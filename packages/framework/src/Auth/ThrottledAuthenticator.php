<?php

declare(strict_types=1);

namespace Nythros\Framework\Auth;

use Nythros\Security\AuthenticationException;
use Nythros\Security\AuthenticatorInterface;
use Nythros\Security\IdentityInterface;

/**
 * 防爆破认证装饰器：按 username 统计连续失败，达到阈值后锁定一段时间，成功即清零。
 * Brute-force-guard authenticator decorator: counts consecutive failures per username, locks the
 * username for a window once the threshold is reached, and clears the count on success.
 *
 * 装配位置：包在真实 AuthenticatorInterface 之外（gateway 角色单进程装配，计数器进程内即完整——
 * gateway 是登录唯一入口，不存在多实例分摊同一账号尝试的场景；多网关实例时每实例独立计数，
 * 全局上限 = maxAttempts × 实例数，生产可按需调低阈值）。
 * Assembly position: wraps the real AuthenticatorInterface (the gateway role is a single process, so an
 * in-process counter is complete — the gateway is the sole login entry; with multiple gateway instances each
 * counts independently and the global cap becomes maxAttempts × instance count; tune the threshold down for that case).
 *
 * 判定顺序：锁定中的 username 直接拒绝（不触达内层，不给枚举/爆破流量任何计算量）；
 * 未锁定才走内层认证，失败记账、成功清零。异常文案与内层一致透传，锁定拒绝用固定文案——
 * 不区分「锁定中」与「密码错误」以外的原因，避免泄露锁定状态被用于账号探测计时侧信道。
 * Order: a locked username is rejected immediately (the inner authenticator is never touched, giving
 * enumeration/brute-force traffic zero compute); unlocked usernames go through the inner authentication with
 * failures recorded and successes cleared. Inner exception messages pass through unchanged; lockout rejections
 * use a fixed message — beyond the existing "no account-existence leakage" semantics, lockout state itself is
 * not differentiated to avoid a timing side channel for account probing.
 */
final class ThrottledAuthenticator implements AuthenticatorInterface
{
    /** @var array<string, array{count: int, lockedUntil: float}> username => 失败计数与锁定截止时刻 */
    private array $state = [];

    /**
     * 构造防爆破装饰器。
     * Constructs the brute-force guard.
     *
     * @param AuthenticatorInterface $inner 真实认证器 The real authenticator.
     * @param int $maxAttempts 锁定阈值（连续失败次数） Failure count that triggers a lockout.
     * @param int $lockoutSeconds 锁定时长（秒） Lockout duration in seconds.
     * @param (\Closure(): float)|null $clock 时钟注入（测试用，缺省 microtime(true)） Injectable clock (for tests; defaults to microtime(true)).
     */
    public function __construct(
        private readonly AuthenticatorInterface $inner,
        private readonly int $maxAttempts = 5,
        private readonly int $lockoutSeconds = 60,
        private readonly ?\Closure $clock = null,
    ) {
        if ($maxAttempts < 1 || $lockoutSeconds < 1) {
            throw new \InvalidArgumentException('maxAttempts 与 lockoutSeconds 必须 >= 1');
        }
    }

    public function authenticate(array $credentials): IdentityInterface
    {
        $username = is_string($credentials['username'] ?? null) ? $credentials['username'] : '';
        $now = $this->clock !== null ? ($this->clock)() : microtime(true);

        // 锁定中：直接拒绝（含缺 username 的非法凭证——无身份可记账，同样快速失败）
        // Locked (or no usable username to track): reject immediately.
        $entry = $this->state[$username] ?? null;
        if ($entry !== null && $entry['lockedUntil'] > $now) {
            throw new AuthenticationException('尝试过于频繁，请稍后再试');
        }

        try {
            $identity = $this->inner->authenticate($credentials);
        } catch (AuthenticationException $e) {
            $count = ($this->state[$username]['count'] ?? 0) + 1;
            $this->state[$username] = [
                'count' => $count,
                'lockedUntil' => $count >= $this->maxAttempts ? $now + $this->lockoutSeconds : 0.0,
            ];
            throw $e;
        }

        unset($this->state[$username]);

        return $identity;
    }
}
