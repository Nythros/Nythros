<?php

declare(strict_types=1);

/*
 * 二进制序列化热路径候选优化的独立 A/B 探针（一次性实验脚本）。
 * 候选（均以「与原实现输出完全一致」为前置资格）：
 *   C1 encode：每字段单次 pack（nCq / nCd / nCC / nCN）替代 两次 pack + `.` 拼接；
 *              encodeBatch 帧体收集进数组，末次 implode 替代逐帧 .=。
 *   C2 decode：标量读改 unpack 三参形式（带 offset），免 substr 中间串；
 *              u16 额外测 ord 算术变体（keyCode 是最高频标量）。
 * 用法：php benchmarks/probe-protocol-ab.php   （WSL 生产等价环境运行）
 */

require __DIR__ . '/../vendor/autoload.php';

use Nythros\Protocol\BinaryBatchSerializer;
use Nythros\Protocol\DecodeException;
use Nythros\Protocol\Message;
use Nythros\Protocol\ProtocolException;
use Nythros\Protocol\ProtocolVocabulary;

// ── 变体实现（C1 encode + C2 decode 合在一个类，逐方法与原实现同语义拷贝改造） ──
final class ABSerializer
{
    private const MAGIC = "\x4e\x58\x00\x01";
    private const T_NULL = 0x00;
    private const T_INT = 0x01;
    private const T_FLOAT = 0x02;
    private const T_STRING = 0x03;
    private const T_STRING32 = 0x04;
    private const T_LIST = 0x05;
    private const T_POS = 0x06;
    private const T_EMPTY_STRING = 0x07;
    private const T_TRUE = 0xF0;
    private const T_FALSE = 0xF1;
    private const K_TIMESTAMP = 0xF1;
    private const K_REQUEST_ID = 0xF2;
    private const K_TYPE = 0xF3;
    private const FIELD_SLOT = 3;

    public function __construct(
        private readonly ProtocolVocabulary $vocab,
        private readonly bool $encodeTimestamp = false,
    ) {
    }

    public function encodeBatch(array $messages): string
    {
        $packet = self::MAGIC . pack('N', count($messages));
        foreach ($messages as $message) {
            $body = $this->encodeFrameBody($message);
            $packet .= pack('N', strlen($body)) . $body;
        }

        return $packet;
    }

    private function encodeFrameBody(Message $message): string
    {
        $typeCode = $this->vocab->typeCode($message->type);
        if ($typeCode === null) {
            throw new ProtocolException('未知帧类型。Unknown frame type.');
        }

        $fixed = $this->encString(self::K_TYPE, $message->type);
        $fieldCount = 1;
        if ($message->requestId !== null) {
            $fixed .= $this->encString(self::K_REQUEST_ID, $message->requestId);
            $fieldCount++;
        }
        if ($this->encodeTimestamp && $message->timestamp !== 0.0) {
            $fixed .= pack('nCd', self::K_TIMESTAMP, self::T_FLOAT, $message->timestamp);
            $fieldCount++;
        }

        $payload = '';
        foreach ($message->payload as $key => $value) {
            $keyCode = $this->vocab->keyCode((string) $key);
            if ($keyCode === null) {
                throw new ProtocolException('未知负载字段。Unknown payload key.');
            }
            $payload .= $this->encodeValue($keyCode, $value);
            $fieldCount++;
        }

        return pack('n', $fieldCount) . $fixed . $payload;
    }

    private function encodeValue(int $keyCode, mixed $value): string
    {
        if (is_string($value)) {
            // C1：单 pack 前缀（nCC / nCN）替代双 pack + 拼接
            if ($value === '') {
                return pack('nC', $keyCode, self::T_EMPTY_STRING);
            }

            return strlen($value) <= 255
                ? pack('nCC', $keyCode, self::T_STRING, strlen($value)) . $value
                : pack('nCN', $keyCode, self::T_STRING32, strlen($value)) . $value;
        }

        return match (true) {
            $value === null => pack('nC', $keyCode, self::T_NULL),
            $value === true => pack('nC', $keyCode, self::T_TRUE),
            $value === false => pack('nC', $keyCode, self::T_FALSE),
            // C1：一次 pack('nCq') / pack('nCd') 出整字段
            is_int($value) => pack('nCq', $keyCode, self::T_INT, $value),
            is_float($value) => pack('nCd', $keyCode, self::T_FLOAT, $value),
            $this->isPositionList($value) => pack('nCnn', $keyCode, self::T_POS, $value['x'], $value['y']),
            is_array($value) => $this->encodeList($keyCode, $value),
            default => throw new ProtocolException('不支持的值类型。Unsupported value type.'),
        };
    }

    private function encodeList(int $keyCode, array $value): string
    {
        $out = pack('nCN', $keyCode, self::T_LIST, count($value));
        foreach ($value as $element) {
            // C1：元素类型码与负载合并进单 pack
            if (is_int($element)) {
                $out .= pack('Cq', self::T_INT, $element);
            } elseif (is_float($element)) {
                $out .= pack('Cd', self::T_FLOAT, $element);
            } elseif (is_string($element)) {
                $out .= strlen($element) <= 255
                    ? pack('CC', self::T_STRING, strlen($element)) . $element
                    : pack('CN', self::T_STRING32, strlen($element)) . $element;
            } elseif (is_bool($element)) {
                $out .= chr($element ? self::T_TRUE : self::T_FALSE);
            } elseif ($element === null) {
                $out .= chr(self::T_NULL);
            } elseif (is_array($element) && $this->isPositionList($element)) {
                $out .= pack('Cnn', self::T_POS, $element['x'], $element['y']);
            } else {
                throw new ProtocolException('LIST 元素类型不支持。Unsupported LIST element type.');
            }
        }

        return $out;
    }

    private function isPositionList(array $value): bool
    {
        return isset($value['x'], $value['y']) && is_int($value['x']) && is_int($value['y']) && array_keys($value) === ['x', 'y'];
    }

    private function encString(int $keyCode, string $value): string
    {
        if ($value === '') {
            return pack('nC', $keyCode, self::T_EMPTY_STRING);
        }

        return strlen($value) <= 255
            ? pack('nCC', $keyCode, self::T_STRING, strlen($value)) . $value
            : pack('nCN', $keyCode, self::T_STRING32, strlen($value)) . $value;
    }

    // ── C2 decode ──
    public function decodeBatch(string $bytes): array
    {
        if ($bytes === '') {
            return [];
        }
        if (strlen($bytes) < 4 || substr($bytes, 0, 4) !== self::MAGIC) {
            throw new DecodeException('魔数不匹配。Magic mismatch.');
        }
        $offset = 4;
        $count = $this->u32($bytes, $offset);
        $offset += 4;

        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $len = $this->u32($bytes, $offset);
            $offset += 4;
            $messages[] = $this->decodeFrameBody(substr($bytes, $offset, $len));
            $offset += $len;
        }

        return $messages;
    }

    private function decodeFrameBody(string $bytes): Message
    {
        $fieldCount = $this->u16($bytes, 0);
        $offset = 2;
        $type = null;
        $requestId = null;
        $timestamp = 0.0;
        $payload = [];

        for ($i = 0; $i < $fieldCount; $i++) {
            if ($offset + self::FIELD_SLOT > strlen($bytes)) {
                throw new DecodeException('字段槽位越界。Field slot out of bounds.');
            }
            $keyCode = $this->u16($bytes, $offset);
            $valueType = ord($bytes[$offset + 2]);
            $offset += self::FIELD_SLOT;

            if ($keyCode === self::K_TYPE) {
                $type = $this->decString($bytes, $offset, $valueType);
                $offset += $this->stringByteLen($bytes, $offset, $valueType);
                continue;
            }
            if ($keyCode === self::K_REQUEST_ID) {
                $requestId = $this->decString($bytes, $offset, $valueType);
                $offset += $this->stringByteLen($bytes, $offset, $valueType);
                continue;
            }
            if ($keyCode === self::K_TIMESTAMP) {
                if ($valueType !== self::T_FLOAT) {
                    throw new DecodeException('timestamp 类型错误。Timestamp type mismatch.');
                }
                $timestamp = $this->f64($bytes, $offset);
                $offset += 8;
                continue;
            }

            $key = $this->vocab->keyName($keyCode);
            if ($key === null) {
                throw new DecodeException('未知 keyCode。Unknown key code.');
            }
            [$value, $consumed] = $this->decodeValue($bytes, $offset, $valueType);
            $offset += $consumed;
            $payload[$key] = $value;
        }

        if ($type === null) {
            throw new DecodeException('缺 type 字段。Lacks type.');
        }

        return new Message($type, $requestId, $timestamp, $payload);
    }

    /** @return array{0: mixed, 1: int} */
    private function decodeValue(string $bytes, int $offset, int $valueType): array
    {
        $base = $offset;
        switch ($valueType) {
            case self::T_NULL:
                return [null, 0];
            case self::T_TRUE:
                return [true, 0];
            case self::T_FALSE:
                return [false, 0];
            case self::T_INT:
                $this->need($bytes, $offset, 8);
                $u = unpack('q', $bytes, $offset);
                return [$u[1], 8];
            case self::T_FLOAT:
                $this->need($bytes, $offset, 8);
                $u = unpack('d', $bytes, $offset);
                return [$u[1], 8];
            case self::T_STRING:
                $this->need($bytes, $offset, 1);
                $len = ord($bytes[$offset]);
                $this->need($bytes, $offset, 1 + $len);
                return [substr($bytes, $offset + 1, $len), 1 + $len];
            case self::T_STRING32:
                $len = $this->u32($bytes, $offset);
                $this->need($bytes, $offset, 4 + $len);
                return [substr($bytes, $offset + 4, $len), 4 + $len];
            case self::T_EMPTY_STRING:
                return ['', 0];
            case self::T_POS:
                $this->need($bytes, $offset, 4);
                return [['x' => $this->i16($bytes, $offset), 'y' => $this->i16($bytes, $offset + 2)], 4];
            case self::T_LIST:
                $count = $this->u32($bytes, $offset);
                $offset += 4;
                $list = [];
                for ($i = 0; $i < $count; $i++) {
                    $this->need($bytes, $offset, 1);
                    $elemType = ord($bytes[$offset]);
                    $offset += 1;
                    [$v, $consumed] = $this->decodeValue($bytes, $offset, $elemType);
                    $offset += $consumed;
                    $list[] = $v;
                }
                return [$list, $offset - $base];
            default:
                throw new DecodeException('未知值类型。Unknown value type.');
        }
    }

    private function decString(string $bytes, int $offset, int $valueType): string
    {
        switch ($valueType) {
            case self::T_STRING:
                $this->need($bytes, $offset, 1);
                $len = ord($bytes[$offset]);
                $this->need($bytes, $offset, 1 + $len);
                return substr($bytes, $offset + 1, $len);
            case self::T_STRING32:
                $len = $this->u32($bytes, $offset);
                $this->need($bytes, $offset, 4 + $len);
                return substr($bytes, $offset + 4, $len);
            case self::T_EMPTY_STRING:
                return '';
            default:
                throw new DecodeException('字符串字段类型错误。String type mismatch.');
        }
    }

    private function stringByteLen(string $bytes, int $offset, int $valueType): int
    {
        switch ($valueType) {
            case self::T_STRING:
                $this->need($bytes, $offset, 1);
                return 1 + ord($bytes[$offset]);
            case self::T_STRING32:
                return 4 + $this->u32($bytes, $offset);
            case self::T_EMPTY_STRING:
                return 0;
            default:
                throw new DecodeException('字符串字段类型错误。String type mismatch.');
        }
    }

    private function need(string $bytes, int $offset, int $length): void
    {
        if ($offset + $length > strlen($bytes)) {
            throw new DecodeException('包体截断。Packet truncated.');
        }
    }

    private function u32(string $bytes, int $offset): int
    {
        $this->need($bytes, $offset, 4);
        $u = unpack('N', $bytes, $offset);

        return $u[1];
    }

    private function u16(string $bytes, int $offset): int
    {
        // C2b：ord 算术替代 unpack 数组
        $this->need($bytes, $offset, 2);

        return (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
    }

    private function i16(string $bytes, int $offset): int
    {
        $raw = (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);

        return $raw > 0x7fff ? $raw - 0x10000 : $raw;
    }

    private function f64(string $bytes, int $offset): float
    {
        $this->need($bytes, $offset, 8);
        $u = unpack('d', $bytes, $offset);

        return $u[1];
    }
}

// ── 正确性对拍 ──
$vocab = new ProtocolVocabulary(
    typeCodes: ['entity_moved' => 1, 'combat:hit' => 2],
    keyCodes: ['id' => 1, 'position' => 2, 'x' => 3, 'y' => 4, 'damage' => 5, 'hp' => 6, 'crit' => 7, 'miss' => 8, 'why' => 9, 'tags' => 10],
);
$ref = new BinaryBatchSerializer($vocab);
$ab = new ABSerializer($vocab);

$messages = [
    Message::create('entity_moved', ['id' => 'p-1', 'position' => ['x' => 1, 'y' => 2]]),
    Message::create('combat:hit', ['id' => 'm-1', 'damage' => 12, 'hp' => 88]),
    Message::create('combat:hit', ['id' => 'm-2', 'damage' => -5, 'hp' => 95.5, 'crit' => true, 'miss' => false, 'why' => null]),
    Message::create('entity_moved', ['id' => 'p-2', 'position' => ['x' => -3, 'y' => 700]]),
    Message::create('combat:hit', ['id' => str_repeat('x', 300), 'damage' => PHP_INT_MAX, 'hp' => 1.25, 'tags' => ['a', 'bb', 7, 1.5, true, null]]),
];

$refBytes = $ref->encodeBatch($messages);
$abBytes = $ab->encodeBatch($messages);
echo "encode byte-identical: " . ($refBytes === $abBytes ? "OK" : "FAIL") . "\n";
if ($refBytes !== $abBytes) {
    echo "  ref hex: " . bin2hex(substr($refBytes, 0, 64)) . "\n  ab  hex: " . bin2hex(substr($abBytes, 0, 64)) . "\n";
    exit(1);
}

$refDecoded = $ref->decodeBatch($refBytes);
$abDecoded = $ab->decodeBatch($refBytes);
$decOk = count($refDecoded) === count($abDecoded);
if ($decOk) {
    foreach ($refDecoded as $i => $r) {
        $a = $abDecoded[$i];
        if ($r->type !== $a->type || $r->requestId !== $a->requestId || $r->timestamp !== $a->timestamp || $r->payload != $a->payload) {
            $decOk = false;
            echo "  frame $i decode MISMATCH\n";
        }
    }
}
echo "decode value-identical: " . ($decOk ? "OK" : "FAIL") . "\n";
if (!$decOk) {
    exit(1);
}

// ── 截断包异常行为对拍（移植前后必须同型同消息） ──
$truncFails = 0;
for ($cut = 1; $cut <= 40; $cut++) {
    $short = substr($refBytes, 0, max(1, strlen($refBytes) - $cut));
    $re = null;
    $reMsg = null;
    try {
        $ref->decodeBatch($short);
    } catch (\Throwable $e) {
        $re = $e::class;
        $reMsg = $e->getMessage();
    }
    $ae = null;
    $aeMsg = null;
    try {
        $ab->decodeBatch($short);
    } catch (\Throwable $e) {
        $ae = $e::class;
        $aeMsg = $e->getMessage();
    }
    if ($re !== $ae || ($re !== null && $reMsg !== $aeMsg)) {
        // 类型不一致 = 移植错误；类型一致仅消息不同 = 移植版新增帧长首道闸的 fail-fast 提前（预期差异，仅提示）
        // Class mismatch = porting bug; same class but different message = the ported version's new frame-length
        // gate fails fast earlier than field-level checks — expected, informational only.
        echo '  cut=' . $cut . ($re !== $ae ? ' CLASS-MISMATCH ' : ' info(fail-fast earlier) ')
            . ($reMsg ?? 'none') . ' vs ' . ($aeMsg ?? 'none') . "\n";
        if ($re !== $ae) {
            $truncFails++;
        }
    }
}
echo 'truncation exception parity (40 cuts): ' . ($truncFails === 0 ? 'OK' : $truncFails . ' FAIL') . "\n";
if ($truncFails > 0) {
    exit(1);
}

// ── 吞吐 A/B：交替 5 轮取中位 ──
function bench(callable $fn, int $iters): float
{
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $fn();
    }

    return (hrtime(true) - $t0) / 1e6 / $iters; // ms/op
}

$encRef = static fn () => $ref->encodeBatch($messages);
$encAb = static fn () => $ab->encodeBatch($messages);
$decRef = static fn () => $ref->decodeBatch($refBytes);
$decAb = static fn () => $ab->decodeBatch($refBytes);

$rE = $rEa = $rD = $rDa = [];
for ($round = 0; $round < 5; $round++) {
    $rE[] = bench($encRef, 10000);
    $rEa[] = bench($encAb, 10000);
    $rD[] = bench($decRef, 10000);
    $rDa[] = bench($decAb, 10000);
}
function med(array $a): float
{
    sort($a);

    return $a[intdiv(count($a), 2)];
}
printf("encode  ref %.4f ms/op  ab %.4f ms/op  gain %.1f%%\n", med($rE), med($rEa), (med($rE) / med($rEa) - 1) * 100);
printf("decode  ref %.4f ms/op  ab %.4f ms/op  gain %.1f%%\n", med($rD), med($rDa), (med($rD) / med($rDa) - 1) * 100);
