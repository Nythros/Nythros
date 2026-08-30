<?php

declare(strict_types=1);

namespace Nythros\Security;

/**
 * Token 五态枚举：consume 的一次性判定结果（决策 F：token 多授权）。
 * 公开（ADR-023 白名单）：纯五态值枚举，语义类比 Position 值对象先例——framework 消费
 * （SocialService 的 token 消费登录按五态归因 auth_failed reason），无实现泄漏。
 * Token status enum: the one-shot verdict produced by consume (decision F: multi-scope tokens).
 * Public (the ADR-023 whitelist): a pure five-state value enum, semantically following the Position value-object
 * precedent — the framework consumes it (SocialService's token-consume login attributes auth_failed reasons by
 * the five states), with no implementation leakage.
 */
enum TokenStatus
{
    /** 有效：该 scope 首次消费成功 Valid: this scope's first successful consumption. */
    case Valid;

    /** 已过期：存在但超过 expiresAt Expired: exists but past expiresAt. */
    case Expired;

    /** 重放：该 scope 已消费过（墓碑期内再次使用） Replayed: this scope was already consumed (reused within the tombstone window). */
    case Replayed;

    /** 无效：不存在或格式非法 Invalid: does not exist or has an illegal format. */
    case Invalid;

    /** 未授权：token 有效但 scope 不在 scopes 列表（不消费任何标记） Unauthorized: the token is valid but the scope is absent from its scope list (nothing consumed). */
    case Unauthorized;
}
