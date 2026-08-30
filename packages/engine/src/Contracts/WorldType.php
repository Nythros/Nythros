<?php

declare(strict_types=1);

namespace Nythros\Contracts;

/**
 * 世界类型枚举：区分「AOI 局域广播」与「全量广播」两类 World 的同步语义。
 * - AOI：拥有空间索引（GridAOI），实体间按九宫格视野建立兴趣关系，广播只发给视野内连接（主城/野外大地图）。
 * - FULL_BROADCAST：无空间索引，注入 UniversalAOI（全量视野）——世界内所有实体对所有连接可见，广播 = 空间内全量
 *   （副本/竞技场等小人数高隔离空间）。
 * 引擎层提供两种构造能力（AOI 恒非空：全量型 World 注入 UniversalAOI，消费方无需判空），
 * 具体编排（哪类频道用哪型）由业务组装层决定。
 *
 * World-type enum: distinguishes the two World synchronization semantics — AOI (area-of-interest) vs full broadcast.
 * - AOI: owns a spatial index (GridAOI); entities build interest relationships through 3x3 vision, and broadcasts only
 *   reach connections inside the view (towns / open-world maps).
 * - FULL_BROADCAST: no spatial index — it injects a UniversalAOI (full view); every entity in the world is visible to
 *   every connection, so broadcasts are world-wide (dungeons / arenas: small headcount, high isolation).
 * The engine offers both constructions (the AOI is never null: full-broadcast Worlds inject a UniversalAOI, so
 * consumers never null-check); the assembly layer decides which type each channel uses.
 */
enum WorldType
{
    /** AOI 局域广播（空间索引 + 九宫格视野）。 AOI-local broadcast (spatial index + 3x3 vision). */
    case AOI;

    /** 全量广播（无空间索引，世界内全量可见）。 Full broadcast (no spatial index; everything in the world is visible). */
    case FULL_BROADCAST;
}
