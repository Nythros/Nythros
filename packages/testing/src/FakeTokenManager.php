<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Security\TokenManagerInterface;
use Nythros\Security\TokenRecord;
use Nythros\Security\TokenStatus;

/**
 * FakeTokenManager - 记录 peek/consume/issue 调用；peek 按配置 records 返回，consume 按配置 results 返回（缺省 Valid），issue 可配置抛异常。
 * FakeTokenManager - records peek/consume/issue calls; peek returns configured records, consume returns configured results (Valid by default), issue can be configured to throw.
 */
final class FakeTokenManager implements TokenManagerInterface
{
    /** @var array<string, TokenRecord> token => 记录 peek 返回表 Peek result table. */
    public array $records = [];

    /** @var array<string, TokenStatus> token => consume 判定（缺省 Valid） Consume verdict per token (Valid by default). */
    public array $consumeResults = [];

    /** @var list<array{token: string, scope: string}> consume 调用记录 Consume call records. */
    public array $consumeCalls = [];

    /** @var list<array{uid: string, mapId: string, scopes: array<string>, ttlSeconds: int}> issue 调用记录 Issue call records. */
    public array $issueCalls = [];

    /** @var ?\Throwable issue 抛出的异常（非 null 时 issue 抛出） The throwable issue throws when set. */
    public ?\Throwable $issueException = null;

    public function peek(string $token): ?TokenRecord
    {
        return $this->records[$token] ?? null;
    }

    public function consume(string $token, string $scope): TokenStatus
    {
        $this->consumeCalls[] = ['token' => $token, 'scope' => $scope];

        return $this->consumeResults[$token] ?? TokenStatus::Valid;
    }

    public function issue(string $uid, string $mapId, array $scopes = ['map'], int $ttlSeconds = 30): string
    {
        $this->issueCalls[] = ['uid' => $uid, 'mapId' => $mapId, 'scopes' => $scopes, 'ttlSeconds' => $ttlSeconds];
        if ($this->issueException !== null) {
            throw $this->issueException;
        }

        return 'issued-token-' . count($this->issueCalls);
    }
}
