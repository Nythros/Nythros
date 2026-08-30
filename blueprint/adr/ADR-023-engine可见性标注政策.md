# ADR-023：engine 可见性标注政策

- 状态：已裁决（2026-08-24）
- 关联：ADR-019（engine 物理合并）、ADR-020（三层产品定位与分层铁律）
- 起因：reviewer 对 R1 三提交发出 ARCHITECTURE_REVIEW_REQUIRED——architecture.md §2/§3 声称「实现类一律 @internal；protocol 协议层为公开例外」，但 `packages/engine/src` 全目录（66 文件）无一处 `@internal` 标注，口径未落地代码，framework 对 engine 实现类的 import 无法机械判定违规。

## 盘点现实（2026-08-24 Grep 实测）

### framework/src 引擎 import：23 处 / 9 文件

实现类 import（8 处）：

| 类 | 位置 | 性质 |
|---|---|---|
| `Actor\BaseActor` ×3 | BasePlayer.php:7、BaseMonster.php:8、BaseNPC.php:7 | 抽象基类，四基类继承目标 |
| `Kernel\PerfProbe` ×1 | Observability\PerfSampler.php:7 | final 具体类，内部观测设施 |
| `Cluster\ServiceInstance` ×1 | Social\SocialService.php:7 | final readonly 寻址值对象 |
| `Entity\Position` ×1 | Server\RealtimeServer.php:14 | final 空间坐标值对象 |
| 异常 ×3 | AuthenticationException（SocialService.php:11）、ConnectionClosedException（RealtimeServer.php:15）、DecodeException（RealtimeServer.php:19） | 接口契约异常 |

非 Contracts 接口 import（9 处）：Security\{IdentityInterface, AuthenticatorInterface, TokenManagerInterface}、Persistence\StorageInterface、Cluster\ServiceRegistryInterface、Network\{ConnectionInterface, ServerInterface}、Protocol\{SerializerInterface, BatchSerializerInterface}。其余为 protocol 公开面（Message 等）。

### demo/src 引擎 import：34 处 / 6 文件（全部属合法组装）

MapChannelFactory(14)、MapServer(9)、SocialServer(4)、StaticAuthenticator(3)、Protocol\MapCodec(2)、WorkermanHubTransport(2)；覆盖 SimpleActorSystem/GridAOI/UniversalAOI/RedisServiceRegistry/SimpleEventBus/WorkermanClock/WorkermanTimer/WorkermanWebSocketServer/MySqlStorage/BinaryBatchSerializer/RegionScheduler/SimpleEntityManager/World/BaseEntity/Position/TokenStatus 等具体实现。bin/*.php 另有 19 处同类组装（验证脚本，同豁免）。

### protocol 层实际构成：11 类

接口 3（FrameInterface/SerializerInterface/BatchSerializerInterface）+ 值对象 2（Frame/Message）+ 词汇表 1（ProtocolVocabulary）+ 异常 2（ProtocolException/DecodeException）+ 具体序列化器 3（JsonSerializer/JsonBatchSerializer/BinaryBatchSerializer）。

## 裁决

### D1 @internal 标注范围

原则：engine 内除下列公开面外，一切 class/trait/enum 标类级 `@internal`；**接口（`*Interface` 后缀）一律公开，无论所在 namespace**。ADR-019 合并后接口散布于各 namespace，「Contracts 唯一出口」修正为「Contracts 为推荐收拢地，接口即公开面」；物理收拢不做承诺，另案。

公开例外白名单（10 个非接口类 + 全部接口 + Contracts 全部）：

1. Contracts 命名空间全部（既定）。
2. 全部接口类型（含上述 9 个非 Contracts 接口）——依赖倒置接缝，framework 合法消费。
3. `Actor\BaseActor`——framework 四基类的文档化继承目标，模板方法即公开 API。
4. protocol 公开面 8 类：Frame/Message/ProtocolVocabulary/三接口/两异常；三个具体序列化器标 @internal（替换点走 SerializerInterface/BatchSerializerInterface）。
5. `Entity\Position`——空间坐标值对象，framework/demo 广泛消费，语义稳定，等同协议词汇。
6. `Cluster\ServiceInstance`——服务寻址返回值对象，纯数据无实现泄漏。
7. 契约异常随接口公开：Security\AuthenticationException（AuthenticatorInterface 契约）、Network\ConnectionClosedException（ConnectionInterface 契约）——调用方必须 catch，@internal 化不现实。

枚举（如 TokenStatus）默认 @internal，framework 若需消费再扩白名单。标注形式：类级 docComment `@internal`；白名单类不加标注即为公开。

### D2 存量违规处置

framework import 中不属于白名单的仅 1 类：`Kernel\PerfProbe`（Observability\PerfSampler.php:7）。

- 裁决：不入白名单。PerfProbe 是引擎内部观测设施，framework 不应直依。
- 去向（**R3 framework Core 扩容批待办，非立即执行**）：Contracts 新增只读采样快照接口（如 `PerfProbeInterface::snapshot()`），PerfSampler 改依赖该接口，PerfProbe 由 starter-kit 注入适配。
- Position/ServiceInstance 经 D1 白名单化，存量 import 即刻合规，零代码改动。

### D3 机械化门禁路径

- 首选：phpstan 自定义规则——遍历 UseStatement 并反射目标类 docComment，命中 `@internal` 且调用方命名空间非 `Nythros\Demo\*` 即报错；豁免以命名空间前缀判定（starter-kit 唯一组装点）。
- 兜底：CI grep 策略——脚本解析 engine/src 声明生成「公开符号清单」，扫描 framework/src 的 use 语句对照清单，越界即 fail。
- 节奏：R2 先落 grep 门禁防回填，phpstan 规则 R5 工程纵深批精化。

### D4 实施批次归属

- bulk `@internal` 标注（engine 全部非公开面文件 docComment 追加）：**R2 引擎补强批**，独立提交，先于 R2 能力开发合入。
- grep 门禁：R2 同批落地。
- PerfProbe 解耦：R3 framework Core 扩容批。
- 接口物理收拢 Contracts：不排期，另案 ADR。

## 后果

- reviewer 可机械判定：framework import 白名单外实现类即违规；demo/src 与 bin 全豁免。
- engine 公开面收敛为「Contracts + 全部接口 + 白名单 10 类」，API 面缩小且文档化。
- 新增 engine 类默认 @internal；扩公开面须修订本 ADR 白名单并过 reviewer。

修订：R2 审查 MAJOR-2，TokenStatus 白名单公开化（纯五态值枚举，framework 消费需要）。
