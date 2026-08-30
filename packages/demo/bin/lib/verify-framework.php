<?php

declare(strict_types=1);

/**
 * P14 验收脚本公共库：verify-*.php 共享的「step 状态机 + 收件箱等待 + 断言 + 定时器登记」骨架。
 * The P14 acceptance-script common library: the "step state machine + inbox waiting + assertions + timer
 * registry" skeleton shared by verify-*.php.
 *
 * 抽离动机（blueprint/21 P14）：六脚本骨架逐字节近似（reqId/check/closeStep/nextStep/finishAll/inboxTake/
 * waitFrame/sendMap/waitMapFrame），且存在系统性病灶——step body 内联注册的链式/自循环定时器不登记，
 * closeStep/finishAll 只删步骤超时定时器；步骤失败/超时后攻击自循环等定时器永久自续，向后续步骤注入
 * 请求帧污染复跑。本库以「登记表」根治：step 内所有非持久定时器一律经 verifyTimer 注册，closeStep/
 * nextStep/finishAll 统一清表。
 * Extraction motive (blueprint/21 P14): the six scripts' skeletons are byte-for-byte near-identical, with one
 * systemic disease — chained/self-rescheduling timers registered inline in step bodies are unregistered, while
 * closeStep/finishAll delete only the step-timeout timer; after a step fails or times out, attack self-loops
 * keep rescheduling forever, injecting request frames into later steps and polluting reruns. This library cures
 * it with a registry: every non-persistent step timer registers via verifyTimer, and closeStep/nextStep/
 * finishAll clear the registry uniformly.
 *
 * 约定（消费方脚本职责）：
 * - 先 require map-codec.php（frameMap/decodeMapFrames 由其提供，本库 sendMap 依赖 frameMap）；
 * - 初始化 $GLOBALS['verify']（steps/stepIdx/currentItem/currentTimer/checks/results/done/stepSettled/
 *   abort + stepTimers 空表——bootVerifyGlobals() 一并代劳）与 $GLOBALS['clients']；
 * - 步骤注册进 $GLOBALS['verify']['steps']：list<[名称, body, 超时秒]>；
 * - 输出契约不变：逐项一行 [verify] [PASS|FAIL]，末行 summary + RESULT。
 * Conventions (consumer-script duties):
 * - require map-codec.php first (frameMap/decodeMapFrames come from it; this library's sendMap depends on frameMap);
 * - initialize $GLOBALS['verify'] (bootVerifyGlobals() does it for you, including the empty stepTimers
 *   registry) and $GLOBALS['clients'];
 * - steps register into $GLOBALS['verify']['steps']: list<[name, body, timeout-seconds]>;
 * - the output contract is unchanged: one [verify] [PASS|FAIL] line per item, a final summary + RESULT.
 */

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/**
 * 初始化验收全局状态（含 P14 定时器登记表）。
 * Initializes the acceptance global state (including the P14 timer registry).
 *
 * @param list<array{0: string, 1: callable(): void, 2: float}> $steps 步骤表 [名称, body, 超时秒] The step table [name, body, timeout-seconds].
 */
function bootVerifyGlobals(array $steps): void
{
    $GLOBALS['verify'] = [
        'steps' => $steps,
        'stepIdx' => 0,
        'currentItem' => '',
        'currentTimer' => null,
        'checks' => [],
        'results' => [],
        'done' => false,
        'stepSettled' => false,
        'abort' => false,
        /** @var list<int|bool> 步骤作用域定时器登记表（closeStep/nextStep/finishAll 统一清空） The step-scoped timer registry (cleared uniformly by closeStep/nextStep/finishAll). */
        'stepTimers' => [],
    ];
    $GLOBALS['clients'] = [];
    $GLOBALS['entityIds'] = [];
    $GLOBALS['reqSeq'] = 0;
}

function reqId(): string
{
    $GLOBALS['reqSeq']++;

    return 'vm' . $GLOBALS['reqSeq'];
}

function check(bool $ok, string $label): void
{
    $GLOBALS['verify']['checks'][] = ['ok' => $ok, 'label' => $label];
}

/**
 * 步骤作用域定时器注册（P14 失败清理核心）：step body 内所有链式/自循环定时器必须经此注册——
 * 步骤收束（PASS/FAIL/超时/异常）与全局收尾时统一摘除，杜绝「失败一次定时器泄漏污染复跑」。
 * 参数与 Timer::add 逐位对齐（interval/回调/参数表/持久标记），迁移即前缀替换零改参。
 * The step-scoped timer registration (the P14 failure-cleanup core): every chained/self-rescheduling timer
 * inside a step body must register here — cleared uniformly on step close (PASS/FAIL/timeout/exception) and
 * on global finish, eliminating the "one failure leaks timers and pollutes reruns" disease. The parameters
 * align positionally with Timer::add (interval/callback/args/persistent), so migration is a prefix swap with
 * zero argument rewrites.
 *
 * @param float $interval 间隔秒 The interval in seconds.
 * @param callable(): void $cb 回调 The callback.
 * @param array<int, mixed> $args 回调参数表（透传 Timer::add） The callback args (Timer::add pass-through).
 * @param bool $persistent true = 持久定时器（不登记、不随步骤清空，如全局看门狗） true = a persistent timer
 *   (unregistered, never cleared by step close — e.g. the global watchdog).
 * @return int|bool 定时器 id（透传 Timer::add） The timer id (Timer::add pass-through).
 */
function verifyTimer(float $interval, callable $cb, array $args = [], bool $persistent = false): int|bool
{
    $timerId = Timer::add($interval, $cb, $args, $persistent);
    if (!$persistent) {
        $GLOBALS['verify']['stepTimers'][] = $timerId;
    }

    return $timerId;
}

/**
 * 清空步骤定时器登记表（含步骤超时定时器）。
 * Clears the step-timer registry (including the step-timeout timer).
 */
function clearStepTimers(): void
{
    $v = &$GLOBALS['verify'];
    if ($v['currentTimer'] !== null) {
        Timer::del($v['currentTimer']);
        $v['currentTimer'] = null;
    }
    foreach ($v['stepTimers'] as $timerId) {
        Timer::del($timerId);
    }
    $v['stepTimers'] = [];
}

function closeStep(string $status, string $detail = ''): void
{
    $v = &$GLOBALS['verify'];
    if ($v['done'] || ($v['stepSettled'] ?? false)) {
        return;
    }
    $v['stepSettled'] = true;
    clearStepTimers();

    if ($status === 'PASS') {
        $failures = array_filter($v['checks'], static fn (array $c): bool => !$c['ok']);
        if ($failures !== []) {
            $status = 'FAIL';
            echo "  断言失败 assertions failed:\n";
            foreach ($failures as $c) {
                echo '    - ' . $c['label'] . PHP_EOL;
            }
        }
    }

    $v['results'][] = ['item' => $v['currentItem'], 'status' => $status, 'detail' => $detail];
    echo sprintf("[verify] [%s] %s%s\n", $status, $v['currentItem'], $detail !== '' ? ' — ' . $detail : '');
    $v['checks'] = [];

    nextStep();
}

function nextStep(): void
{
    $v = &$GLOBALS['verify'];
    if ($v['done']) {
        return;
    }

    if (!empty($v['abort'])) {
        finishAll();

        return;
    }

    if ($v['stepIdx'] >= count($v['steps'])) {
        finishAll();

        return;
    }

    // 步骤边界兜底清表：上一步异常路径漏登的定时器在此被拦截（防跨步骤泄漏的最后一道闸）
    // A step-boundary backstop sweep: timers an exceptional path failed to catch get intercepted here
    // (the last gate against cross-step leaks).
    clearStepTimers();

    [$item, $body, $timeout] = $v['steps'][$v['stepIdx']];
    $v['stepIdx']++;
    $v['currentItem'] = $item;
    $v['checks'] = [];
    $v['stepSettled'] = false;
    echo sprintf("[verify] run: %s\n", $item);
    $v['currentTimer'] = Timer::add($timeout, function () use ($item, $timeout): void {
        echo sprintf("[verify] TIMEOUT: %s\n", $item);
        closeStep('FAIL', sprintf('步骤超时 step timeout（>%gs）', $timeout));
    }, [], false);
    try {
        $body();
    } catch (\Throwable $e) {
        echo sprintf("[verify] EXCEPTION in %s: %s\n", $item, $e->getMessage());
        closeStep('FAIL', '步骤异常: ' . $e->getMessage());
    }
}

function finishAll(): void
{
    $v = &$GLOBALS['verify'];
    if ($v['done']) {
        return;
    }
    $v['done'] = true;
    clearStepTimers();

    foreach ($GLOBALS['clients'] as $state) {
        $conn = $state['conn'] ?? null;
        if ($conn instanceof AsyncTcpConnection) {
            $conn->close();
        }
    }

    // SKIP 单列统计（如未启用反作弊的验收项）——不计入 FAIL（P18 修正：原逐脚本实现把 SKIP 误计为
    // FAIL，RESULT 恒 FAILED）。
    // SKIPs are counted separately (e.g. acceptance items whose feature env is off) — never counted as FAIL
    // (the P18 fix: the per-script implementations used to count SKIP as FAIL, making RESULT permanently FAILED).
    $pass = $fail = $skip = 0;
    foreach ($v['results'] as $r) {
        if ($r['status'] === 'PASS') {
            $pass++;
        } elseif ($r['status'] === 'SKIP') {
            $skip++;
        } else {
            $fail++;
        }
    }

    echo PHP_EOL;
    echo sprintf("[verify] summary: PASS=%d FAIL=%d SKIP=%d\n", $pass, $fail, $skip);
    echo sprintf("[verify] RESULT: %s (PASS=%d FAIL=%d SKIP=%d)\n", $fail > 0 ? 'FAILED' : 'PASSED', $pass, $fail, $skip);

    posix_kill(posix_getppid(), SIGINT);
}

/**
 * 在收件箱中查找并移除首个匹配 type 与谓词的帧。
 * Finds and removes the first inbox frame matching the type and predicate.
 *
 * @param array<int, array<string, mixed>> $inbox 收件箱（引用） Inbox (by reference).
 * @param callable(array<string, mixed>): bool|null $pred 附加谓词 Additional predicate.
 * @return ?array<string, mixed> 命中帧，未命中 null The hit frame, or null.
 */
function inboxTake(array &$inbox, ?string $type = null, ?callable $pred = null): ?array
{
    foreach ($inbox as $index => $f) {
        if ($type !== null && ($f['type'] ?? null) !== $type) {
            continue;
        }
        if ($pred !== null && !$pred($f)) {
            continue;
        }
        unset($inbox[$index]);
        $inbox = array_values($inbox);

        return $f;
    }

    return null;
}

/**
 * 轮询等待收件箱出现匹配帧（0.2s 粒度，扫描定时器经 verifyTimer 登记——步骤收束即停扫）；
 * 命中回调命中帧、超时回调失败。
 * Polls until a matching frame appears in the inbox (0.2s granularity, the scan timer registered via
 * verifyTimer — sweeping stops the moment the step closes); the hit callback receives the frame, the miss
 * callback fires on timeout.
 *
 * @param array<int, array<string, mixed>> $inbox 收件箱（引用） Inbox (by reference).
 * @param callable(array<string, mixed>): bool|null $pred 附加谓词 Additional predicate.
 * @param callable(array<string, mixed>): void $onHit 命中回调 Hit callback.
 * @param callable(): void $onFail 超时回调 Timeout callback.
 */
function waitFrame(array &$inbox, ?string $type, ?callable $pred, float $timeout, callable $onHit, callable $onFail): void
{
    $t0 = microtime(true);
    $scan = null;
    $scan = function () use (&$scan, &$inbox, $type, $pred, $timeout, $onHit, $onFail, $t0): void {
        $f = inboxTake($inbox, $type, $pred);
        if ($f !== null) {
            $onHit($f);

            return;
        }
        if (microtime(true) - $t0 >= $timeout) {
            $onFail();

            return;
        }
        verifyTimer(0.2, $scan);
    };
    $scan();
}

/**
 * 向某 uid 的 Map 直连发送一帧（frameMap 由消费方先 require 的 map-codec.php 提供）。
 * Sends one frame on a uid's direct Map connection (frameMap comes from the map-codec.php the consumer
 * requires first).
 */
function sendMap(string $uid, string $type, array $payload, ?string $requestId = null): void
{
    $conn = $GLOBALS['clients'][$uid]['conn'] ?? null;
    if ($conn instanceof AsyncTcpConnection) {
        $conn->send(frameMap($type, $payload, $requestId ?? reqId()));
    }
}

function waitMapFrame(string $uid, string $type, ?callable $pred, float $timeout, callable $onHit, callable $onFail): void
{
    if (!isset($GLOBALS['clients'][$uid]['inbox']) || !is_array($GLOBALS['clients'][$uid]['inbox'])) {
        $onFail();

        return;
    }
    waitFrame($GLOBALS['clients'][$uid]['inbox'], $type, $pred, $timeout, $onHit, $onFail);
}
