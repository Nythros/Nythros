<?php

declare(strict_types=1);

namespace Nythros\NetworkWorkerman\Tests;

use Nythros\Network\RateLimiterInterface;
use Nythros\NetworkWorkerman\ConnectionManager;
use Nythros\NetworkWorkerman\WorkermanConnection;
use Nythros\NetworkWorkerman\WorkermanWebSocketServer;
use Nythros\Protocol\BinaryBatchSerializer;
use Nythros\Protocol\JsonBatchSerializer;
use Nythros\Protocol\ProtocolVocabulary;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;

/**
 * WorkermanWebSocketServer 单元测试：内部连接限流豁免（MINOR-3）与普通连接限流路径。
 * 组装策略：真实 server 实例（Worker 对象仅在 runAll 时监听，构造无害）+ 桩 TcpConnection 注入私有
 * connections 表（等价 handleConnect 的登记路径），handleMessage 经反射调用逐路径断言限流器行为。
 * WorkermanWebSocketServer unit tests: the internal-connection rate-limit exemption (MINOR-3) and the ordinary
 * connection rate-limit path. Assembly strategy: a real server instance (the Worker object only listens inside
 * runAll, so construction is harmless) + a stub TcpConnection injected into the private connections map
 * (equivalent to handleConnect's registration path); handleMessage is invoked via reflection to assert the
 * rate limiter's behavior per path.
 */
final class WorkermanWebSocketServerTest extends TestCase
{
    public function testInternalConnectionSkipsRateLimiting(): void
    {
        // MINOR-3：rpc:hello 握手登记后 markInternal 的内部连接（服务间 RPC transport）不受 10 tokens/s 限流——
        // 未标记前 consume 正常执行（超限静默丢弃、不派发 handler）；标记后不再消费令牌、handler 正常派发
        // MINOR-3: an internal connection (inter-service RPC transport) marked after the rpc:hello handshake is exempt
        // from rate limiting — before marking, consume runs normally (over-limit frames are silently dropped without
        // handler dispatch); after marking, no token is consumed and handlers dispatch normally
        $rateLimiter = new FakeRateLimiter();
        $rateLimiter->allow = false;
        $server = new WorkermanWebSocketServer(
            'websocket://0.0.0.0:18090',
            rateLimiter: $rateLimiter,
            authTimeoutSeconds: null,
        );

        $tcp = new StubTcpConnection();
        $conn = new WorkermanConnection($tcp, new ConnectionManager(null, static fn (): float => 0.0));
        $this->injectConnection($server, $tcp, $conn);

        /** @var list<string> $dispatched 派发给 onMessage handler 的负载 Payloads dispatched to the onMessage handlers. */
        $dispatched = [];
        $server->onMessage(static function ($connection, string $data) use (&$dispatched): void {
            $dispatched[] = $data;
        });

        $handleMessage = new \ReflectionMethod(WorkermanWebSocketServer::class, 'handleMessage');

        // 未标记内部：consume 调用（超限 false）→ 静默丢弃，不派发 handler
        // Not marked internal: consume runs (over limit → false) → silently dropped, no handler dispatch
        // 注意：consume 收到的是 $conn->getId()（前缀字符串 conn-N），而非裸 $tcp->id
        // Note: consume receives \$conn->getId() (prefixed string conn-N), not the bare \$tcp->id
        $handleMessage->invoke($server, $tcp, 'frame-1');
        self::assertSame([$conn->getId()], $rateLimiter->consumed);
        self::assertSame([], $dispatched);

        // 标记内部：跳过限流（无新增 consume 调用），handler 正常派发
        // Marked internal: rate limiting skipped (no new consume call), the handler dispatches normally
        $conn->markInternal();
        self::assertTrue($conn->isInternal());
        $handleMessage->invoke($server, $tcp, 'frame-2');
        self::assertSame([$conn->getId()], $rateLimiter->consumed);
        self::assertSame(['frame-2'], $dispatched);
    }

    public function testOrdinaryConnectionConsumesRateLimitWhenAllowed(): void
    {
        // 对照路径：普通连接标记前 consume 通过（allow=true）→ handler 正常派发（豁免仅限内部连接）
        // Control path: an ordinary connection passes consume (allow=true) → the handler dispatches normally (the exemption is internal-only)
        $rateLimiter = new FakeRateLimiter();
        $server = new WorkermanWebSocketServer(
            'websocket://0.0.0.0:18091',
            rateLimiter: $rateLimiter,
            authTimeoutSeconds: null,
        );

        $tcp = new StubTcpConnection();
        $conn = new WorkermanConnection($tcp, new ConnectionManager(null, static fn (): float => 0.0));
        $this->injectConnection($server, $tcp, $conn);

        /** @var list<string> $dispatched 派发给 onMessage handler 的负载 Payloads dispatched to the onMessage handlers. */
        $dispatched = [];
        $server->onMessage(static function ($connection, string $data) use (&$dispatched): void {
            $dispatched[] = $data;
        });

        $handleMessage = new \ReflectionMethod(WorkermanWebSocketServer::class, 'handleMessage');
        $handleMessage->invoke($server, $tcp, 'frame-1');

        self::assertFalse($conn->isInternal());
        self::assertSame([$conn->getId()], $rateLimiter->consumed);
        self::assertSame(['frame-1'], $dispatched);
    }

    public function testHandlerExceptionWritesBatchEncodedErrorFrameAndLogsViaCallback(): void
    {
        // 错误兜底帧协议一致性：出站帧强制二进制 WebSocket（BINARY opcode），兜底帧必须与正常出站同走
        // 批量包编码路径——文本 JSON 兜底会让二进制协议客户端解析必失败。注入 BinaryBatchSerializer 断言
        // 兜底帧可被客户端 decodeBatch 解码；日志走注入回调而非 error_log。
        // Error-frame protocol consistency: outbound frames are forced binary WebSocket (BINARY opcode), so the
        // fallback frame must ride the same batch-packet encoding path as normal outbound traffic — a text-JSON
        // fallback would always fail binary clients. Injecting a BinaryBatchSerializer asserts the fallback frame
        // decodes via the client-side decodeBatch; logging goes through the injected callback instead of error_log.
        $serializer = new BinaryBatchSerializer(new ProtocolVocabulary(
            typeCodes: ['error' => 1],
            keyCodes: ['code' => 1, 'message' => 2],
        ));
        /** @var list<string> $logs 日志回调收到的消息 Messages captured by the log callback. */
        $logs = [];
        $server = new WorkermanWebSocketServer(
            'websocket://0.0.0.0:18092',
            authTimeoutSeconds: null,
            errorSerializer: $serializer,
            errorLogger: static function (string $message) use (&$logs): void {
                $logs[] = $message;
            },
        );

        $tcp = new PipedTcpConnection();
        $conn = new WorkermanConnection($tcp, new ConnectionManager(null, static fn (): float => 0.0));
        $this->injectConnection($server, $tcp, $conn);

        $server->onMessage(static function (): void {
            throw new \RuntimeException('boom');
        });

        (new \ReflectionMethod(WorkermanWebSocketServer::class, 'handleMessage'))
            ->invoke($server, $tcp, 'frame-1');

        // 日志回调恰好收到一次异常消息（不再直写 error_log）
        // The log callback receives the exception message exactly once (no direct error_log write)
        self::assertCount(1, $logs);
        self::assertStringContainsString('onMessage handler failed', $logs[0]);
        self::assertStringContainsString('boom', $logs[0]);

        // 兜底帧是批量包编码：客户端按同一词表解码出 type=error / code=500 / message=boom
        // The fallback frame is batch-packet encoded: the client decodes type=error / code=500 / message=boom with the same vocabulary
        $frames = $serializer->decodeBatch($tcp->readSentBytes());
        self::assertCount(1, $frames);
        self::assertSame('error', $frames[0]->type);
        self::assertSame(500, $frames[0]->payload['code']);
        self::assertSame('boom', $frames[0]->payload['message']);
    }

    public function testHandlerExceptionDefaultsToJsonBatchErrorFrame(): void
    {
        // 缺省装配（未注入 errorSerializer）：兜底帧走缺省 JsonBatchSerializer 的批量包编码——
        // 任何情况下兜底帧都不是裸 JSON 文本帧，与「一包多帧」的出站语义保持同构。
        // Default assembly (no errorSerializer injected): the fallback frame uses the default JsonBatchSerializer's
        // batch encoding — it is never a bare JSON text frame, staying isomorphic with the "many frames in one
        // packet" outbound semantics.
        $server = new WorkermanWebSocketServer(
            'websocket://0.0.0.0:18093',
            authTimeoutSeconds: null,
            errorLogger: static function (string $message): void {
            },
        );

        $tcp = new PipedTcpConnection();
        $conn = new WorkermanConnection($tcp, new ConnectionManager(null, static fn (): float => 0.0));
        $this->injectConnection($server, $tcp, $conn);

        $server->onMessage(static function (): void {
            throw new \RuntimeException('kaboom');
        });

        (new \ReflectionMethod(WorkermanWebSocketServer::class, 'handleMessage'))
            ->invoke($server, $tcp, 'frame-1');

        $frames = (new JsonBatchSerializer())->decodeBatch($tcp->readSentBytes());
        self::assertCount(1, $frames);
        self::assertSame('error', $frames[0]->type);
        self::assertSame('kaboom', $frames[0]->payload['message']);
    }

    /**
     * 把 (tcp, conn) 注入 server 的私有 connections 表（等价 handleConnect 的登记路径）。
     * Injects the (tcp, conn) pair into the server's private connections map (equivalent to handleConnect's registration path).
     */
    private function injectConnection(WorkermanWebSocketServer $server, TcpConnection $tcp, WorkermanConnection $conn): void
    {
        $property = new \ReflectionProperty($server, 'connections');
        $connections = $property->getValue($server);
        \assert(is_array($connections));
        $connections[$tcp->id] = $conn;
        $property->setValue($server, $connections);
    }
}

/**
 * 假限流器：记录 consume 的 connectionId，按 allow 返回放行/超限。
 * Fake rate limiter: records the consumed connectionIds and returns allow/over-limit per the allow flag.
 */
final class FakeRateLimiter implements RateLimiterInterface
{
    /** @var list<string> consume 调用的 connectionId 记录 consume call connectionIds. */
    public array $consumed = [];

    /** 放行判定：true = 放行，false = 超限 Verdict: true = allowed, false = over limit. */
    public bool $allow = true;

    public function consume(string $connectionId, int $tokens = 1): bool
    {
        $this->consumed[] = $connectionId;

        return $this->allow;
    }

    public function forget(string $connectionId): void
    {
        // 本测试不涉及断连释放，空实现 Not used by this test; no-op
    }
}

/**
 * 桩 TcpConnection：真实 socket 对 + Select 事件循环对象，仅作连接对象注入（不发送、不监听）。
 * Stub TcpConnection: a real socket pair + a Select event-loop object, injected as a connection object only (never sends, never listens).
 */
final class StubTcpConnection extends TcpConnection
{
    public function __construct()
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        \assert($pair !== false);
        [$socket] = $pair;
        parent::__construct(new Select(), $socket, '127.0.0.1:1');
    }
}

/**
 * 管道桩 TcpConnection：保留 socket 对的对端，send 写入的字节可从对端读回（错误兜底帧编码断言用）。
 * Piped stub TcpConnection: keeps the peer end of the socket pair so bytes written by send can be read back
 * (used to assert the error fallback frame's encoding).
 */
final class PipedTcpConnection extends TcpConnection
{
    /** @var resource 对端 socket（readSentBytes 读取端） Peer socket (the read side of readSentBytes). */
    private $peer;

    public function __construct()
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        \assert($pair !== false);
        [$socket, $peer] = $pair;
        $this->peer = $peer;
        parent::__construct(new Select(), $socket, '127.0.0.1:1');
    }

    /**
     * 从对端读回本连接 send 过的全部字节（非阻塞，读尽即止）。
     * Reads back all bytes sent on this connection from the peer end (non-blocking, drains what is available).
     */
    public function readSentBytes(): string
    {
        $buffer = '';
        stream_set_blocking($this->peer, false);
        while (($chunk = fread($this->peer, 65536)) !== false && $chunk !== '') {
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
