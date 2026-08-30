<?php

declare(strict_types=1);

namespace Nythros\Framework\Tests\Deploy;

use Nythros\Framework\Deploy\DeployConfig;
use Nythros\Framework\Deploy\DeployWorker;
use PHPUnit\Framework\TestCase;

/**
 * DeployConfigTest - deploy.yaml 解析纯函数测试（组 9）：拓扑解析、worker 展开、命令构建。
 * ADR-021 后 SERVICE_TYPES 白名单恢复为 ['gateway','chat','team','map']，processes 为必填段。
 * 覆盖：9.1 契约结构解析（含 ADR 3.1 流式与 9.1 块式两种列表写法）、进程→服务→端口展开顺序、map serviceId 编码、
 * mapIds 白名单导出、count 实例展开、buildCommand 纯函数与解析失败矩阵（行号归因）、社交三角色声明。
 * DeployConfigTest - pure-function tests for the deploy.yaml parser (group 9): topology parsing, worker expansion and command building.
 * After ADR-021 the SERVICE_TYPES whitelist is restored to ['gateway','chat','team','map'] with processes as a required section.
 * Covers: the 9.1 contract parsing (both the ADR 3.1 flow-style and 9.1 block-style list forms), process→service→port expansion order,
 * the map serviceId encoding, mapIds whitelist export, count instance expansion, buildCommand purity, the parse-failure matrix
 * (line-number attribution) and the social-trio declarations.
 */
final class DeployConfigTest extends TestCase
{
    /** 完整契约拓扑（块式列表写法）：map-1 两频道 + map-2 一频道 The full contract topology (block-style list form): map-1 with two channels + map-2 with one channel. */
    private const MAPS_YAML = <<<'YAML'
# 注释与空行应被忽略 Comments and blank lines must be ignored
redis:
  host: 127.0.0.1
  port: 6379

processes:
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
    - type: map
      mapId: map-1
      channelId: ch-2
      port: 18082
  map-2:
    - type: map
      mapId: map-2
      channelId: ch-1
      port: 18083
YAML;

    public function testParsesRedisAndProcessTopology(): void
    {
        $config = DeployConfig::parseYaml(self::MAPS_YAML);

        // redis 连接参数 Redis connection parameters
        self::assertSame(['host' => '127.0.0.1', 'port' => 6379], $config->redis());

        // 两个部署单元：map-1 2 服务、map-2 1 服务
        // Two deployment units: map-1 with 2 services, map-2 with 1
        $processes = $config->processes();
        self::assertSame(['map-1', 'map-2'], array_keys($processes));
        self::assertCount(2, $processes['map-1']);
        self::assertCount(1, $processes['map-2']);

        // service 字段展开正确 service fields expand correctly
        self::assertSame('map', $processes['map-1'][0]->type);
        self::assertSame(18081, $processes['map-1'][0]->port);
        self::assertSame('map-1', $processes['map-1'][0]->mapId);
        self::assertSame('ch-1', $processes['map-1'][0]->channelId);
    }

    public function testWorkersExpandInDeclarationOrder(): void
    {
        $config = DeployConfig::parseYaml(self::MAPS_YAML);

        // 展开顺序 = process 声明顺序 × service 声明顺序；count 缺省 1
        // Expansion order = process declaration order × service declaration order; count defaults to 1
        $workers = $config->workers();
        self::assertCount(3, $workers);

        $expected = [
            ['map-1', 'map', 18081],
            ['map-1', 'map', 18082],
            ['map-2', 'map', 18083],
        ];
        foreach ($expected as $idx => [$process, $type, $port]) {
            self::assertSame($process, $workers[$idx]->process);
            self::assertSame($type, $workers[$idx]->service->type);
            self::assertSame($port, $workers[$idx]->service->port);
            self::assertSame(1, $workers[$idx]->instance);
        }
    }

    public function testMapWorkerCarriesServiceIdEncoding(): void
    {
        $config = DeployConfig::parseYaml(self::MAPS_YAML);

        // map 的 serviceId = {mapId}#{channelId}（ADR 5.1）
        // A map's serviceId = {mapId}#{channelId} (ADR 5.1)
        $serviceIds = [];
        foreach ($config->workers() as $worker) {
            $serviceIds[] = $worker->service->serviceId();
        }
        self::assertSame(['map-1#ch-1', 'map-1#ch-2', 'map-2#ch-1'], $serviceIds);
    }

    public function testMapIdsWhitelistExportedInTopologyOrder(): void
    {
        $config = DeployConfig::parseYaml(self::MAPS_YAML);

        // 合法 mapId 白名单 = 拓扑内 map 服务 mapId 去重保序（拓扑即白名单，无需单独声明）
        // The allowed mapId whitelist = deduplicated mapIds of map services in topology order (the topology is the whitelist, no separate declaration)
        self::assertSame(['map-1', 'map-2'], $config->mapIds());
    }

    public function testCountFieldExpandsMultipleInstances(): void
    {
        $config = DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
processes:
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
      count: 3
    - type: map
      mapId: map-1
      channelId: ch-2
      port: 18082
YAML);

        $workers = $config->workers();

        // count 展开：map-1#ch-1 ×3 + map-1#ch-2 ×1，实例序号 1 起
        // count expansion: map-1#ch-1 ×3 + map-1#ch-2 ×1, instance ordinals starting at 1
        self::assertCount(4, $workers);
        self::assertSame([1, 2, 3, 1], array_map(static fn (DeployWorker $w): int => $w->instance, $workers));
    }

    public function testBuildCommandForMapIncludesMapIdChannelId(): void
    {
        $config = DeployConfig::parseYaml(self::MAPS_YAML);
        $map = $config->workers()[0];

        $command = DeployConfig::buildCommand($map, 'run-worker.php', $config->redis());

        self::assertSame([
            PHP_BINARY,
            'run-worker.php',
            '--service=map',
            '--port=18081',
            '--mapId=map-1',
            '--channelId=ch-1',
            '--redisHost=127.0.0.1',
            '--redisPort=6379',
        ], $command);
    }

    public function testMysqlSectionParsedWithDefaultsWhenAbsent(): void
    {
        // 未声明 mysql 段：缺省 127.0.0.1:3306 / root / 空密码 / nythros（与 run-worker CLI 缺省一致）
        // Without a declared mysql section: defaults 127.0.0.1:3306 / root / empty password / nythros (matching run-worker's CLI defaults)
        $config = DeployConfig::parseYaml(self::MAPS_YAML);

        self::assertSame([
            'host' => '127.0.0.1',
            'port' => 3306,
            'user' => 'root',
            'password' => '',
            'dbname' => 'nythros',
        ], $config->mysql());
    }

    public function testMysqlSectionParsedAndPassedThroughBuildCommand(): void
    {
        // 声明 mysql 段：解析出完整连接参数，buildCommand 透传 5 个 --mysql* 参数
        // A declared mysql section: full connection parameters parsed, buildCommand passes the five --mysql* flags through
        $config = DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
mysql:
  host: db.internal
  port: 3307
  user: archive
  password: "s3cret"
  dbname: game
processes:
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
YAML);

        self::assertSame([
            'host' => 'db.internal',
            'port' => 3307,
            'user' => 'archive',
            'password' => 's3cret',
            'dbname' => 'game',
        ], $config->mysql());

        $command = DeployConfig::buildCommand($config->workers()[0], 'run-worker.php', $config->redis(), $config->mysql());
        self::assertContains('--mysqlHost=db.internal', $command);
        self::assertContains('--mysqlPort=3307', $command);
        self::assertContains('--mysqlUser=archive', $command);
        self::assertContains('--mysqlPass=s3cret', $command);
        self::assertContains('--mysqlDb=game', $command);
    }

    public function testMysqlSectionUnknownKeyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mysql 段含未知键 "db"');
        DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
mysql:
  host: 127.0.0.1
  port: 3306
  db: nythros
processes:
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
YAML);
    }

    public function testMysqlSectionInvalidPortRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mysql.port 必须是 1~65535 的整数');
        DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
mysql:
  host: 127.0.0.1
  port: 0
processes:
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
YAML);
    }

    public function testMysqlSectionMissingDbnameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mysql.dbname 必须是非空字符串');
        DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
mysql:
  host: 127.0.0.1
  port: 3306
  user: root
  password: ""
processes:
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
YAML);
    }

    public function testPidFileParsedAndPassedThroughBuildCommand(): void
    {
        // G-5：service 可显式声明 pidFile → buildCommand 透传 --pidFile（覆盖 run-worker 的 type+port 缺省）
        // G-5: a service may declare pidFile explicitly → buildCommand passes --pidFile through (overriding run-worker's type+port default)
        $config = DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
processes:
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
      pidFile: /tmp/nythros-map-1.pid
    - type: map
      mapId: map-1
      channelId: ch-2
      port: 18082
YAML);

        $workers = $config->workers();
        self::assertSame('/tmp/nythros-map-1.pid', $workers[0]->service->pidFile);
        self::assertNull($workers[1]->service->pidFile);

        $pidCommand = DeployConfig::buildCommand($workers[0], 'run-worker.php', $config->redis());
        self::assertContains('--pidFile=/tmp/nythros-map-1.pid', $pidCommand);

        // 未声明 pidFile 的 service：命令不含 --pidFile（run-worker 按 type+port 生成缺省）
        // A service without a declared pidFile: no --pidFile in the command (run-worker generates the type+port default)
        $mapCommand = DeployConfig::buildCommand($workers[1], 'run-worker.php', $config->redis());
        self::assertNotContains('--pidFile=', $mapCommand);
    }

    public function testEmptyPidFileRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pidFile 必须是非空字符串');

        DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
processes:
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
      pidFile: ""
YAML);
    }

    public function testDuplicatePidFileRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pidFile "/tmp/same.pid" 重复声明');

        DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
processes:
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
      pidFile: /tmp/same.pid
    - type: map
      mapId: map-1
      channelId: ch-2
      port: 18082
      pidFile: /tmp/same.pid
YAML);
    }

    public function testFlowStyleServiceEntriesSupported(): void
    {
        // ADR 3.1 示例风格（流式映射 `- {type: ..., port: ...}`）与 9.1 块式等价
        // The ADR 3.1 example style (flow maps `- {type: ..., port: ...}`) is equivalent to the 9.1 block style
        $config = DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
processes:
  map-1:
    - {type: map, mapId: map-1, channelId: ch-1, count: 1, port: 18081}
    - {type: map, mapId: map-1, channelId: ch-2, count: 1, port: 18082}
YAML);

        $workers = $config->workers();
        self::assertCount(2, $workers);
        self::assertSame([18081, 18082], array_map(static fn (DeployWorker $w): int => $w->service->port, $workers));
        self::assertSame('map-1#ch-1', $workers[0]->service->serviceId());
        self::assertSame('map-1#ch-2', $workers[1]->service->serviceId());
    }

    public function testQuotedScalarsAndInlineCommentsSupported(): void
    {
        $config = DeployConfig::parseYaml(<<<'YAML'
redis:
  host: "127.0.0.1"     # 引号字符串 + 行内注释 quoted string + inline comment
  port: 6379            # 行内注释 inline comment
processes:              # 注释 comments
  map-1:
    - type: map         # 注释 comments
      mapId: map-1
      channelId: ch-1
      port: 18081
YAML);

        self::assertSame(['host' => '127.0.0.1', 'port' => 6379], $config->redis());
        self::assertCount(1, $config->workers());
    }

    public function testUnknownTopLevelKeyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未知顶层键 "redisx"');
        DeployConfig::parseYaml("redisx:\n  host: 127.0.0.1\nprocesses:\n  map-1:\n    - type: map\n      mapId: map-1\n      channelId: ch-1\n      port: 18081\n");
    }

    public function testMissingRedisPortRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('redis.port 必须是 1~65535 的整数');
        DeployConfig::parseYaml("redis:\n  host: 127.0.0.1\nprocesses:\n  map-1:\n    - type: map\n      mapId: map-1\n      channelId: ch-1\n      port: 18081\n");
    }

    public function testUnknownServiceTypeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type 必须是 gateway/chat/team/map 之一');
        DeployConfig::parseYaml("redis:\n  host: 127.0.0.1\n  port: 6379\nprocesses:\n  map-1:\n    - type: voice\n      port: 18081\n");
    }

    public function testMapServiceRequiresMapIdAndChannelId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('必须声明非空 mapId 与 channelId');
        DeployConfig::parseYaml("redis:\n  host: 127.0.0.1\n  port: 6379\nprocesses:\n  map-1:\n    - type: map\n      mapId: map-1\n      port: 18081\n");
    }

    public function testDuplicatePortRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('端口 18081 重复声明');
        DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
processes:
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
  map-2:
    - type: map
      mapId: map-2
      channelId: ch-1
      port: 18081
YAML);
    }

    public function testDuplicateMapServiceIdRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('map 实例 "map-1#ch-1" 重复声明');
        DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
processes:
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
  map-1-bis:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18082
YAML);
    }

    public function testEmptyYamlRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('为空或仅含注释');
        DeployConfig::parseYaml("# 只有注释 only comments\n\n");
    }

    public function testTabIndentationRejectedWithLineNumber(): void
    {
        // 错误消息带行号归因（第 2 行含 tab 缩进） Error messages carry line-number attribution (line 2 has tab indentation)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('第 2 行');
        $this->expectExceptionMessage('tab');
        DeployConfig::parseYaml("redis:\n\thost: 127.0.0.1\nprocesses:\n  map-1:\n    - type: map\n      mapId: map-1\n      channelId: ch-1\n      port: 18081\n");
    }

    public function testSocialTrioParsedAndExpanded(): void
    {
        // ADR-021：social 部署单元声明 gateway/chat/team 三服务（无 mapId/channelId 要求），与 map 单元并存
        // ADR-021: a social deployment unit declares the gateway/chat/team trio (no mapId/channelId required), coexisting with map units
        $config = DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
processes:
  social:
    - type: gateway
      port: 18285
    - type: chat
      port: 18286
    - type: team
      port: 18287
  map-1:
    - type: map
      mapId: map-1
      channelId: ch-1
      port: 18081
YAML);

        $workers = $config->workers();
        self::assertCount(4, $workers);
        self::assertSame(['gateway', 'chat', 'team', 'map'], array_map(static fn (DeployWorker $w): string => $w->service->type, $workers));
        self::assertSame([18285, 18286, 18287, 18081], array_map(static fn (DeployWorker $w): int => $w->service->port, $workers));

        // 社交三角色无 serviceId（注册表身份由运行时自持，不属于部署拓扑契约）
        // The social trio carries no serviceId (registry identities are runtime-held, outside the topology contract)
        self::assertNull($workers[0]->service->serviceId());
        self::assertSame('map-1#ch-1', $workers[3]->service->serviceId());

        // mapIds 白名单只含 map 服务
        // The mapIds whitelist covers map services only
        self::assertSame(['map-1'], $config->mapIds());
    }

    public function testBuildCommandForSocialRoleOmitsMapArguments(): void
    {
        // 非 map 类型不追加 mapId/channelId/worldType 参数（buildCommand 泛化）
        // Non-map types append no mapId/channelId/worldType arguments (buildCommand generalization)
        $config = DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
processes:
  social:
    - type: chat
      port: 18286
YAML);

        $command = DeployConfig::buildCommand($config->workers()[0], 'run-worker.php', $config->redis());

        self::assertSame([
            PHP_BINARY,
            'run-worker.php',
            '--service=chat',
            '--port=18286',
            '--redisHost=127.0.0.1',
            '--redisPort=6379',
        ], $command);
    }

    public function testNonMapTypeSilentlyAcceptsDeclaredMapId(): void
    {
        // 行为锁定（buildService 现行为）：mapId/channelId 校验分流只对 type=map 强制；非 map 类型声明
        // mapId/channelId 不报错、原样透传进 DeployService（serviceId 编码随之生效）。
        // Behavior lock (current buildService behavior): the mapId/channelId validation split is enforced for type=map
        // only; a non-map type declaring them neither fails nor strips — values pass through into DeployService (and the
        // serviceId encoding kicks in accordingly).
        $config = DeployConfig::parseYaml(<<<'YAML'
redis:
  host: 127.0.0.1
  port: 6379
processes:
  social:
    - type: chat
      port: 18286
      mapId: ghost-map
      channelId: ghost-ch
YAML);

        $service = $config->workers()[0]->service;
        self::assertSame('chat', $service->type);
        self::assertSame('ghost-map', $service->mapId);
        self::assertSame('ghost-ch', $service->channelId);
        self::assertSame('ghost-map#ghost-ch', $service->serviceId());
    }

    public function testGatewayTopLevelKeyRejected(): void
    {
        // ADR-021：gateway 顶层段退役——白名单外顶层键一律拒绝
        // ADR-021: the top-level gateway section is retired — unknown top-level keys are always rejected
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未知顶层键 "gateway"');
        DeployConfig::parseYaml("redis:\n  host: 127.0.0.1\n  port: 6379\ngateway:\n  gateway_port: 19000\nprocesses:\n  map-1:\n    - type: map\n      mapId: map-1\n      channelId: ch-1\n      port: 18081\n");
    }

    public function testMissingProcessesSectionRejected(): void
    {
        // processes 必填：缺失即拒绝（ADR-021 后拓扑唯一事实源收敛于 processes）
        // processes is required: absence is rejected (after ADR-021 the single topology source converges on processes)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('缺少 processes 段');
        DeployConfig::parseYaml("redis:\n  host: 127.0.0.1\n  port: 6379\n");
    }

    public function testEmptyProcessesSectionRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('缺少 processes 段');
        DeployConfig::parseYaml("redis:\n  host: 127.0.0.1\n  port: 6379\nprocesses: {}\n");
    }
}
