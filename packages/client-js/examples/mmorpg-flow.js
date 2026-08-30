/**
 * SDK 验收示例：mmorpg E2E 同款登录/移动/攻击流（P12 批验收口径）。
 * SDK acceptance example: the mmorpg E2E's same login/move/attack flow (the P12 acceptance criterion).
 *
 * 前置：服务器已启动（Redis 可用；玩法批建议开启——示例演示 quest:list 回执模式）：
 * Prerequisites: the server is up (Redis reachable; the gameplay batch is recommended — the example
 * demonstrates the quest:list receipt pattern):
 *
 *   cd /path/to/Nythros && NYTHROS_MMORPG=1 NYTHROS_GAMEPLAY=1 php bin/server start
 *
 * 运行（Node ≥ 22，原生 WebSocket）：
 * Run (Node >= 22, native WebSocket):
 *
 *   node packages/client-js/examples/mmorpg-flow.js
 *
 * 流程（对应 verify-mmorpg step0/1 的精简版）：
 * Flow (a condensed version of verify-mmorpg's step0/1):
 *   ① 登录链路 gateway → token → Map 直连（auth_ok 拿 entityId）
 *     The login chain gateway -> token -> Map direct (entityId from auth_ok)
 *   ② 避险 move 到 (100,100)（火发无回执，靠 entity_moved 世界帧确认——move 路由只回错误帧）
 *     Evasive move to (100,100) (fire-and-forget; confirmed by the entity_moved world frame — the move
 *     route only replies with error frames)
 *   ③ quest:list 回执模式（requestId 关联 quest:rows）
 *     The quest:list receipt pattern (requestId correlates quest:rows)
 *   ④ 移动到 monster-1 锚点 (15,15)，等 monster 进入视野（entity_enter），攻击到首条 combat:hit
 *     Move to monster-1's anchor (15,15), wait for it to enter view (entity_enter), attack until the
 *     first combat:hit
 *   ⑤ 插值采样展示：interpolator.sample()（平滑）vs position()（权威位置）
 *     Interpolation sampling: interpolator.sample() (smoothed) vs position() (authoritative)
 *   ⑥ logout 收尾
 *     logout to finish
 */
'use strict';

const { NythrosClient } = require('../nythros-client.js');

const USERNAME = process.env.NYTHROS_DEMO_UID ?? '1001';
const PASSWORD = process.env.NYTHROS_DEMO_PASSWORD ?? 'secret';

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

async function main() {
  const client = new NythrosClient({
    username: USERNAME,
    password: PASSWORD,
    logger: (line) => console.log('  [client]', line),
  });

  // ① 登录链路
  // ① The login chain
  console.log('① 登录：gateway → token → Map 直连 ...');
  const { entityId } = await client.connect();
  console.log('  entityId =', entityId);

  const ownMoves = [];
  client.on('entity_moved', (f) => {
    if (f.payload.id === entityId) ownMoves.push(f.payload.position);
  });

  // ② 避险 move（火发无回执：move 路由无成功回执帧，且 STATE 帧跳过自身——单人客户端收不到
  // 自己的 entity_moved，服务器为权威位置源，等待落地后继续，与 verify-mmorpg step0 同口径）。
  // ② Evasive move (fire-and-forget: the move route has no success receipt, and STATE frames skip self —
  // a solo client never receives its own entity_moved; the server is the authoritative position source,
  // so wait for the landing and continue, matching verify-mmorpg's step0).
  console.log('② 避险 move → (100,100) ...');
  client.send('move', { dx: 100, dy: 100 }, 'flow-move-evasive');
  await sleep(1200);
  console.log('  已发送（自身位移以服务器为权威，STATE 帧跳过自身）');

  // ③ quest:list 回执模式（quest 路由不发 requestId 回显——replyType 指定 quest:rows 帧类型匹配）
  // ③ The quest:list receipt pattern (quest routes don't echo requestIds — replyType matches by the quest:rows frame type)
  console.log('③ request(quest:list) 回执模式 ...');
  const rows = await client.request('quest:list', {}, { requestId: 'flow-quest', replyType: 'quest:rows', timeoutMs: 8000 });
  console.log('  quest:rows:', JSON.stringify(rows.payload));
  if (!Array.isArray(rows.payload.questIds)) {
    throw new Error('quest:rows 负载不完整（玩法批未开启？NYTHROS_GAMEPLAY=1）');
  }

  // ④ 移动到 monster-1 锚点并攻击
  // ④ Move to monster-1's anchor and attack
  console.log('④ 移动到 monster-1 锚点 (15,15) ...');
  client.send('move', { dx: -85, dy: -85 }, 'flow-move-anchor');
  await sleep(1200);

  let hit = null;
  client.on('combat:hit', (f) => {
    if (f.payload.targetId === 'monster-1') hit = f;
  });

  console.log('  攻击 monster-1（等首条 combat:hit，最多 20 次）...');
  for (let i = 0; i < 20 && !hit; i++) {
    client.send('attack', { targetId: 'monster-1' }, 'flow-atk-' + i);
    await sleep(1500);
  }
  if (!hit) throw new Error('20 次攻击未命中 monster-1（怪物漂移或已死亡）');
  console.log('  combat:hit:', JSON.stringify(hit.payload));

  // ⑤ 插值采样展示（怪物 = tick 驱动实体：到达间隔 EMA 自适应窗口；平滑值 vs 权威值）
  // ⑤ Interpolation sampling (monsters are tick-driven entities: arrival-interval EMA adaptive window;
  // smoothed vs authoritative)
  const interpolated = client.interpolator.sample('monster-1');
  const authoritative = client.interpolator.position('monster-1');
  console.log('⑤ monster-1 插值采样（平滑）:', JSON.stringify(interpolated), '/ 权威位置:', JSON.stringify(authoritative));
  if (!authoritative) throw new Error('monster-1 未进入插值引擎（entity_enter/monster:spawned 未收到）');

  // ⑥ 登出
  // ⑥ Logout
  client.close();
  await sleep(300);
  console.log('⑥ logout 完成');
  console.log('RESULT: PASS（登录/移动/攻击流全链走通）');
}

main().catch((e) => {
  console.error('RESULT: FAIL —', e.message);
  process.exit(1);
});
