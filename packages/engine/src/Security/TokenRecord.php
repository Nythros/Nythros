<?php

declare(strict_types=1);

namespace Nythros\Security;

/**
 * Token 记录：一次签发（issue）产生的凭证数据（{uid, mapId, scopes, issuedAt, expiresAt}）。
 * Token record: the credential data produced by a single issue operation ({uid, mapId, scopes, issuedAt, expiresAt}).
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final readonly class TokenRecord
{
    /**
     * 构造 Token 记录。
     * Create a token record.
     *
     * @param string $uid 用户唯一标识 Unique user identifier
     * @param string $mapId 目标地图标识 Target map identifier
     * @param ?list<string> $scopes 授权 scope 列表（null = 旧格式记录，仅授权 'map'；'map'/'chat'/'team' 子集）
     *                               Authorized scope list (null = legacy record authorizing 'map' only; a subset of 'map'/'chat'/'team')
     * @param float $issuedAt 签发时间（microtime） Issued-at time (microtime)
     * @param float $expiresAt 过期时间（microtime） Expires-at time (microtime)
     */
    public function __construct(
        public string $uid,
        public string $mapId,
        /** @var ?list<string> 授权 scope 列表（null = 旧格式，仅授权 'map'） Authorized scope list (null = legacy, authorizing 'map' only) */
        public ?array $scopes,
        public float $issuedAt,
        public float $expiresAt,
    ) {
    }
}
