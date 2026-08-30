<?php

declare(strict_types=1);

namespace Nythros\Framework\Combat;

/**
 * 掉落表（正式化版）：每条目独立 roll 是否掉落，命中后数量在 [minCount, maxCount] 区间内独立 roll。
 * 与旧版「全表单次加权抽取」不同：多条目可同时命中（掉落风暴语义），不掉落段按条目独立判定。
 * Drop table (formalized): every entry independently rolls whether it drops, and a hit rolls its count
 * independently inside [minCount, maxCount]. Unlike the legacy "one weighted pick per table", multiple entries may
 * hit simultaneously (drop-storm semantics) and the no-drop segment is judged per entry.
 *
 * 兼容口径：构造仍接受旧版 `itemId => 权重` 映射（内部归一化为 count=1 的 DropEntry），存量装配点零改动；
 * 新代码一律用 DropEntry 显式声明数量区间。
 * Compatibility: the constructor still accepts the legacy `itemId => weight` map (normalized internally into
 * count=1 DropEntry instances), so existing assembly sites need no change; new code declares count ranges
 * explicitly with DropEntry.
 */
final class DropTable
{
    /** @var list<DropEntry> 归一化后的条目列表 Normalized entry list. */
    private readonly array $normalizedEntries;

    /**
     * @param list<DropEntry>|array<string, int> $entries 正式条目列表或旧版 itemId => 掉落权重映射 Formal entries or the legacy itemId => drop-weight map.
     * @param int $noDropWeight 每条目的不掉落权重段（随机数落入该段则该条目不掉落） Per-entry no-drop weight segment (a roll landing in it skips that entry).
     */
    public function __construct(
        array $entries,
        private readonly int $noDropWeight = 0,
    ) {
        $normalized = [];
        foreach ($entries as $key => $entry) {
            if ($entry instanceof DropEntry) {
                $normalized[] = $entry;

                continue;
            }
            // 旧版映射形态：itemId => weight（键为 itemId，值为权重）
            // Legacy map shape: itemId => weight (the key is the itemId, the value its weight)
            $normalized[] = new DropEntry((string) $key, (int) $entry);
        }
        $this->normalizedEntries = $normalized;
    }

    /**
     * 数据表构造（P11 掉落表外置）：从行声明数组构建——每行 {itemId, weight, minCount?, maxCount?}，
     * 字段合法性由 schema 校验层负责，这里只做强类型收敛（数量区间非法仍由 DropEntry fail-fast）。
     * The data-table constructor (the P11 drop-table externalization): builds from a row-declaration array — each
     * row {itemId, weight, minCount?, maxCount?}; field legality is the schema layer's job, so this only firms up
     * types (an illegal count range still fails fast inside DropEntry).
     *
     * @param list<array<string, mixed>> $rows 掉落条目行声明 The drop-entry row declarations.
     * @param int $noDropWeight 每条目的不掉落权重段 Per-entry no-drop weight segment.
     */
    public static function fromRows(array $rows, int $noDropWeight = 0): self
    {
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = new DropEntry(
                (string) ($row['itemId'] ?? ''),
                (int) ($row['weight'] ?? 0),
                (int) ($row['minCount'] ?? 1),
                (int) ($row['maxCount'] ?? ($row['minCount'] ?? 1)),
            );
        }

        return new self($entries, $noDropWeight);
    }

    /**
     * 多条目独立 roll：逐条目在 [1, weight + noDropWeight] 上掷点，落入前 noDropWeight 段（不掉落段）则跳过，
     * 命中后数量再独立 roll 于 [minCount, maxCount]；空表恒返回空数组。
     * Independent multi-entry roll: each entry throws over [1, weight + noDropWeight]; landing inside the leading
     * noDropWeight segment skips that entry, and a hit rolls its count independently over [minCount, maxCount];
     * an empty table always returns an empty array.
     *
     * @param RandomSourceInterface $random 随机源 Random source.
     * @return list<array{itemId: string, count: int}> 掉落结果（可能为空/多条） Drop results (possibly empty or multiple).
     */
    public function roll(RandomSourceInterface $random): array
    {
        $drops = [];
        foreach ($this->normalizedEntries as $entry) {
            $denominator = $entry->weight + $this->noDropWeight;
            if ($entry->weight <= 0 || $denominator <= 0) {
                continue; // 零权重条目永不命中 Zero-weight entries never hit.
            }

            if ($random->randomInt(1, $denominator) <= $this->noDropWeight) {
                continue; // 落入不掉落段 The roll landed in the no-drop segment.
            }

            $drops[] = [
                'itemId' => $entry->itemId,
                'count' => $entry->maxCount > $entry->minCount
                    ? $random->randomInt($entry->minCount, $entry->maxCount)
                    : $entry->minCount,
            ];
        }

        return $drops;
    }
}
