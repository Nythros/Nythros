<?php

declare(strict_types=1);

namespace Nythros\Security\Tests;

use Nythros\Security\InMemoryTokenStore;
use Nythros\Security\TokenManager;
use Nythros\Security\TokenStatus;
use Nythros\Security\TokenStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * TokenManagerTest - 覆盖 TokenManager 的令牌签发（scopes 白名单过滤）、消费状态判定与只读查询行为。
 * Tests covering TokenManager token issuance (scopes whitelist filtering), consume status determination, and read-only peeking.
 */
final class TokenManagerTest extends TestCase
{
    public function testIssueReturnsDistinct64CharHexTokens(): void
    {
        $manager = new TokenManager(new InMemoryTokenStore());

        $first = $manager->issue('u1', 'map-1');
        $second = $manager->issue('u1', 'map-1');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $second);
        self::assertNotSame($first, $second);
    }

    public function testIssueFiltersScopesToWhitelistDedup(): void
    {
        // 非 map/chat/team 剔除 + 保序去重：['map','admin','chat','map','team','super'] → ['map','chat','team']
        // Non-map/chat/team dropped + order-preserving dedup: ['map','admin','chat','map','team','super'] → ['map','chat','team']
        $manager = new TokenManager(new InMemoryTokenStore());

        $token = $manager->issue('u1', 'map-1', ['map', 'admin', 'chat', 'map', 'team', 'super']);

        $record = $manager->peek($token);
        self::assertNotNull($record);
        self::assertSame(['map', 'chat', 'team'], $record->scopes);
    }

    public function testIssueEmptyScopesDefaultsToFullSet(): void
    {
        // 空集缺省全量：issue(..., []) → ['map','chat','team']（ADR 9.3 缺省全量）
        // Empty set defaults to the full set: issue(..., []) → ['map','chat','team'] (ADR 9.3 default = full set)
        $manager = new TokenManager(new InMemoryTokenStore());

        $token = $manager->issue('u1', 'map-1', []);

        $record = $manager->peek($token);
        self::assertNotNull($record);
        self::assertSame(['map', 'chat', 'team'], $record->scopes);
    }

    public function testIssueScopesAllFilteredDefaultsToFullSet(): void
    {
        // 全部被剔除 → 过滤结果为空 → 缺省全量
        // Everything filtered out → empty result → defaults to the full set
        $manager = new TokenManager(new InMemoryTokenStore());

        $token = $manager->issue('u1', 'map-1', ['admin', 'staff']);

        $record = $manager->peek($token);
        self::assertNotNull($record);
        self::assertSame(['map', 'chat', 'team'], $record->scopes);
    }

    public function testIssueDropsNonStringScopeElements(): void
    {
        // 非字符串元素被严格比较剔除（scopes 可能来自客户端 payload 的 mixed 数据）
        // Non-string elements are dropped by strict comparison (scopes may come from mixed client payload data)
        $manager = new TokenManager(new InMemoryTokenStore());

        $token = $manager->issue('u1', 'map-1', ['map', 123, 'chat']);

        $record = $manager->peek($token);
        self::assertNotNull($record);
        self::assertSame(['map', 'chat'], $record->scopes);
    }

    public function testConsumeIssuedTokenIsValid(): void
    {
        $now = 100.0;
        $clock = static fn (): float => $now;
        $manager = new TokenManager(new InMemoryTokenStore($clock), $clock);

        $token = $manager->issue('u1', 'map-1');

        self::assertSame(TokenStatus::Valid, $manager->consume($token, 'map'));
    }

    public function testConsumeSameTokenTwiceIsReplayed(): void
    {
        $now = 100.0;
        $clock = static fn (): float => $now;
        $manager = new TokenManager(new InMemoryTokenStore($clock), $clock);

        $token = $manager->issue('u1', 'map-1');

        self::assertSame(TokenStatus::Valid, $manager->consume($token, 'map'));
        self::assertSame(TokenStatus::Replayed, $manager->consume($token, 'map'));
    }

    public function testExpiredTokenIsExpired(): void
    {
        $now = 100.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        $manager = new TokenManager(new InMemoryTokenStore($clock), $clock);

        $token = $manager->issue('u1', 'map-1', ttlSeconds: 1);

        $now += 2.0;

        self::assertSame(TokenStatus::Expired, $manager->consume($token, 'map'));
    }

    public function testMalformedTokenIsInvalidWithoutTouchingStore(): void
    {
        $manager = new TokenManager(new InMemoryTokenStore());

        self::assertSame(TokenStatus::Invalid, $manager->consume('not-a-token', 'map'));
    }

    public function testWellFormedButUnknownTokenIsInvalid(): void
    {
        $manager = new TokenManager(new InMemoryTokenStore());

        self::assertSame(TokenStatus::Invalid, $manager->consume(str_repeat('a', 64), 'map'));
    }

    public function testScopeFailingWhitelistShortCircuitsInvalidWithoutConsuming(): void
    {
        // scope 白名单 /^[a-z][a-z0-9-]{0,31}$/：不匹配在管理器层短路 Invalid，token 不被消费（可继续正常消费）
        // Scope whitelist /^[a-z][a-z0-9-]{0,31}$/: mismatches short-circuit to Invalid at the manager layer and the token stays unconsumed (a later valid consume still succeeds)
        $manager = new TokenManager(new InMemoryTokenStore());

        $token = $manager->issue('u1', 'map-1');

        // 非法 scope 集合：大写字母 / 数字开头 / 特殊字符 / 超长（33 字符，超过 32 上限）
        // Illegal scope set: uppercase, leading digit, special characters, overlong (33 chars, above the 32 cap)
        foreach (['MAP', '1map', 'map!', str_repeat('m', 33), 'map scope'] as $scope) {
            self::assertSame(TokenStatus::Invalid, $manager->consume($token, $scope));
        }

        // 短路语义：token 未被消费，白名单内的 scope 仍可正常 Valid
        // Short-circuit semantics: the token remains unconsumed, and a whitelisted scope still consumes as Valid
        self::assertSame(TokenStatus::Valid, $manager->consume($token, 'map'));
    }

    public function testIllegalScopeDoesNotReachStore(): void
    {
        // scope 白名单短路：非法格式在管理器层判定 Invalid，存储层 consume 不被调用（键注入面不扩大）
        // Scope whitelist short-circuit: illegal formats are judged Invalid at the manager layer
        // and the store's consume is never invoked (the key-injection surface stays narrow)
        $store = $this->createMock(TokenStoreInterface::class);
        $store->expects(self::never())->method('consume');
        $store->expects(self::never())->method('save');
        $manager = new TokenManager($store);

        foreach (['MAP', '1map', 'map!', str_repeat('m', 33), 'map scope', ''] as $scope) {
            self::assertSame(TokenStatus::Invalid, $manager->consume(str_repeat('a', 64), $scope));
        }
    }

    public function testPeekIssuedTokenReturnsRecordWithoutConsuming(): void
    {
        $manager = new TokenManager(new InMemoryTokenStore());

        $token = $manager->issue('u1', 'map-1');

        $record = $manager->peek($token);

        self::assertNotNull($record);
        self::assertSame('u1', $record->uid);
        self::assertSame('map-1', $record->mapId);
        self::assertSame(['map'], $record->scopes);
        // peek 不消费：consume 仍为 Valid
        self::assertSame(TokenStatus::Valid, $manager->consume($token, 'map'));
    }

    public function testPeekMalformedTokenReturnsNull(): void
    {
        $manager = new TokenManager(new InMemoryTokenStore());

        self::assertNull($manager->peek('not-a-token'));
    }

    public function testPeekUnknownWellFormedTokenReturnsNull(): void
    {
        $manager = new TokenManager(new InMemoryTokenStore());

        self::assertNull($manager->peek(str_repeat('a', 64)));
    }
}
