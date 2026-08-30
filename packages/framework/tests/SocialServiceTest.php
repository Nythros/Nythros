<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests;

require_once __DIR__ . '/FakeCluster.php';
require_once __DIR__ . '/FakeSocial.php';

use Nythros\Cluster\ServiceInstance;
use Nythros\Framework\Social\GuildStoreInterface;
use Nythros\Framework\Social\HubTransportInterface;
use Nythros\Framework\Social\InMemoryConnectionHub;
use Nythros\Framework\Social\SocialService;
use Nythros\Protocol\Frame;
use Nythros\Protocol\JsonSerializer;
use Nythros\Protocol\Message;
use Nythros\Security\AuthenticationException;
use Nythros\Security\TokenRecord;
use Nythros\Security\TokenStatus;
use PHPUnit\Framework\TestCase;

/**
 * SocialServiceTest - 纯业务单测：auth 流程 / chat 五语义 / map:enter。
 * 组装策略：ConnectionHub/TeamStore/LocationStore/GuildStore/Authenticator 用 FakeSocial 记录调用与配置返回；
 * registry/token 复用 FakeCluster 的 FakeServiceRegistry/FakeTokenManager；序列化走真实 JsonSerializer。
 * SocialServiceTest - pure business unit tests: auth flow / the five chat semantics / map:enter.
 * Assembly strategy: ConnectionHub/TeamStore/LocationStore/GuildStore/Authenticator use the FakeSocial fakes for call
 * recording and configured returns; registry/token reuse FakeCluster's FakeServiceRegistry/FakeTokenManager; the codec is the real JsonSerializer.
 */
final class SocialServiceTest extends TestCase
{
    public function testAuthSuccessRepliesAuthOkWithFiveFieldsAndLeastLoadedChannel(): void
    {
        $h = $this->buildHarness();
        $h->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 9, 'wsAddress' => 'ws://ch1']);
        $h->registry->discoveries['map']['map-1#ch-2'] = new ServiceInstance('map-1#ch-2', ['mapId' => 'map-1', 'channelId' => 'ch-2', 'playerCount' => 1, 'wsAddress' => 'ws://ch2']);

        $h->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1'], 'auth-1'));

        // auth_ok 五字段：uid/token/map/team/guild；最少在线落到 ch-2
        $ok = self::messagesOfType($h->frames(), 'auth_ok');
        self::assertCount(1, $ok);
        self::assertSame('1001', $ok[0]->payload['uid']);
        self::assertSame('issued-token-1', $ok[0]->payload['token']);
        self::assertSame('map-1', $ok[0]->payload['map']['mapId']);
        self::assertSame('ch-2', $ok[0]->payload['map']['channelId']);
        self::assertSame('ws://ch2', $ok[0]->payload['map']['wsAddress']);
        self::assertNull($ok[0]->payload['team']);
        self::assertNull($ok[0]->payload['guild']);

        // issue 签多 scope（map/chat/team 各服务消费自己的 scope，ADR-021 §3.2）
        self::assertCount(1, $h->tokens->issueCalls);
        self::assertSame('1001', $h->tokens->issueCalls[0]['uid']);
        self::assertSame('map-1', $h->tokens->issueCalls[0]['mapId']);
        self::assertSame(['map', 'chat', 'team'], $h->tokens->issueCalls[0]['scopes']);
        self::assertSame(30, $h->tokens->issueCalls[0]['ttlSeconds']);

        // bindUid + setSession（uid + loc）+ joinGroup 频道组 + 位置快照
        self::assertSame(['conn-1|1001'], $h->gateway->binds);
        self::assertCount(1, $h->gateway->setSessions);
        self::assertSame(['uid' => '1001', 'loc' => ['mapId' => 'map-1', 'channelId' => 'ch-2']], $h->gateway->setSessions[0]['session']);
        self::assertContains('conn-1|map:map-1:ch-2', $h->gateway->joinGroups);
        self::assertCount(1, $h->location->saves);
        self::assertSame('ch-2', $h->location->saves[0]['channelId']);
    }

    public function testAuthSelectsDungeonByTypeThenLeastLoadedPool(): void
    {
        // 副本分类（2a）：dungeon-A 类型下有 pool-1/pool-2 两个进程，按 mapId=dungeon-A 过滤 + 最少在线选 pool-2
        // Dungeon classification (2a): the dungeon-A type has pool-1/pool-2 processes; filtered by mapId=dungeon-A, then least-loaded pool-2
        $h = $this->buildHarness(['map-1', 'map-2', 'dungeon-A']);
        $h->registry->discoveries['map']['dungeon-A#pool-1'] = new ServiceInstance('dungeon-A#pool-1', ['mapId' => 'dungeon-A', 'channelId' => 'pool-1', 'playerCount' => 4, 'wsAddress' => 'ws://d-p1']);
        $h->registry->discoveries['map']['dungeon-A#pool-2'] = new ServiceInstance('dungeon-A#pool-2', ['mapId' => 'dungeon-A', 'channelId' => 'pool-2', 'playerCount' => 1, 'wsAddress' => 'ws://d-p2']);
        $h->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://city']);

        // 登录选择 dungeon-A 副本：不应串到 map-1 主城（mapId 过滤），落到最少在线的 pool-2
        $h->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'secret', 'mapId' => 'dungeon-A'], 'auth-1'));

        $ok = self::messagesOfType($h->frames(), 'auth_ok');
        self::assertCount(1, $ok);
        self::assertSame('dungeon-A', $ok[0]->payload['map']['mapId']);
        self::assertSame('pool-2', $ok[0]->payload['map']['channelId'], 'dungeon-A 副本应落到最少在线的进程池。');
        self::assertSame('ws://d-p2', $ok[0]->payload['map']['wsAddress']);
    }

    public function testAuthAuthenticateFailureReplies401AndCloses(): void
    {
        $h = $this->buildHarness();
        $h->authenticator->exception = new AuthenticationException('用户名或密码错误');

        $h->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'bad', 'mapId' => 'map-1'], 'auth-1'));

        $failed = self::messagesOfType($h->frames(), 'auth_failed');
        self::assertCount(1, $failed);
        self::assertSame(401, $failed[0]->payload['code']);
        self::assertSame(['conn-1'], $h->gateway->closes);
    }

    public function testAuthUnknownMapIdReplies400AndCloses(): void
    {
        $h = $this->buildHarness();

        $h->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'secret', 'mapId' => 'map-9'], 'auth-1'));

        $failed = self::messagesOfType($h->frames(), 'auth_failed');
        self::assertCount(1, $failed);
        self::assertSame(400, $failed[0]->payload['code']);
        self::assertSame(['conn-1'], $h->gateway->closes);
    }

    public function testAuthNoAvailableChannelReplies503AndCloses(): void
    {
        $h = $this->buildHarness();

        $h->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1'], 'auth-1'));

        $failed = self::messagesOfType($h->frames(), 'auth_failed');
        self::assertCount(1, $failed);
        self::assertSame(503, $failed[0]->payload['code']);
        self::assertSame(['conn-1'], $h->gateway->closes);
    }

    public function testAuthRecoveryPrefersOriginalChannelDespiteHigherLoad(): void
    {
        $h = $this->buildHarness();
        $h->location->offline['1001'] = true;
        $h->location->locations['1001'] = ['mapId' => 'map-1', 'channelId' => 'ch-1', 'x' => null, 'y' => null, 'updatedAt' => 0.0];
        $h->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 9, 'wsAddress' => 'ws://ch1']);
        $h->registry->discoveries['map']['map-1#ch-2'] = new ServiceInstance('map-1#ch-2', ['mapId' => 'map-1', 'channelId' => 'ch-2', 'playerCount' => 1, 'wsAddress' => 'ws://ch2']);

        $h->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1'], 'auth-1'));

        // 恢复模式优先原频道 ch-1（即使更拥挤）
        $ok = self::messagesOfType($h->frames(), 'auth_ok');
        self::assertCount(1, $ok);
        self::assertSame('ch-1', $ok[0]->payload['map']['channelId']);
        // 掉线标记清除
        self::assertSame(['1001'], $h->location->clearOfflines);
    }

    public function testAuthRestoresTeamAndGuildGroups(): void
    {
        $h = $this->buildHarness();
        $h->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1']);
        $h->team->uidTeam['1001'] = 'team-1';
        $h->team->teams['team-1'] = ['leaderUid' => '1001', 'members' => ['1001']];
        $h->guild->uidGuild['1001'] = 'guild-1';
        $h->guild->guilds['guild-1'] = ['name' => null, 'notice' => '', 'members' => ['1001']];

        $h->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1'], 'auth-1'));

        $ok = self::messagesOfType($h->frames(), 'auth_ok');
        self::assertCount(1, $ok);
        self::assertSame('team-1', $ok[0]->payload['team']['teamId']);
        self::assertSame('1001', $ok[0]->payload['team']['leaderUid']);
        self::assertSame(['1001'], $ok[0]->payload['team']['members']);
        self::assertSame('guild-1', $ok[0]->payload['guild']['guildId']);
        self::assertSame(['1001'], $ok[0]->payload['guild']['members']);

        self::assertContains('conn-1|team:team-1', $h->gateway->joinGroups);
        self::assertContains('conn-1|guild:guild-1', $h->gateway->joinGroups);
        self::assertContains('conn-1|map:map-1:ch-1', $h->gateway->joinGroups);
    }

    public function testChatWorldBroadcastsExcludingSender(): void
    {
        $h = $this->buildHarness();
        $h->gateway->sessions['conn-1'] = ['uid' => '1001', 'loc' => ['mapId' => 'map-1', 'channelId' => 'ch-1']];

        $h->service->handleChat('conn-1', '1001', Message::create('chat:send', ['scope' => 'world', 'content' => 'hello'], 'c-1'));

        self::assertCount(1, $h->gateway->sendToAlls);
        self::assertSame('conn-1', $h->gateway->sendToAlls[0]['exclude']);
        $sent = SocialServiceHarness::decode($h->gateway->sendToAlls[0]['message']);
        self::assertSame('chat:message', $sent->type);
        self::assertSame('world', $sent->payload['scope']);
        self::assertSame('hello', $sent->payload['content']);
        self::assertSame('1001', $sent->payload['fromUid']);
    }

    public function testChatChannelBroadcastsToOwnChannelGroup(): void
    {
        $h = $this->buildHarness();
        $h->gateway->sessions['conn-1'] = ['uid' => '1001', 'loc' => ['mapId' => 'map-1', 'channelId' => 'ch-1']];

        $h->service->handleChat('conn-1', '1001', Message::create('chat:send', ['scope' => 'channel', 'content' => 'hi'], 'c-1'));

        self::assertCount(1, $h->gateway->sendToGroups);
        self::assertSame('map:map-1:ch-1', $h->gateway->sendToGroups[0]['group']);
        self::assertSame('conn-1', $h->gateway->sendToGroups[0]['exclude']);
    }

    public function testChatChannelCrossChannelRejectedWith404(): void
    {
        $h = $this->buildHarness();
        $h->gateway->sessions['conn-1'] = ['uid' => '1001', 'loc' => ['mapId' => 'map-1', 'channelId' => 'ch-1']];

        $h->service->handleChat('conn-1', '1001', Message::create('chat:send', ['scope' => 'channel', 'content' => 'hi', 'channelId' => 'ch-2'], 'c-1'));

        $error = self::messagesOfType($h->frames(), 'chat:error');
        self::assertCount(1, $error);
        self::assertSame(404, $error[0]->payload['code']);
        self::assertSame('channel unknown', $error[0]->payload['message']);
        self::assertCount(0, $h->gateway->sendToGroups);
    }

    public function testChatTeamWithoutTeamReplies404(): void
    {
        $h = $this->buildHarness();
        $h->gateway->sessions['conn-1'] = ['uid' => '1001', 'loc' => ['mapId' => 'map-1', 'channelId' => 'ch-1']];

        $h->service->handleChat('conn-1', '1001', Message::create('chat:send', ['scope' => 'team', 'content' => 'hi'], 'c-1'));

        $error = self::messagesOfType($h->frames(), 'chat:error');
        self::assertCount(1, $error);
        self::assertSame(404, $error[0]->payload['code']);
        self::assertSame('not in team', $error[0]->payload['message']);
    }

    public function testChatGuildWithoutGuildReplies404(): void
    {
        $h = $this->buildHarness();
        $h->gateway->sessions['conn-1'] = ['uid' => '1001', 'loc' => ['mapId' => 'map-1', 'channelId' => 'ch-1']];

        $h->service->handleChat('conn-1', '1001', Message::create('chat:send', ['scope' => 'guild', 'content' => 'hi'], 'c-1'));

        $error = self::messagesOfType($h->frames(), 'chat:error');
        self::assertCount(1, $error);
        self::assertSame('not in guild', $error[0]->payload['message']);
    }

    public function testChatPrivateToOfflineTargetReplies404(): void
    {
        $h = $this->buildHarness();
        $h->gateway->sessions['conn-1'] = ['uid' => '1001', 'loc' => ['mapId' => 'map-1', 'channelId' => 'ch-1']];

        $h->service->handleChat('conn-1', '1001', Message::create('chat:send', ['scope' => 'private', 'content' => 'hi', 'targetUid' => '1002'], 'c-1'));

        $error = self::messagesOfType($h->frames(), 'chat:error');
        self::assertCount(1, $error);
        self::assertSame(404, $error[0]->payload['code']);
        self::assertSame('target offline', $error[0]->payload['message']);
        self::assertCount(0, $h->gateway->sendToUids);
    }

    public function testChatPrivateToOnlineTargetSendsToUid(): void
    {
        $h = $this->buildHarness();
        $h->gateway->sessions['conn-1'] = ['uid' => '1001', 'loc' => ['mapId' => 'map-1', 'channelId' => 'ch-1']];
        $h->gateway->online['1002'] = true;

        $h->service->handleChat('conn-1', '1001', Message::create('chat:send', ['scope' => 'private', 'content' => 'hi', 'targetUid' => '1002'], 'c-1'));

        self::assertCount(1, $h->gateway->sendToUids);
        self::assertSame('1002', $h->gateway->sendToUids[0]['uid']);
        $sent = SocialServiceHarness::decode($h->gateway->sendToUids[0]['message']);
        self::assertSame('private', $sent->payload['scope']);
        self::assertSame('1001', $sent->payload['fromUid']);
    }

    public function testMapEnterIssuesTokenAndRepliesMapEntered(): void
    {
        $h = $this->buildHarness();
        $h->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 2, 'wsAddress' => 'ws://ch1']);

        $h->service->handleMapEnter('conn-1', '1001', Message::create('map:enter', ['mapId' => 'map-1'], 'm-1'));

        $entered = self::messagesOfType($h->frames(), 'map:entered');
        self::assertCount(1, $entered);
        self::assertSame('issued-token-1', $entered[0]->payload['token']);
        self::assertSame('map-1', $entered[0]->payload['map']['mapId']);
        self::assertSame('ch-1', $entered[0]->payload['map']['channelId']);
        self::assertSame('ws://ch1', $entered[0]->payload['map']['wsAddress']);

        self::assertCount(1, $h->tokens->issueCalls);
        self::assertSame(['map'], $h->tokens->issueCalls[0]['scopes']);
        // 不写位置快照、不 joinGroup（等 map:join 上报）
        self::assertCount(0, $h->location->saves);
        self::assertCount(0, $h->gateway->joinGroups);
    }

    public function testMapEnterUnknownMapIdReplies400(): void
    {
        $h = $this->buildHarness();

        $h->service->handleMapEnter('conn-1', '1001', Message::create('map:enter', ['mapId' => 'map-9'], 'm-1'));

        $error = self::messagesOfType($h->frames(), 'map:error');
        self::assertCount(1, $error);
        self::assertSame(400, $error[0]->payload['code']);
    }

    public function testMapEnterPrefersCurrentSessionChannelOverLeastLoaded(): void
    {
        // R1 e2e 缺陷回归：同图重入必须沿用当前会话频道（登录即进图确定性）——即使该频道
        // playerCount 已不再是最低（心跳水位漂移），也不得在 map:enter 时被悄悄换掉。
        // R1 e2e-defect regression: a same-map re-entry must keep the current session channel (login-to-map
        // determinism) — even when its playerCount is no longer the lowest (heartbeat watermark drift), the
        // channel must not be silently swapped at map:enter.
        $h = $this->buildHarness();
        $h->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1']);
        $h->registry->discoveries['map']['map-1#ch-2'] = new ServiceInstance('map-1#ch-2', ['mapId' => 'map-1', 'channelId' => 'ch-2', 'playerCount' => 5, 'wsAddress' => 'ws://ch2']);
        $h->gateway->sessions['conn-1'] = ['uid' => '1001', 'loc' => ['mapId' => 'map-1', 'channelId' => 'ch-2']];

        $h->service->handleMapEnter('conn-1', '1001', Message::create('map:enter', ['mapId' => 'map-1'], 'm-1'));

        $entered = self::messagesOfType($h->frames(), 'map:entered');
        self::assertCount(1, $entered);
        self::assertSame('ch-2', $entered[0]->payload['map']['channelId'], 'map:enter 必须沿用会话所在频道 ch-2。map:enter must keep the session channel ch-2.');
    }

    public function testMapEnterFallsBackToLeastLoadedWhenSessionChannelStopping(): void
    {
        // 会话频道已 mark-stopping：恢复模式未命中 → 最少在线重选（selectChannel 的降级语义不变）。
        // The session channel is mark-stopping: the recovery mode misses → least-loaded re-selection (selectChannel's
        // fallback semantics unchanged).
        $h = $this->buildHarness();
        $h->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 3, 'wsAddress' => 'ws://ch1']);
        $h->registry->discoveries['map']['map-1#ch-2'] = new ServiceInstance('map-1#ch-2', ['mapId' => 'map-1', 'channelId' => 'ch-2', 'playerCount' => 0, 'status' => 'stopping', 'wsAddress' => 'ws://ch2']);
        $h->gateway->sessions['conn-1'] = ['uid' => '1001', 'loc' => ['mapId' => 'map-1', 'channelId' => 'ch-2']];

        $h->service->handleMapEnter('conn-1', '1001', Message::create('map:enter', ['mapId' => 'map-1'], 'm-1'));

        $entered = self::messagesOfType($h->frames(), 'map:entered');
        self::assertCount(1, $entered);
        self::assertSame('ch-1', $entered[0]->payload['map']['channelId'], '原频道 stopping 时回退最少在线。A stopping original channel falls back to least-loaded.');
    }

    public function testMapEnterPrefersLocationSnapshotWhenSessionHasNoLoc(): void
    {
        // 会话无 loc（如 chat/team 角色连接或极端清理后）：Redis 位置快照兜底恢复同图频道。
        // The session carries no loc (e.g. after extreme cleanup): the Redis location snapshot is the fallback for
        // same-map channel recovery.
        $h = $this->buildHarness();
        $h->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1']);
        $h->registry->discoveries['map']['map-1#ch-2'] = new ServiceInstance('map-1#ch-2', ['mapId' => 'map-1', 'channelId' => 'ch-2', 'playerCount' => 4, 'wsAddress' => 'ws://ch2']);
        $h->location->locations['1001'] = ['mapId' => 'map-1', 'channelId' => 'ch-2', 'x' => null, 'y' => null, 'updatedAt' => 1.0];

        $h->service->handleMapEnter('conn-1', '1001', Message::create('map:enter', ['mapId' => 'map-1'], 'm-1'));

        $entered = self::messagesOfType($h->frames(), 'map:entered');
        self::assertCount(1, $entered);
        self::assertSame('ch-2', $entered[0]->payload['map']['channelId'], '无会话 loc 时按位置快照恢复 ch-2。With no session loc, ch-2 is recovered from the location snapshot.');
    }

    public function testAuthOkCarriesEndpointsOnlyWhenAddressesInjected(): void
    {
        // endpoints 注入分支（ADR-021 §3.2）：构造传入 endpointAddresses 后 auth_ok 携带 chat/team 三地址中的两址；
        // 缺省（空数组）不产生 endpoints 键——部署拓扑未声明时不向客户端下发空端点。
        // The endpoints injection branch (ADR-021 §3.2): with endpointAddresses passed to the constructor, auth_ok carries
        // the chat/team addresses; with the default (empty array) no endpoints key is emitted — an undeclared topology
        // never hands out empty endpoints.
        $injected = $this->buildHarness(['map-1', 'map-2'], ['chat' => 'ws://chat:18286', 'team' => 'ws://team:18287']);
        $injected->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1']);

        $injected->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1'], 'auth-1'));

        $okInjected = self::messagesOfType($injected->frames(), 'auth_ok');
        self::assertCount(1, $okInjected);
        self::assertSame([
            'chat' => ['wsAddress' => 'ws://chat:18286'],
            'team' => ['wsAddress' => 'ws://team:18287'],
        ], $okInjected[0]->payload['endpoints']);

        $bare = $this->buildHarness();
        $bare->registry->discoveries['map']['map-1#ch-1'] = new ServiceInstance('map-1#ch-1', ['mapId' => 'map-1', 'channelId' => 'ch-1', 'playerCount' => 0, 'wsAddress' => 'ws://ch1']);

        $bare->service->handleAuth('conn-1', Message::create('auth', ['username' => '1001', 'password' => 'secret', 'mapId' => 'map-1'], 'auth-1'));

        $okBare = self::messagesOfType($bare->frames(), 'auth_ok');
        self::assertCount(1, $okBare);
        self::assertArrayNotHasKey('endpoints', $okBare[0]->payload);
    }

    public function testFriendApplyRepliesOkAndNotifiesTargetOnline(): void
    {
        $h = $this->buildHarness();
        $h->gateway->online['1002'] = true;

        $h->service->handleFriend('conn-1', '1001', Message::create('friend:apply', ['targetUid' => '1002'], 'f-1'));

        $ok = self::messagesOfType($h->frames(), 'friend:ok');
        self::assertCount(1, $ok);
        self::assertSame('apply', $ok[0]->payload['action']);

        // 在线通知：sendToUid 定向目标（hub 契约对离线自动丢弃）
        // Online notification: sendToUid directed at the target (the hub contract drops it when offline)
        self::assertCount(1, $h->gateway->sendToUids);
        self::assertSame('1002', $h->gateway->sendToUids[0]['uid']);
        $notify = SocialServiceHarness::decode($h->gateway->sendToUids[0]['message']);
        self::assertSame('friend:notify', $notify->type);
        self::assertSame('applied', $notify->payload['type']);
        self::assertSame('1001', $notify->payload['fromUid']);
    }

    public function testFriendApplyDuplicateMaps409RequestExists(): void
    {
        $h = $this->buildHarness();

        $h->service->handleFriend('conn-1', '1001', Message::create('friend:apply', ['targetUid' => '1002'], 'f-1'));
        $h->service->handleFriend('conn-1', '1001', Message::create('friend:apply', ['targetUid' => '1002'], 'f-2'));

        $error = self::messagesOfType($h->frames(), 'friend:error');
        self::assertCount(1, $error);
        self::assertSame(409, $error[0]->payload['code']);
        self::assertSame('request_exists', $error[0]->payload['message']);
    }

    public function testFriendSelfApplyMaps400(): void
    {
        $h = $this->buildHarness();

        $h->service->handleFriend('conn-1', '1001', Message::create('friend:apply', ['targetUid' => '1001'], 'f-1'));

        $error = self::messagesOfType($h->frames(), 'friend:error');
        self::assertCount(1, $error);
        self::assertSame(400, $error[0]->payload['code']);
        self::assertSame('self_not_allowed', $error[0]->payload['message']);
    }

    public function testFriendMissingOrIllegalTargetUidMaps400(): void
    {
        $h = $this->buildHarness();

        $h->service->handleFriend('conn-1', '1001', Message::create('friend:apply', [], 'f-1'));
        $h->service->handleFriend('conn-1', '1001', Message::create('friend:remove', ['targetUid' => "bad;uid\x80"], 'f-2'));

        $error = self::messagesOfType($h->frames(), 'friend:error');
        self::assertCount(2, $error);
        self::assertSame(400, $error[0]->payload['code']);
        self::assertSame(400, $error[1]->payload['code']);
    }

    public function testFriendAcceptCreatesBidirectionalFriendshipAndNotifiesApplicant(): void
    {
        $h = $this->buildHarness();
        $h->gateway->online['1001'] = true;
        $h->friend->apply('1001', '1002');

        // 1002 同意 1001 的申请：accept(applicant=1001, acceptor=1002)
        // 1002 accepts 1001's application: accept(applicant=1001, acceptor=1002)
        $h->service->handleFriend('conn-2', '1002', Message::create('friend:accept', ['targetUid' => '1001'], 'f-1'));

        $ok = self::messagesOfType($h->frames(), 'friend:ok');
        self::assertCount(1, $ok);
        self::assertSame('accept', $ok[0]->payload['action']);

        // 双向一致（Fake 与 Redis 实现同语义）
        // Bidirectional consistency (the fake shares the Redis implementation's semantics)
        self::assertSame(['1002'], $h->friend->list('1001'));
        self::assertSame(['1001'], $h->friend->list('1002'));

        // 申请方收到 accepted 通知
        // The applicant receives the accepted notification
        self::assertCount(1, $h->gateway->sendToUids);
        self::assertSame('1001', $h->gateway->sendToUids[0]['uid']);
        $notify = SocialServiceHarness::decode($h->gateway->sendToUids[0]['message']);
        self::assertSame('accepted', $notify->payload['type']);
        self::assertSame('1002', $notify->payload['fromUid']);
    }

    public function testFriendAcceptWithoutRequestMaps404(): void
    {
        $h = $this->buildHarness();

        $h->service->handleFriend('conn-1', '1002', Message::create('friend:accept', ['targetUid' => '1001'], 'f-1'));

        $error = self::messagesOfType($h->frames(), 'friend:error');
        self::assertCount(1, $error);
        self::assertSame(404, $error[0]->payload['code']);
        self::assertSame('request_not_found', $error[0]->payload['message']);
    }

    public function testFriendRejectRemovesRequest(): void
    {
        $h = $this->buildHarness();
        $h->friend->apply('1001', '1002');

        $h->service->handleFriend('conn-2', '1002', Message::create('friend:reject', ['targetUid' => '1001'], 'f-1'));

        $ok = self::messagesOfType($h->frames(), 'friend:ok');
        self::assertCount(1, $ok);
        self::assertSame([], $h->friend->list('1001'));
        self::assertSame([], $h->friend->list('1002'));
    }

    public function testFriendRemoveDeletesBothSidesAndNotifiesRemoved(): void
    {
        $h = $this->buildHarness();
        $h->gateway->online['1002'] = true;
        $h->friend->apply('1001', '1002');
        $h->friend->accept('1001', '1002');
        $h->gateway->sendToUids = [];

        $h->service->handleFriend('conn-1', '1001', Message::create('friend:remove', ['targetUid' => '1002'], 'f-1'));

        $ok = self::messagesOfType($h->frames(), 'friend:ok');
        self::assertCount(1, $ok);
        self::assertSame('remove', $ok[0]->payload['action']);
        self::assertSame([], $h->friend->list('1001'));
        self::assertSame([], $h->friend->list('1002'));

        // 被删方收到 removed 通知
        // The removed side receives the removed notification
        self::assertCount(1, $h->gateway->sendToUids);
        $notify = SocialServiceHarness::decode($h->gateway->sendToUids[0]['message']);
        self::assertSame('removed', $notify->payload['type']);
        self::assertSame('1001', $notify->payload['fromUid']);
    }

    public function testFriendRemoveNonFriendMaps404(): void
    {
        $h = $this->buildHarness();

        $h->service->handleFriend('conn-1', '1001', Message::create('friend:remove', ['targetUid' => '1002'], 'f-1'));

        $error = self::messagesOfType($h->frames(), 'friend:error');
        self::assertCount(1, $error);
        self::assertSame(404, $error[0]->payload['code']);
        self::assertSame('not_friends', $error[0]->payload['message']);
    }

    public function testFriendListReturnsSortedUids(): void
    {
        $h = $this->buildHarness();
        foreach (['u3', 'u2'] as $friend) {
            $h->friend->apply($friend, '1001');
            $h->friend->accept($friend, '1001');
        }

        $h->service->handleFriend('conn-1', '1001', Message::create('friend:list', [], 'f-1'));

        $ok = self::messagesOfType($h->frames(), 'friend:ok');
        self::assertCount(1, $ok);
        self::assertSame('list', $ok[0]->payload['action']);
        self::assertSame(['u2', 'u3'], $ok[0]->payload['uids']);
    }

    public function testFriendWithoutWiredStoreReplies500(): void
    {
        $h = $this->buildHarness(withFriend: false);

        $h->service->handleFriend('conn-1', '1001', Message::create('friend:list', [], 'f-1'));

        $error = self::messagesOfType($h->frames(), 'friend:error');
        self::assertCount(1, $error);
        self::assertSame(500, $error[0]->payload['code']);
    }

    public function testFriendOfflineNotificationIsSilentlyDroppedByHub(): void
    {
        // 真实 InMemoryConnectionHub 链路：目标无绑定连接 → sendToUid 静默丢弃，业务仍成功。
        // Real InMemoryConnectionHub path: the target has no bound connection → sendToUid drops silently, business still succeeds.
        $transport = new class () implements HubTransportInterface {
            /** @var list<array{clientId: string, type: string}> */
            public array $sent = [];

            public function sendToConnection(string $clientId, string $message): void
            {
                $decoded = json_decode($message, true);
                $this->sent[] = ['clientId' => $clientId, 'type' => is_array($decoded) ? (string) ($decoded['type'] ?? '') : ''];
            }

            public function close(string $clientId): void
            {
            }
        };
        $hub = new InMemoryConnectionHub($transport);
        $h = new SocialServiceHarness();
        $h->gateway = new FakeConnectionHub();
        $h->tokens = new FakeTokenManager();
        $h->registry = new FakeServiceRegistry();
        $h->authenticator = new FakeSocialAuthenticator();
        $h->location = new FakeLocationStore();
        $h->guild = new FakeGuildStore();
        $h->team = new FakeTeamStore();
        $h->friend = new FakeFriendStore();
        $h->service = new SocialService(
            $hub,
            $h->tokens,
            $h->registry,
            $h->authenticator,
            $h->location,
            $h->guild,
            $h->team,
            new JsonSerializer(),
            ['map-1'],
            [],
            $h->friend,
        );

        // 发起方在线（回执可达），目标离线（无绑定连接）
        // The initiator is online (receipts deliverable), the target offline (no bound connection)
        $hub->attachConnection('conn-1');
        $hub->bindUid('conn-1', '1001');

        $h->service->handleFriend('conn-1', '1001', Message::create('friend:apply', ['targetUid' => '1002-offline'], 'f-1'));

        // 业务成功；唯一下行是发起方的 friend:ok 回执——目标侧通知被 hub 静默丢弃
        // The business succeeds; the only downstream is the initiator's friend:ok receipt — the target-side notification was silently dropped by the hub
        self::assertSame([['clientId' => 'conn-1', 'type' => 'friend:ok']], $transport->sent);
    }

    public function testGuildCreateRepliesOkJoinsGroupAndWritesSession(): void
    {
        $h = $this->buildHarness();

        $h->service->handleGuild('conn-1', '1001', Message::create('guild:create', ['guildId' => 'guild-r3', 'name' => '名门', 'maxMembers' => 30], 'g-1'));

        $ok = self::messagesOfType($h->frames(), 'guild:ok');
        self::assertCount(1, $ok);
        self::assertSame('create', $ok[0]->payload['action']);
        self::assertSame('guild-r3', $ok[0]->payload['guildId']);
        self::assertContains('conn-1|guild:guild-r3', $h->gateway->joinGroups);
        self::assertSame(['guildId' => 'guild-r3'], $h->gateway->sessions['conn-1']);
        self::assertSame(GuildStoreInterface::ROLE_LEADER, $h->guild->roleOf('1001', 'guild-r3'));
    }

    public function testGuildCreateDuplicateGuildIdMaps409(): void
    {
        $h = $this->buildHarness();
        $h->guild->create('u9', 'guild-r3', null, 10);

        $h->service->handleGuild('conn-1', '1001', Message::create('guild:create', ['guildId' => 'guild-r3'], 'g-1'));

        $error = self::messagesOfType($h->frames(), 'guild:error');
        self::assertCount(1, $error);
        self::assertSame(409, $error[0]->payload['code']);
        self::assertSame('guild_exists', $error[0]->payload['message']);
    }

    /**
     * 服务层权限矩阵表驱动测试：会长/官员/成员 × 操作，断言 GuildStore 判定经 SocialService 的
     * guild:error 映射（HTTP code + message）与成功路径 guild:ok。
     * The service-layer table-driven permission matrix: leader/officer/member × operations, asserting the
     * GuildStore verdicts mapped through SocialService's guild:error (HTTP code + message) and success via guild:ok.
     */
    public function testGuildPermissionMatrixThroughServiceRouting(): void
    {
        // [操作者职位, 帧类型, 负载增量, 预期帧, 预期 code, 预期 message]
        // [operator role, frame type, payload delta, expected frame, expected code, expected message]
        $matrix = [
            ['leader', 'guild:disband', [], 'guild:ok', null, null],
            ['officer', 'guild:disband', [], 'guild:error', 403, 'permission_denied'],
            ['member', 'guild:disband', [], 'guild:error', 403, 'permission_denied'],
            ['leader', 'guild:kick', ['targetUid' => 'member'], 'guild:ok', null, null],
            ['member', 'guild:kick', ['targetUid' => 'member'], 'guild:error', 403, 'permission_denied'],
            ['leader', 'guild:promote', ['targetUid' => 'member', 'role' => 'officer'], 'guild:ok', null, null],
            ['officer', 'guild:promote', ['targetUid' => 'member', 'role' => 'officer'], 'guild:error', 403, 'permission_denied'],
            ['leader', 'guild:notice', ['notice' => '公告'], 'guild:ok', null, null],
            ['member', 'guild:notice', ['notice' => '公告'], 'guild:error', 403, 'permission_denied'],
            ['leader', 'guild:approve', ['targetUid' => 'applicant', 'accept' => true], 'guild:ok', null, null],
            ['member', 'guild:approve', ['targetUid' => 'applicant', 'accept' => true], 'guild:error', 403, 'permission_denied'],
        ];

        foreach ($matrix as [$role, $type, $extra, $expectedFrame, $expectedCode, $expectedMessage]) {
            $h = $this->buildHarness();
            $h->gateway->sessions['conn-' . $role] = ['uid' => $role];
            $h->guild->create('leader', 'guild-r3', null, 50);
            $h->guild->join('officer', 'guild-r3');
            $h->guild->promote('leader', 'officer', 'guild-r3', GuildStoreInterface::ROLE_OFFICER);
            $h->guild->join('member', 'guild-r3');
            if ($type === 'guild:approve') {
                $h->guild->apply('applicant', 'guild-r3');
            }

            $h->service->handleGuild(
                'conn-' . $role,
                $role,
                Message::create($type, ['guildId' => 'guild-r3', ...$extra], 'g-mx'),
            );

            $frames = self::messagesOfType($h->frames(), $expectedFrame);
            self::assertCount(1, $frames, sprintf('%s × %s 应得 %s', $role, $type, $expectedFrame));
            if ($expectedFrame === 'guild:error') {
                self::assertSame($expectedCode, $frames[0]->payload['code'], sprintf('%s × %s code 失配', $role, $type));
                self::assertSame($expectedMessage, $frames[0]->payload['message'], sprintf('%s × %s message 失配', $role, $type));
            }
        }
    }

    public function testGuildDisbandNotifiesGroupAndCleansUpAllMembers(): void
    {
        $h = $this->buildHarness();
        $h->gateway->sessions['conn-leader'] = ['uid' => 'leader', 'guildId' => 'guild-r3'];
        $h->gateway->sessions['conn-member'] = ['uid' => 'member', 'teamId' => 'team-9', 'guildId' => 'guild-r3'];
        $h->gateway->clientIdsByUid['leader'] = ['conn-leader'];
        $h->gateway->clientIdsByUid['member'] = ['conn-member'];
        $h->guild->create('leader', 'guild-r3', null, 50);
        $h->guild->join('member', 'guild-r3');

        $h->service->handleGuild('conn-leader', 'leader', Message::create('guild:disband', ['guildId' => 'guild-r3'], 'g-1'));

        // 全帮通知 disbanded
        // Whole-guild disbanded notification
        self::assertCount(1, $h->gateway->sendToGroups);
        self::assertSame('guild:guild-r3', $h->gateway->sendToGroups[0]['group']);
        $notify = SocialServiceHarness::decode($h->gateway->sendToGroups[0]['message']);
        self::assertSame('guild:notify', $notify->type);
        self::assertSame('disbanded', $notify->payload['type']);

        // 全员清场：退帮组 + 只清 session.guildId（不动 teamId）
        // Whole-membership cleanup: leave the guild group and clear only session.guildId (teamId untouched)
        self::assertContains('conn-leader|guild:guild-r3', $h->gateway->leaveGroups);
        self::assertContains('conn-member|guild:guild-r3', $h->gateway->leaveGroups);
        self::assertSame('team-9', $h->gateway->sessions['conn-member']['teamId']);
        self::assertNull($h->gateway->sessions['conn-member']['guildId']);
        self::assertNull($h->gateway->sessions['conn-leader']['guildId']);

        // 存储侧已解散
        // Store side disbanded
        self::assertNull($h->guild->get('guild-r3'));
    }

    public function testGuildKickCleansTargetConnectionsAndNotifies(): void
    {
        $h = $this->buildHarness();
        $h->gateway->online['member'] = true;
        $h->gateway->clientIdsByUid['member'] = ['conn-member'];
        $h->gateway->sessions['conn-member'] = ['uid' => 'member', 'guildId' => 'guild-r3'];
        $h->guild->create('leader', 'guild-r3', null, 50);
        $h->guild->join('member', 'guild-r3');

        $h->service->handleGuild('conn-leader', 'leader', Message::create('guild:kick', ['guildId' => 'guild-r3', 'targetUid' => 'member'], 'g-1'));

        $ok = self::messagesOfType($h->frames(), 'guild:ok');
        self::assertCount(1, $ok);
        self::assertContains('conn-member|guild:guild-r3', $h->gateway->leaveGroups);
        self::assertNull($h->gateway->sessions['conn-member']['guildId']);

        // 被踢者定向通知 kicked
        // The kicked member gets a directed kicked notification
        self::assertCount(1, $h->gateway->sendToUids);
        self::assertSame('member', $h->gateway->sendToUids[0]['uid']);
        $notify = SocialServiceHarness::decode($h->gateway->sendToUids[0]['message']);
        self::assertSame('kicked', $notify->payload['type']);
    }

    public function testGuildApproveAdmitsApplicantIntoGroupAndSession(): void
    {
        $h = $this->buildHarness();
        $h->gateway->online['applicant'] = true;
        $h->gateway->clientIdsByUid['applicant'] = ['conn-applicant'];
        $h->gateway->sessions['conn-applicant'] = ['uid' => 'applicant'];
        $h->guild->create('leader', 'guild-r3', null, 50);
        $h->guild->apply('applicant', 'guild-r3');

        $h->service->handleGuild('conn-leader', 'leader', Message::create('guild:approve', ['guildId' => 'guild-r3', 'targetUid' => 'applicant', 'accept' => true], 'g-1'));

        $ok = self::messagesOfType($h->frames(), 'guild:ok');
        self::assertCount(1, $ok);
        self::assertContains('conn-applicant|guild:guild-r3', $h->gateway->joinGroups);
        self::assertSame('guild-r3', $h->gateway->sessions['conn-applicant']['guildId']);
        self::assertSame(GuildStoreInterface::ROLE_MEMBER, $h->guild->roleOf('applicant', 'guild-r3'));

        $notify = SocialServiceHarness::decode($h->gateway->sendToUids[0]['message']);
        self::assertSame('approved', $notify->payload['type']);
    }

    public function testGuildNoticeBroadcastsToGuildGroup(): void
    {
        $h = $this->buildHarness();
        $h->guild->create('leader', 'guild-r3', null, 50);

        $h->service->handleGuild('conn-leader', 'leader', Message::create('guild:notice', ['guildId' => 'guild-r3', 'notice' => '今晚攻城'], 'g-1'));

        $ok = self::messagesOfType($h->frames(), 'guild:ok');
        self::assertCount(1, $ok);
        self::assertSame('今晚攻城', $h->guild->get('guild-r3')['notice']);
        self::assertCount(1, $h->gateway->sendToGroups);
        $notify = SocialServiceHarness::decode($h->gateway->sendToGroups[0]['message']);
        self::assertSame('notice', $notify->payload['type']);
        self::assertSame('今晚攻城', $notify->payload['notice']);
    }

    public function testTokenAuthValidConsumesRoleScopeAndRepliesUidOnly(): void
    {
        $h = $this->buildHarness();
        // gateway 完整握手签发的多 scope token（ADR-021 §3.2）：chat 角色消费 'chat' scope
        // The multi-scope token issued by gateway's full handshake (ADR-021 §3.2): the chat role consumes the 'chat' scope
        $h->tokens->records['token-a'] = new TokenRecord('1001', 'map-1', ['map', 'chat', 'team'], 0.0, 999.0);

        $h->service->handleTokenAuth('conn-1', Message::create('auth', ['token' => 'token-a'], 'auth-t1'), 'chat');

        // auth_ok 仅 uid：凭证已在 gateway 签发，此处只消费不再签发
        // auth_ok carries the uid only: the credential was issued at gateway, here it is consumed, never re-issued
        $ok = self::messagesOfType($h->frames(), 'auth_ok');
        self::assertCount(1, $ok);
        self::assertSame('1001', $ok[0]->payload['uid']);
        self::assertArrayNotHasKey('token', $ok[0]->payload);

        // consume 精确落在本角色 scope；不触发 issue、不写位置快照、不 joinGroup
        // consume lands exactly on this role's scope; no issue, no location snapshot, no group joins
        self::assertSame([['token' => 'token-a', 'scope' => 'chat']], $h->tokens->consumeCalls);
        self::assertCount(0, $h->tokens->issueCalls);
        self::assertCount(0, $h->location->saves);
        self::assertCount(0, $h->gateway->joinGroups);

        // hub 登记：bindUid + session（仅 uid）
        // Hub registration: bindUid + session (uid only)
        self::assertSame(['conn-1|1001'], $h->gateway->binds);
        self::assertCount(1, $h->gateway->setSessions);
        self::assertSame(['uid' => '1001'], $h->gateway->setSessions[0]['session']);
    }

    public function testTokenAuthTeamScopeConsumedIndependentlyOfChat(): void
    {
        $h = $this->buildHarness();
        $h->tokens->records['token-a'] = new TokenRecord('1002', 'map-1', ['map', 'chat', 'team'], 0.0, 999.0);

        // 同一 token 先后消费 chat 与 team scope（per-scope 墓碑互不干扰，均 Valid）
        // The same token consumes the chat then the team scope (per-scope tombstones are independent; both Valid)
        $h->service->handleTokenAuth('conn-1', Message::create('auth', ['token' => 'token-a'], 'auth-t1'), 'chat');
        $h->service->handleTokenAuth('conn-2', Message::create('auth', ['token' => 'token-a'], 'auth-t2'), 'team');

        $ok = self::messagesOfType($h->frames(), 'auth_ok');
        self::assertCount(2, $ok);
        self::assertSame('1002', $ok[0]->payload['uid']);
        self::assertSame('1002', $ok[1]->payload['uid']);
        self::assertSame(
            [['token' => 'token-a', 'scope' => 'chat'], ['token' => 'token-a', 'scope' => 'team']],
            $h->tokens->consumeCalls,
        );
    }

    public function testTokenAuthUnknownTokenReplies403InvalidAndCloses(): void
    {
        $h = $this->buildHarness();

        // peek 不可见（不存在）→ consume 归因兜底 invalid → 403 + 断开
        // peek invisible (absent) → consume attributes the fallback invalid verdict → 403 + close
        $h->service->handleTokenAuth('conn-1', Message::create('auth', ['token' => str_repeat('f', 64)], 'auth-t1'), 'chat');

        $failed = self::messagesOfType($h->frames(), 'auth_failed');
        self::assertCount(1, $failed);
        self::assertSame(403, $failed[0]->payload['code']);
        self::assertSame('invalid', $failed[0]->payload['reason']);
        self::assertSame(['conn-1'], $h->gateway->closes);
        self::assertCount(0, $h->gateway->binds);
    }

    public function testTokenAuthExpiredTokenRepliesExpiredAndCloses(): void
    {
        $h = $this->buildHarness();
        // 已过期 token：peek 不可见（过期即 null），consume 归因 Expired
        // An expired token: peek is invisible (expired reads as null), consume attributes Expired
        $h->tokens->consumeResults[str_repeat('e', 64)] = TokenStatus::Expired;

        $h->service->handleTokenAuth('conn-1', Message::create('auth', ['token' => str_repeat('e', 64)], 'auth-t1'), 'team');

        $failed = self::messagesOfType($h->frames(), 'auth_failed');
        self::assertCount(1, $failed);
        self::assertSame(403, $failed[0]->payload['code']);
        self::assertSame('expired', $failed[0]->payload['reason']);
        self::assertSame(['conn-1'], $h->gateway->closes);
    }

    public function testTokenAuthScopeMismatchRepliesUnauthorizedWithoutConsuming(): void
    {
        $h = $this->buildHarness();
        // token 有效但未授权该 scope（如旧格式只授 'map'）：Unauthorized 不写墓碑
        // A valid token without this scope's authorization (e.g. a legacy record granting only 'map'): Unauthorized writes no tombstone
        $h->tokens->records['token-legacy'] = new TokenRecord('1001', 'map-1', ['map'], 0.0, 999.0);
        $h->tokens->consumeResults['token-legacy'] = TokenStatus::Unauthorized;

        $h->service->handleTokenAuth('conn-1', Message::create('auth', ['token' => 'token-legacy'], 'auth-t1'), 'chat');

        $failed = self::messagesOfType($h->frames(), 'auth_failed');
        self::assertCount(1, $failed);
        self::assertSame(403, $failed[0]->payload['code']);
        self::assertSame('unauthorized', $failed[0]->payload['reason']);
        self::assertSame(['conn-1'], $h->gateway->closes);
        self::assertCount(0, $h->gateway->binds);
    }

    public function testTokenAuthReplayedTombstoneRejectedAndCloses(): void
    {
        $h = $this->buildHarness();
        // 该 scope 墓碑期内二次使用：peek 仍可见（不感知墓碑），consume 判 Replayed
        // A second use within the scope's tombstone window: peek still sees the record (tombstone-invisible), consume verdicts Replayed
        $h->tokens->records['token-used'] = new TokenRecord('1001', 'map-1', ['map', 'chat', 'team'], 0.0, 999.0);
        $h->tokens->consumeResults['token-used'] = TokenStatus::Replayed;

        $h->service->handleTokenAuth('conn-1', Message::create('auth', ['token' => 'token-used'], 'auth-t1'), 'chat');

        $failed = self::messagesOfType($h->frames(), 'auth_failed');
        self::assertCount(1, $failed);
        self::assertSame(403, $failed[0]->payload['code']);
        self::assertSame('replayed', $failed[0]->payload['reason']);
        self::assertSame(['conn-1'], $h->gateway->closes);
        self::assertCount(0, $h->gateway->binds);
    }

    public function testTokenAuthMissingTokenFieldReplies400AndCloses(): void
    {
        $h = $this->buildHarness();

        $h->service->handleTokenAuth('conn-1', Message::create('auth', [], 'auth-t1'), 'chat');

        $failed = self::messagesOfType($h->frames(), 'auth_failed');
        self::assertCount(1, $failed);
        self::assertSame(400, $failed[0]->payload['code']);
        self::assertSame(['conn-1'], $h->gateway->closes);
        self::assertCount(0, $h->tokens->consumeCalls);
    }

    /**
     * 组装 SocialService 测试依赖并返回线束。
     * Builds the SocialService dependency stack and returns the harness.
     *
     * @param array<string, string> $endpointAddresses chat/team 对外 ws 地址（缺省空 = auth_ok 不含 endpoints） Public chat/team ws addresses (default empty = no endpoints in auth_ok).
     * @param bool $withFriend 是否装配 FakeFriendStore（缺省装配；false = friend:* 路由 500 分支用） Whether to wire the FakeFriendStore (wired by default; false exercises the friend:* 500 branch).
     */
    private function buildHarness(array $mapIds = ['map-1', 'map-2'], array $endpointAddresses = [], bool $withFriend = true): SocialServiceHarness
    {
        $h = new SocialServiceHarness();
        $h->gateway = new FakeConnectionHub();
        $h->tokens = new FakeTokenManager();
        $h->registry = new FakeServiceRegistry();
        $h->authenticator = new FakeSocialAuthenticator();
        $h->location = new FakeLocationStore();
        $h->guild = new FakeGuildStore();
        $h->team = new FakeTeamStore();
        $h->friend = $withFriend ? new FakeFriendStore() : null;

        $h->service = new SocialService(
            $h->gateway,
            $h->tokens,
            $h->registry,
            $h->authenticator,
            $h->location,
            $h->guild,
            $h->team,
            new JsonSerializer(),
            $mapIds,
            $endpointAddresses,
            $h->friend,
        );

        return $h;
    }

    /**
     * 按消息类型过滤并返回全部匹配消息。
     * Filters messages by type and returns all matches.
     *
     * @param list<Message> $messages 已解码消息列表 Decoded messages.
     * @param string $type 目标消息类型 Target message type.
     * @return list<Message> 匹配消息列表 Matching messages.
     */
    private static function messagesOfType(array $messages, string $type): array
    {
        return array_values(array_filter(
            $messages,
            static fn (Message $message): bool => $message->type === $type,
        ));
    }
}

/**
 * SocialServiceTest 测试线束：集中存放 fakes 与 SocialService，及帧解码工具。
 * Test harness for SocialServiceTest: holds the fakes and the SocialService, plus the frame-decode helpers.
 */
final class SocialServiceHarness
{
    public FakeConnectionHub $gateway;
    public FakeTokenManager $tokens;
    public FakeServiceRegistry $registry;
    public FakeSocialAuthenticator $authenticator;
    public FakeLocationStore $location;
    public FakeGuildStore $guild;
    public FakeTeamStore $team;
    public ?FakeFriendStore $friend;
    public SocialService $service;

    /**
     * 经 sendToClient 发送的帧解码为消息列表（走真实 JsonSerializer 链路）。
     * Decode the frames sent via sendToClient into messages (via the real JsonSerializer path).
     *
     * @return list<Message> 已解码消息 Decoded messages.
     */
    public function frames(): array
    {
        $serializer = new JsonSerializer();

        return array_map(
            static fn (string $frame): Message => $serializer->decode(new Frame($frame)),
            $this->gateway->sendToClients,
        );
    }

    /**
     * 解码单帧字节为消息。
     * Decode a single frame into a message.
     */
    public static function decode(string $frame): Message
    {
        return (new JsonSerializer())->decode(new Frame($frame));
    }
}
