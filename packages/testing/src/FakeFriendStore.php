<?php

declare(strict_types=1);

namespace Nythros\Testing;

use Nythros\Framework\Social\FriendStoreInterface;

/**
 * FakeFriendStore - 内存好友关系存储：与 RedisFriendStore 同语义（双向一致/申请去重），供 SocialService 单测。
 * FakeFriendStore - an in-memory friend store with the same semantics as RedisFriendStore (bidirectional
 * consistency / application dedupe), serving the SocialService unit tests.
 */
final class FakeFriendStore implements FriendStoreInterface
{
    /** @var array<string, list<string>> uid => 好友 uid 列表 uid => friend uid list. */
    public array $friends = [];

    /** @var array<string, list<string>> uid => 指向 uid 的申请方列表 uid => applicants targeting uid. */
    public array $requests = [];

    public function apply(string $fromUid, string $toUid): array
    {
        if ($fromUid === $toUid) {
            return ['code' => self::CODE_SELF];
        }
        if (in_array($toUid, $this->friends[$fromUid] ?? [], true)) {
            return ['code' => self::CODE_ALREADY_FRIENDS];
        }
        if (in_array($fromUid, $this->requests[$toUid] ?? [], true)) {
            return ['code' => self::CODE_REQUEST_EXISTS];
        }
        $this->requests[$toUid][] = $fromUid;

        return ['code' => self::CODE_OK];
    }

    public function accept(string $applicantUid, string $acceptorUid): array
    {
        if ($applicantUid === $acceptorUid) {
            return ['code' => self::CODE_SELF];
        }
        $requests = $this->requests[$acceptorUid] ?? [];
        if (!in_array($applicantUid, $requests, true)) {
            return ['code' => self::CODE_REQUEST_NOT_FOUND];
        }
        $this->requests[$acceptorUid] = array_values(array_filter(
            $requests,
            static fn (string $applicant): bool => $applicant !== $applicantUid,
        ));
        $this->requests[$applicantUid] = array_values(array_filter(
            $this->requests[$applicantUid] ?? [],
            static fn (string $applicant): bool => $applicant !== $acceptorUid,
        ));
        $this->friends[$applicantUid][] = $acceptorUid;
        $this->friends[$acceptorUid][] = $applicantUid;

        return ['code' => self::CODE_OK];
    }

    public function reject(string $applicantUid, string $rejectorUid): array
    {
        $requests = $this->requests[$rejectorUid] ?? [];
        if (!in_array($applicantUid, $requests, true)) {
            return ['code' => self::CODE_REQUEST_NOT_FOUND];
        }
        $this->requests[$rejectorUid] = array_values(array_filter(
            $requests,
            static fn (string $applicant): bool => $applicant !== $applicantUid,
        ));

        return ['code' => self::CODE_OK];
    }

    public function remove(string $uid, string $targetUid): array
    {
        if ($uid === $targetUid) {
            return ['code' => self::CODE_SELF];
        }
        if (!in_array($targetUid, $this->friends[$uid] ?? [], true)) {
            return ['code' => self::CODE_NOT_FRIENDS];
        }
        $this->friends[$uid] = array_values(array_filter(
            $this->friends[$uid],
            static fn (string $friend): bool => $friend !== $targetUid,
        ));
        $this->friends[$targetUid] = array_values(array_filter(
            $this->friends[$targetUid] ?? [],
            static fn (string $friend): bool => $friend !== $uid,
        ));

        return ['code' => self::CODE_OK];
    }

    public function list(string $uid): array
    {
        $friends = $this->friends[$uid] ?? [];
        sort($friends, SORT_STRING);

        return $friends;
    }
}
