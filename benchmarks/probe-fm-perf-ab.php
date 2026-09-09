<?php

declare(strict_types=1);

/*
 * 出站合并器与探针热路径的 A/B 实验脚本（一次性，验证后转正或删除）。
 * 候选：
 *   FM1 FrameMerger::drain —— 无软过滤时 chosen 直接 COW 引用 $frameSlots（免逐槽拷贝）；
 *       $encode 闭包每连接构建一次 → 提升为私有方法；array_map+array_values 双中间数组 →
 *       foreach 直建 Message 列表（map 保序，list 上 array_values 本为冗余拷贝）。
 *   PP1 PerfProbe::bucketOf —— 现 foreach 全扫 9 边界（无提前退出），改比较链提前返回。
 * 资格：对拍必须逐字节/逐值全等（含异常路径输入），吞吐才有意义。
 */

require __DIR__ . '/../vendor/autoload.php';

use Nythros\Kernel\PerfProbe;
use Nythros\Network\ConnectionInterface;
use Nythros\Protocol\BinaryBatchSerializer;
use Nythros\Protocol\Message;
use Nythros\Protocol\ProtocolVocabulary;

// ── 测试连接替身（FrameMerger 只用 getId） ──
final class ProbeConn implements ConnectionInterface
{
    public function __construct(private readonly string $id)
    {
    }
    public function getId(): string
    {
        return $this->id;
    }
    public function getRemoteAddress(): string
    {
        return '127.0.0.1:0';
    }
    public function send(string $payload): void
    {
    }
    public function sendBatch(array $payloads): void
    {
    }
    public function getSendBufferQueueSize(): int
    {
        return 0;
    }
    public function close(): void
    {
    }
    public function isClosed(): bool
    {
        return false;
    }
    public function getLastMessageTime(): float
    {
        return 0.0;
    }
    public function markAuthenticated(): void
    {
    }
    public function isAuthenticated(): bool
    {
        return true;
    }
    public function markInternal(): void
    {
    }
    public function isInternal(): bool
    {
        return false;
    }
    public function onBufferFull(callable $handler): void
    {
    }
    public function onBufferDrain(callable $handler): void
    {
    }
}

// ── FM 变体类：与 HEAD FrameMerger 逐行同构，drain 按 FM1 重写 ──
final class FrameMergerV2
{
    public const KIND_STATE = 'state';
    public const KIND_EVENT = 'event';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_LOW = 'low';
    private const DEFAULT_POLICY = ['kind' => self::KIND_EVENT, 'priority' => self::PRIORITY_HIGH];
    private const FRAME_POLICY = [
        'entity_moved' => ['kind' => self::KIND_STATE, 'priority' => self::PRIORITY_LOW],
        'player:stats' => ['kind' => self::KIND_STATE, 'priority' => self::PRIORITY_LOW],
    ];

    /** @var array<string, list<array{type: string, payload: array<string|int, mixed>, priority: string}>> */
    private array $slots = [];
    /** @var array<string, array<string, int>> */
    private array $stateSlots = [];

    public function __construct(private readonly \Nythros\Protocol\BatchSerializerInterface $serializer)
    {
    }

    public function enqueue(ConnectionInterface $conn, string $type, array $payload, ?string $dedupKey = null): void
    {
        $connId = $conn->getId();
        $policy = self::FRAME_POLICY[$type] ?? self::DEFAULT_POLICY;

        if ($policy['kind'] === self::KIND_STATE) {
            $key = $dedupKey ?? ($payload['id'] ?? null);
            if (is_string($key) && $key !== '') {
                $slotIndex = $this->stateSlots[$connId][$key] ?? null;
                if ($slotIndex !== null) {
                    $this->slots[$connId][$slotIndex]['payload'] = $payload;

                    return;
                }
                $this->stateSlots[$connId][$key] = count($this->slots[$connId] ?? []);
            }
        }

        $this->slots[$connId][] = [
            'type' => $type,
            'payload' => $payload,
            'priority' => $policy['priority'],
        ];
    }

    public function drain(int $maxBytesPerConnection, array $softFilterConnIds = []): array
    {
        $result = [];
        foreach ($this->slots as $connId => $frameSlots) {
            // FM1a：无软过滤 → COW 引用共享（PHP 数组写时复制，读遍历零拷贝）；有软过滤才逐槽建列表
            // FM1a: no soft filter → share by COW (PHP copy-on-write, no per-slot copy); only filter builds a list
            if (isset($softFilterConnIds[$connId])) {
                $chosen = [];
                foreach ($frameSlots as $slot) {
                    if ($slot['priority'] !== self::PRIORITY_LOW) {
                        $chosen[] = $slot;
                    }
                }
                if ($chosen === []) {
                    continue;
                }
            } else {
                $chosen = $frameSlots;
            }

            // FM1b：闭包提升为方法；FM1c：foreach 直建列表，免 map 中间数组 + 冗余 array_values
            // FM1b: closure hoisted to method; FM1c: foreach builds the list directly, no temp array_map + redundant array_values
            $blob = $this->encodeSlots($chosen);
            if (strlen($blob) > $maxBytesPerConnection) {
                // 超配额：剔除低优先级帧后重编码（与原版同语义：list 直建，天然无空洞）
                $kept = [];
                foreach ($chosen as $slot) {
                    if ($slot['priority'] !== self::PRIORITY_LOW) {
                        $kept[] = $slot;
                    }
                }
                if ($kept === []) {
                    continue;
                }
                $blob = $this->encodeSlots($kept);
            }

            $result[$connId] = [$blob];
        }

        $this->slots = [];
        $this->stateSlots = [];

        return $result;
    }

    /** @param list<array{type: string, payload: array<string|int, mixed>, priority: string}> $slots */
    private function encodeSlots(array $slots): string
    {
        $messages = [];
        foreach ($slots as $slot) {
            $messages[] = Message::create($slot['type'], $slot['payload']);
        }

        return $this->serializer->encodeBatch($messages);
    }
}

// ── 对拍：覆盖 无过滤 / 软过滤(全LOW连接被整条跳过) / dedup替换 / 超配额重编码 / 超配额且全LOW被跳过 / 多连接 ──
$vocab = new ProtocolVocabulary(
    typeCodes: ['entity_moved' => 1, 'player:stats' => 2, 'combat:hit' => 3],
    keyCodes: ['id' => 1, 'position' => 2, 'x' => 3, 'y' => 4, 'hp' => 5],
);
$ser = new BinaryBatchSerializer($vocab);

/** 构造一个 merger（ref = 真类，v2 = 变体），灌同一工作负载，返回 drain 结果。 */
function workload(object $merger, int $framesPerConn, int $conns, int $quota, array $softFilter): array
{
    $cs = [];
    for ($c = 0; $c < $conns; $c++) {
        $conn = new ProbeConn('c' . $c);
        $cs[] = $conn;
        for ($f = 0; $f < $framesPerConn; $f++) {
            // 混合：STATE(去重) + EVENT(追加)；不同实体
            $merger->enqueue($conn, 'entity_moved', ['id' => 'e' . ($f % 7), 'position' => ['x' => $f, 'y' => $c]]);
            $merger->enqueue($conn, 'combat:hit', ['id' => 'e' . $f, 'hp' => 100 - $f]);
        }
    }

    return $merger->drain($quota, $softFilter);
}

// 场景矩阵：软过滤命中全LOW实体连接、大配额、小配额(触发重编码)、更小(全LOW被跳)
$scenarios = [
    ['frames' => 20, 'conns' => 3, 'quota' => 1_000_000, 'filter' => []],
    ['frames' => 20, 'conns' => 3, 'quota' => 1_000_000, 'filter' => ['c0' => true]],
    ['frames' => 20, 'conns' => 2, 'quota' => 260, 'filter' => []],   // 超配额：混合(有HIGH)→重编码
    ['frames' => 8, 'conns' => 1, 'quota' => 30, 'filter' => []],     // 超配额且含EVENT…确保 kept==[] 与保留两条都覆盖
];
$parityOk = true;
foreach ($scenarios as $si => $sc) {
    $a = workload(new \Nythros\Framework\Server\FrameMerger($GLOBALS["ser"]), $sc['frames'], $sc['conns'], $sc['quota'], $sc['filter']);
    $b = workload(new FrameMergerV2($GLOBALS["ser"]), $sc['frames'], $sc['conns'], $sc['quota'], $sc['filter']);
    if ($a !== $b) {
        $parityOk = false;
        echo "  scenario $si DRAINED DIFF\n";
        var_dump(array_keys($a), array_keys($b));
    }
}
echo 'FrameMerger drain parity (4 scenarios, byte-strict): ' . ($parityOk ? 'OK' : 'FAIL') . "\n";
if (!$parityOk) {
    exit(1);
}

// ── 吞吐 ──
function bench(callable $f, int $iters): float
{
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $f();
    }

    return (hrtime(true) - $t0) / 1e6 / $iters;
}

// 典型 30Hz 房间：单连接 8 实体帧一次 drain（含 enqueue 重建，两变体同负载）
$rA = $rB = [];
for ($round = 0; $round < 5; $round++) {
    $rA[] = bench(static function () use ($vocab): void {
        $mm = new \Nythros\Framework\Server\FrameMerger($GLOBALS["ser"]);
        $c = new ProbeConn('x');
        for ($k = 0; $k < 8; $k++) {
            $mm->enqueue($c, 'entity_moved', ['id' => 'e' . $k, 'position' => ['x' => $k, 'y' => 0]]);
        }
        $mm->drain(1_000_000);
    }, 20000);

    $rB[] = bench(static function () use ($vocab): void {
        $mm = new FrameMergerV2($GLOBALS["ser"]);
        $c = new ProbeConn('x');
        for ($k = 0; $k < 8; $k++) {
            $mm->enqueue($c, 'entity_moved', ['id' => 'e' . $k, 'position' => ['x' => $k, 'y' => 0]]);
        }
        $mm->drain(1_000_000);
    }, 20000);
}
$med = static function (array $a): float {
    sort($a);

    return $a[intdiv(count($a), 2)];
};
printf("drain(8槽) ref %.5f ms/op  v2 %.5f ms/op  gain %.1f%%\n", $med($rA), $med($rB), ($med($rA) / $med($rB) - 1) * 100);

// ── PP1：bucketOf 穷举值域对拍 + 吞吐 ──
$bucket = new ReflectionMethod(PerfProbe::class, 'bucketOf');
$bucket->setAccessible(true);

// 变体：降序 >= 比较链提前返回——与原版「取最大满足边界」逐值全等（NaN/负值/±0/INF 全域：
// 原版对 NaN 与负值落 0；降序链让它们自然走到底部 return 0）
$bucketNew = static function (float $ms): int {
    if ($ms >= 64.0) {
        return 8;
    }
    if ($ms >= 32.0) {
        return 7;
    }
    if ($ms >= 16.0) {
        return 6;
    }
    if ($ms >= 8.0) {
        return 5;
    }
    if ($ms >= 4.0) {
        return 4;
    }
    if ($ms >= 2.0) {
        return 3;
    }
    if ($ms >= 1.0) {
        return 2;
    }
    if ($ms >= 0.5) {
        return 1;
    }

    return 0;
};
// 注意：原实现对 ≥64 与负值、NaN、边界 0.5/1.0/2.0... 的处理先穷举核对
$ppOk = true;
foreach ([NAN, -1.0, -0.0, 0.0, 0.0001, 0.499, 0.5, 0.50001, 1.0, 1.5, 2.0, 3.9, 4.0, 8.0, 15.9, 16.0, 32.0, 63.9, 64.0, 64.001, 1000.0, INF] as $v) {
    $o = $bucket->invoke(null, $v);
    $n = $bucketNew($v);
    if ($o !== $n) {
        $ppOk = false;
        printf("  bucketOf(%s) ref=%d new=%d\n", var_export($v, true), $o, $n);
    }
}
echo 'bucketOf parity (incl NaN/neg/boundary/INF): ' . ($ppOk ? 'OK' : 'FAIL') . "\n";

$rPA = $rPB = [];
for ($round = 0; $round < 5; $round++) {
    $rPA[] = bench(static fn () => $bucket->invoke(null, 0.264), 100000);
    $rPB[] = bench(static fn () => $bucketNew(0.264), 100000);
}
printf("bucketOf(0.264) ref %.6f ms/op  new %.6f ms/op  gain %.1f%%\n", $med($rPA), $med($rPB), ($med($rPA) / $med($rPB) - 1) * 100);
