<?php

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use Nythros\Demo\Protocol\MapCodec;
use Nythros\Protocol\Message;

$ser = MapCodec::create();
$frames = [];
for ($i = 0; $i < 60; $i++) {
    $frames[] = Message::create('entity_moved', ['id' => 'p-' . $i, 'position' => ['x' => 100 + $i, 'y' => 200 - $i]]);
}
$batch = $ser->encodeBatch($frames);
printf("60 帧批量: %dB 总, 每帧 %.2fB\n", strlen($batch), strlen($batch) / 60);
$msg = Message::create('entity_moved', ['id' => 'p-1', 'position' => ['x' => 37, 'y' => -88]]);
printf("单帧体(不含批量头与帧长前缀): %dB = type(16) + id(8) + pos(7) + fieldCount(2)\n", strlen($ser->encodeBatch([$msg])) - 12 - 4);
// 极端对照:若 type 走 1B code、id 走 numeric 2B、pos 不变 → 理论帧体
printf("理想帧体(type=1B code+id=2B): 1+1+2+7+2 ≈ 13B/帧（当前 33B，压缩率 -61%%）\n");
