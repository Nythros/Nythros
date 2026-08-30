<?php

declare(strict_types=1);

namespace Nythros\Contracts\Tests;

use Nythros\Contracts\EventEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * EventEnvelopeTest - 覆盖 EventEnvelope 的字段赋值与类型常量。
 * Tests covering EventEnvelope field assignment and type constants.
 */
final class EventEnvelopeTest extends TestCase
{
    public function testTypeConstants(): void
    {
        self::assertSame('aoi.enter', EventEnvelope::TYPE_AOI_ENTER);
        self::assertSame('aoi.leave', EventEnvelope::TYPE_AOI_LEAVE);
    }

    public function testAllFieldsAreAssigned(): void
    {
        $envelope = new EventEnvelope(
            source: 'player-1',
            type: EventEnvelope::TYPE_AOI_ENTER,
            timestamp: 1720000000.5,
            targetScope: 'map-1',
            reliable: true,
            droppable: false,
            payload: ['id' => 'player-1', 'x' => 1.5],
        );

        self::assertSame('player-1', $envelope->source);
        self::assertSame('aoi.enter', $envelope->type);
        self::assertSame(1720000000.5, $envelope->timestamp);
        self::assertSame('map-1', $envelope->targetScope);
        self::assertTrue($envelope->reliable);
        self::assertFalse($envelope->droppable);
        self::assertSame(['id' => 'player-1', 'x' => 1.5], $envelope->payload);
    }

    public function testNullTargetScopeMeansGlobalBroadcast(): void
    {
        $envelope = new EventEnvelope(
            source: 'system',
            type: EventEnvelope::TYPE_AOI_LEAVE,
            timestamp: 1.0,
            targetScope: null,
            reliable: false,
            droppable: true,
            payload: [],
        );

        self::assertNull($envelope->targetScope);
    }
}
