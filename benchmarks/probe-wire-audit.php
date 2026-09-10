<?php

declare(strict_types=1);

/*
 * 出站线上字节审计探针：用 demo 真实词表(MapCodec)编一组代表性广播帧，逐帧逐字段拆包，
 * 输出每字段字节占比与「可压缩上限」估算（type→1B code、keyCode→1B、POS 保持、id 维持字符串）。
 * 只读分析，不改任何编码。
 */

require __DIR__ . '/../vendor/autoload.php';

use Nythros\Demo\Protocol\MapCodec;
use Nythros\Protocol\Message;

// ── 代表性出站帧（按 MapServer 真实 payload 形状） ──
$frames = [
    'entity_moved' => ['id' => 'p-12', 'position' => ['x' => 37, 'y' => -88]],
    'entity_enter' => ['id' => 'm-34', 'position' => ['x' => 512, 'y' => 1024], 'typeId' => 'wolf'],
    'entity_leave' => ['id' => 'p-7'],
    'combat:hit' => ['id' => 'm-34', 'damage' => 128, 'hp' => 4444],
    'player:stats' => ['id' => 'p-12', 'hp' => 4444],
    'drop:spawned' => ['id' => 'd-99', 'position' => ['x' => 3, 'y' => 4], 'itemId' => 'sword-iron-1'],
    'entity_dead' => ['id' => 'm-34'],
];

/** 通用帧体拆解器：与 BinaryBatchSerializer 帧体布局同构（只读，语义对齐 u16 keyCode + u8 valueType）。 */
function inspectBody(string $body): array
{
    $fields = [];
    $p = 0;
    $fc = unpack('n', substr($body, $p, 2))[1];
    $p += 2;
    for ($i = 0; $i < $fc; $i++) {
        $start = $p;
        $kc = unpack('n', substr($body, $p, 2))[1];
        $vt = ord($body[$p + 2]);
        $p += 3;
        $label = match (true) {
            $kc === 0xF3 => 'type',
            $kc === 0xF2 => 'requestId',
            $kc === 0xF1 => 'timestamp',
            default => 'keyCode:' . $kc,
        };
        $payloadDesc = '';
        switch ($vt) {
            case 0x00: $payloadDesc = 'NULL';
                break;
            case 0x01: $payloadDesc = sprintf('INT q=%d', unpack('q', substr($body, $p, 8))[1]);
                $p += 8;
                break;
            case 0x02: $p += 8;
                break;
            case 0x03: { $len = ord($body[$p]);
                $p += 1;
                $payloadDesc = 'STR "' . substr($body, $p, $len) . '"';
                $p += $len;
                break; }
            case 0x04: { $len = unpack('N', substr($body, $p, 4))[1];
                $p += 4 + $len;
                break; }
            case 0x06: { $x = unpack('n', substr($body, $p, 2))[1];
                $y = unpack('n', substr($body, $p + 2, 2))[1];
                $payloadDesc = "POS($x,$y)";
                $p += 4;
                break; }
            case 0x07: $payloadDesc = '""';
                break;
            case 0xF0: $payloadDesc = 'true';
                break;
            case 0xF1: $payloadDesc = 'false';
                break;
            case 0x05: { // LIST：逐元素跳
                $n = unpack('N', substr($body, $p, 4))[1];
                $p += 4;
                for ($k = 0; $k < $n; $k++) {
                    $et = ord($body[$p]);
                    $p += 1;
                    $p += match ($et) {
                        0x01 => 8, 0x02 => 8, 0x06 => 4, 0x00,0xF0,0xF1,0x07 => 0, 0x03 => 1 + ord($body[$p]), default => 0
                    };
                    if ($et === 0x03) {
                        $p += ord($body[$p - 1]);
                    }
                }
                $payloadDesc = "LIST×$n";
                break;
            }
        }
        $fields[] = ['label' => $label, 'bytes' => $p - $start, 'vt' => $vt, 'desc' => $payloadDesc];
    }

    return $fields;
}

$ser = MapCodec::create();
$totals = [];
echo str_repeat('=', 100) . "\n";
foreach ($frames as $type => $payload) {
    $batch = $ser->encodeBatch([Message::create($type, $payload)]);
    $body = substr($batch, 12);
    $fields = inspectBody($body);
    $sum = 12 + 2 + 2 + 2; // magic+count+len 摊到帧：单帧批量下全归此帧
    printf("%-14s 线上 %2dB（批量头 12B + 帧长 4B + fieldCount 2B）\n", $type, strlen($batch));
    foreach ($fields as $f) {
        printf("    %-12s %2dB  %s\n", $f['label'], $f['bytes'], $f['desc']);
    }
    $totals[$type] = strlen($batch);
    echo "\n";
}

// ── 汇总：加权估算（热区 60 人典型帧组成：moved 80%、hit/enter/leave/stats 共 20%） ──
echo str_repeat('=', 100) . "\n按热区频率加权（moved×0.8 / 其余×0.2）：\n";
$w = ['entity_moved' => 0.8, 'entity_enter' => 0.05, 'entity_leave' => 0.05, 'combat:hit' => 0.05, 'player:stats' => 0.05];
$cur = 0.0;
foreach ($w as $t => $p) {
    $cur += $p * $totals[$t];
}
printf("当前平均帧大小: %.1f B\n", $cur);

// ── 可压缩上限估算（逐项给依据） ──
printf("\n可压缩空间（逐项）：\n");
printf("  [P0] type 明文→1B code：entity_moved 省 15B/帧（12B字符串+3B头→1B），加权省 ~%.1f B（-40%%+）——需协议版本协商（ADR-027 已有框架）\n", 0.8 * 15 + 0.2 * 13);
printf("  [P1] keyCode 2B→1B（词表 36+<128）：每字段省 1B，moved 帧省 3B，加权 ~2.6B（-7%%）\n");
printf("  [P2] id 短编码：\"p-12\"→实体注册表 numeric id 1-2B，省 3-5B/帧（demo 层数据设计）\n");
printf("  [P3] POS 已 int16×2=4B，varint 最多再省 2B/帧——优先级最低\n");
