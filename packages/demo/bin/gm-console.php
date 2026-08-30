<?php

declare(strict_types=1);

// 定位：packages/demo/bin/gm-console.php — GM Web 控制台（demo 级，最小 HTTP + 内嵌单页 UI）。
// 复用既有 GM 链路而非另起后门：每次执行 = 完整客户端登录（gateway JSON 换 token → Map 二进制 auth）
// → gm:exec{command,...} → gm:result 定向回执。权限判定仍在服务端 GmCommandBus +
// GmPermissionInterface（demo 装配 StaticGmAuthorizer，账号须在 NYTHROS_GM_UIDS 白名单内）。
// Located at: packages/demo/bin/gm-console.php — the GM web console (demo-grade, minimal HTTP + a single-page UI).
// It reuses the existing GM chain instead of opening a backdoor: every execution = a full client login
// (gateway JSON -> token -> Map binary auth) -> gm:exec{command,...} -> a directed gm:result receipt.
// Permission checking stays server-side in GmCommandBus + GmPermissionInterface (the demo wires
// StaticGmAuthorizer; the account must be inside the NYTHROS_GM_UIDS whitelist).
//
// 用法 Usage:
//   php packages/demo/bin/gm-console.php [--addr=127.0.0.1:19110] [--gateway=127.0.0.1:18285]
//       [--map=127.0.0.1:18081] [--username=1001] [--password=secret] [--mapId=map-1]
//   php packages/demo/bin/gm-console.php --self-test
//
// 服务器侧前提 Server-side prerequisite:
//   NYTHROS_GM_UIDS=1001 ... php bin/server start   # GM 白名单（uid 即账号 username）
//   curl -s -XPOST localhost:19110/api/exec -d '{"command":"status"}'
//   curl -s -XPOST localhost:19110/api/exec -d '{"command":"broadcast","message":"停机维护"}'
//
// 注意 Notes：协议回执只携带 code/message（GmResult data 为进程内消费，MapServer 不转发）；
// 控制台缺省只绑 127.0.0.1——公网暴露前必须另做鉴权与 TLS（见 docs/deployment.md 生产清单）。

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/lib/map-codec.php';

/**
 * 最小 WebSocket 客户端帧编码（客户端帧必须掩码）。
 * Minimal WebSocket client-frame encoding (client frames must be masked).
 */
function wsEncodeFrame(string $payload, int $opcode = 0x1): string
{
    $len = strlen($payload);
    $head = chr(0x80 | $opcode); // FIN + opcode
    $maskKey = random_bytes(4);
    if ($len < 126) {
        $head .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $head .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $head .= chr(0x80 | 127) . pack('J', $len);
    }
    $masked = $payload ^ str_repeat($maskKey, intdiv($len, 4) + 1);

    return $head . $maskKey . substr($masked, 0, $len);
}

/**
 * 读一个服务端 WebSocket 帧（服务端帧不掩码）；EOF/超时返回 null。
 * Reads one server WebSocket frame (unmasked); null on EOF/timeout.
 *
 * @return ?array{opcode: int, payload: string}
 */
function wsReadFrame($stream, float $timeoutSeconds = 5.0): ?array
{
    stream_set_timeout($stream, (int) $timeoutSeconds, (int) (($timeoutSeconds - (int) $timeoutSeconds) * 1e6));
    $head = fread($stream, 2);
    if ($head === false || strlen($head) < 2) {
        return null;
    }
    $opcode = ord($head[0]) & 0x0f;
    $len = ord($head[1]) & 0x7f;
    if ($len === 126) {
        $b = fread($stream, 2);
        if ($b === false || strlen($b) < 2) {
            return null;
        }
        $len = unpack('n', $b)[1];
    } elseif ($len === 127) {
        $b = fread($stream, 8);
        if ($b === false || strlen($b) < 8) {
            return null;
        }
        $len = unpack('J', $b)[1];
    }
    $payload = $len === 0 ? '' : (string) fread($stream, $len);

    return ['opcode' => $opcode, 'payload' => $payload];
}

/**
 * 发起 WebSocket 握手（RFC 6455 最小客户端）。
 * Performs the WebSocket handshake (a minimal RFC 6455 client).
 *
 * @return resource|false
 */
function wsHandshake(string $host, int $port, string $path = '/')
{
    $stream = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
    if ($stream === false) {
        return false;
    }
    $key = base64_encode(random_bytes(16));
    fwrite($stream, "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $response = '';
    while (!str_contains($response, "\r\n\r\n")) {
        $line = fgets($stream);
        if ($line === false) {
            fclose($stream);

            return false;
        }
        $response .= $line;
    }
    if (!str_contains($response, '101')) {
        fclose($stream);

        return false;
    }

    return $stream;
}

/**
 * GM 执行链：gateway 登录换 token → Map 二进制 auth → gm:exec → gm:result（requestId 匹配）。
 * The GM execution chain: gateway login for a token -> Map binary auth -> gm:exec -> gm:result (matched by requestId).
 *
 * @param array{gateway: string, map: string, username: string, password: string, mapId: string} $cfg
 * @param array<string|int, mixed> $payload gm:exec 负载（含 command） The gm:exec payload (with command).
 *
 * @return array{ok: bool, code?: string, message?: string, error?: string}
 */
function gmExecute(array $cfg, array $payload): array
{
    [$gwHost, $gwPort] = explode(':', $cfg['gateway']);
    $gw = wsHandshake($gwHost, (int) $gwPort);
    if ($gw === false) {
        return ['ok' => false, 'error' => 'gateway 握手失败 ' . $cfg['gateway']];
    }
    fwrite($gw, wsEncodeFrame((string) json_encode([
        'type' => 'auth',
        'requestId' => 'gm-console:' . $cfg['username'],
        'timestamp' => microtime(true),
        'payload' => ['username' => $cfg['username'], 'password' => $cfg['password'], 'mapId' => $cfg['mapId']],
    ])));

    $token = null;
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $frame = wsReadFrame($gw);
        if ($frame === null) {
            break;
        }
        if (in_array($frame['opcode'], [0x8, 0x9], true)) {
            break; // close/ping 终止等待 close/ping ends the wait
        }
        $msg = json_decode($frame['payload'], true);
        if (($msg['type'] ?? '') === 'auth_ok') {
            $token = $msg['payload']['token'] ?? null;
            break;
        }
        if (($msg['type'] ?? '') === 'auth_failed') {
            fclose($gw);

            return ['ok' => false, 'error' => 'gateway auth_failed：' . json_encode($msg['payload'] ?? [], JSON_UNESCAPED_UNICODE)];
        }
    }
    fclose($gw);
    if (!is_string($token) || $token === '') {
        return ['ok' => false, 'error' => 'gateway 未返回 token（超时或拒绝）'];
    }

    [$mapHost, $mapPort] = explode(':', $cfg['map']);
    $map = wsHandshake($mapHost, (int) $mapPort);
    if ($map === false) {
        return ['ok' => false, 'error' => 'map 握手失败 ' . $cfg['map']];
    }
    $requestId = 'gm-exec:' . bin2hex(random_bytes(4));
    fwrite($map, wsEncodeFrame(frameMap('auth', ['token' => $token]), 0x2));
    $authed = false;
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $frame = wsReadFrame($map);
        if ($frame === null) {
            break;
        }
        if ($frame['opcode'] !== 0x2) {
            continue;
        }
        foreach (decodeMapFrames($frame['payload']) as $f) {
            if ($f['type'] === 'auth_ok') {
                $authed = true;
            }
            if ($f['type'] === 'auth_failed') {
                fclose($map);

                return ['ok' => false, 'error' => 'map auth_failed：token 失效或过期'];
            }
        }
        if ($authed) {
            break;
        }
    }
    if (!$authed) {
        fclose($map);

        return ['ok' => false, 'error' => 'map auth 未完成（超时）'];
    }

    fwrite($map, wsEncodeFrame(frameMap('gm:exec', $payload, $requestId), 0x2));
    $result = null;
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $frame = wsReadFrame($map);
        if ($frame === null) {
            break;
        }
        if ($frame['opcode'] !== 0x2) {
            continue;
        }
        foreach (decodeMapFrames($frame['payload']) as $f) {
            if ($f['type'] === 'gm:result' && $f['requestId'] === $requestId) {
                $result = $f['payload'];
                break 2;
            }
        }
    }
    fclose($map);
    if ($result === null) {
        return ['ok' => false, 'error' => 'gm:result 未到达（超时；检查账号是否在 NYTHROS_GM_UIDS 白名单）'];
    }

    return ['ok' => true, 'code' => (string) $result['code'], 'message' => (string) $result['message']];
}

/** 控制台单页 UI（无外部依赖）。 The single-page console UI (zero external deps). */
function renderConsolePage(string $username): string
{
    $u = htmlspecialchars($username, ENT_QUOTES);
    // nowdoc 不插值：执行者经占位符替换注入（已 HTML 转义）。
    // The nowdoc never interpolates: the executor is injected via a placeholder (already HTML-escaped).
    return str_replace('{$u}', $u, <<<'HTML'
<!doctype html><html lang="zh"><head><meta charset="utf-8"><title>Nythros GM 控制台</title>
<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem;background:#111;color:#eee}
button,select,input{padding:.4rem .7rem;margin:.2rem;background:#2a2a2a;color:#eee;border:1px solid #444;border-radius:4px}
button{cursor:pointer}pre{background:#000;padding:1rem;border-radius:6px;min-height:2rem;white-space:pre-wrap}
input[type=text]{width:12rem}</style></head><body>
<h1>Nythros GM 控制台</h1><p>执行者：{$u}（权限由服务端 GmPermissionInterface 判定）</p>
<p><button onclick="exec({command:'status'})">status</button>
<button onclick="exec({command:'drain'})">drain</button>
kick <input type=text id="kick" placeholder="uid">
<button onclick="exec({command:'kick',targetId:document.getElementById('kick').value})">kick</button></p>
<p>broadcast <input type=text id="msg" placeholder="全服公告文本">
<button onclick="exec({command:'broadcast',message:document.getElementById('msg').value})">broadcast</button></p>
<pre id="out">// gm:result 显示在此（协议回执只带 code/message）</pre>
<script>
async function exec(payload){
  const out=document.getElementById('out');
  out.textContent='执行中…';
  try{
    const r=await fetch('/api/exec',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const j=await r.json();
    out.textContent=j.ok?('['+j.code+'] '+j.message):('ERROR: '+j.error);
  }catch(e){out.textContent='ERROR: '+e.message}
}
</script></body></html>
HTML);
}

/**
 * 门禁自测：ws 帧编码/解析 roundtrip（掩码还原）+ UI 形状，无网络依赖。
 * Self-test: ws frame encode/parse roundtrip (mask restoration) + UI shape, no network.
 */
function runSelfTest(): int
{
    $failures = [];
    $assert = static function (bool $cond, string $name) use (&$failures): void {
        echo ($cond ? 'PASS' : 'FAIL') . "  {$name}\n";
        if (!$cond) {
            $failures[] = $name;
        }
    };
    // 客户端帧掩码还原：帧头解析 + XOR 还原 payload
    $payload = json_encode(['type' => 'auth', 'timestamp' => 1.5], JSON_UNESCAPED_UNICODE);
    $frame = wsEncodeFrame($payload);
    assert(strlen($frame) >= 2);
    $finOpcode = ord($frame[0]);
    $maskedLen = ord($frame[1]) & 0x80;
    $len = ord($frame[1]) & 0x7f;
    $assert(($finOpcode & 0x0f) === 0x1 && ($finOpcode & 0x80) !== 0, 'FIN+文本帧 opcode');
    $assert($maskedLen !== 0 && $len === strlen($payload), '掩码位 + 7bit 长度');
    $maskKey = substr($frame, 2, 4);
    $body = $len < 126 ? substr($frame, 6) : null;
    if ($body !== null) {
        $restored = $body ^ str_repeat($maskKey, intdiv(strlen($body), 4) + 1);
        $assert(substr($restored, 0, strlen($payload)) === $payload, 'XOR 掩码还原 payload');
    } else {
        $assert(false, 'XOR 掩码还原 payload');
    }
    // 服务端帧解析 fixture（不掩码文本 "hi"）：0x81 0x02 'h' 'i'
    $fixture = chr(0x81) . chr(0x02) . 'hi';
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $fixture);
    rewind($fp);
    $parsed = wsReadFrame($fp);
    $assert($parsed !== null && $parsed['opcode'] === 0x1 && $parsed['payload'] === 'hi', '服务端帧解析（不掩码文本）');
    fclose($fp);
    $page = renderConsolePage('1001');
    $assert(str_contains($page, "执行者：1001") && str_contains($page, '/api/exec'), 'UI 含执行者与 API 端点');

    if ($failures !== []) {
        printf("[gm-console] SELF-TEST FAIL：%d 项断言未过\n", count($failures));

        return 1;
    }
    echo "[gm-console] SELF-TEST PASS\n";

    return 0;
}

if (in_array('--self-test', $argv, true)) {
    exit(runSelfTest());
}

$cfg = [
    'addr' => '127.0.0.1:19110',
    'gateway' => '127.0.0.1:18285',
    'map' => '127.0.0.1:18081',
    'username' => getenv('NYTHROS_GM_ACCOUNT') ?: '1001',
    'password' => getenv('NYTHROS_GM_PASSWORD') ?: 'secret',
    'mapId' => 'map-1',
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--addr=(.+)$/', $arg, $m)) {
        $cfg['addr'] = $m[1];
    } elseif (preg_match('/^--gateway=(.+)$/', $arg, $m)) {
        $cfg['gateway'] = $m[1];
    } elseif (preg_match('/^--map=(.+)$/', $arg, $m)) {
        $cfg['map'] = $m[1];
    } elseif (preg_match('/^--username=(.+)$/', $arg, $m)) {
        $cfg['username'] = $m[1];
    } elseif (preg_match('/^--password=(.+)$/', $arg, $m)) {
        $cfg['password'] = $m[1];
    } elseif (preg_match('/^--mapId=(.+)$/', $arg, $m)) {
        $cfg['mapId'] = $m[1];
    }
}

$server = @stream_socket_server('tcp://' . $cfg['addr'], $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "[gm-console] fatal: 监听 {$cfg['addr']} 失败：$errstr\n");
    exit(1);
}
echo "[gm-console] serving http://{$cfg['addr']}（执行者 {$cfg['username']}；权限由服务端白名单判定）\n";

while (true) {
    $conn = @stream_socket_accept($server, 1.0);
    if ($conn === false) {
        continue;
    }
    $request = '';
    while (!feof($conn) && strlen($request) < 16384) {
        $chunk = fread($conn, 1024);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
        if (str_contains($request, "\r\n\r\n")) {
            break;
        }
    }
    [$head, $body] = array_pad(explode("\r\n\r\n", $request, 2), 2, '');
    $requestLine = strtok($head, "\r\n") ?: '';
    $method = strtoupper((string) strstr($requestLine, ' ', true));
    $path = (string) preg_replace('/^\\S+\\s+([^\\s?]+).*$/', '$1', $requestLine);
    preg_match('/^Content-Length:\s*(\d+)/mi', $head, $cl);
    $need = (int) ($cl[1] ?? 0);
    while (strlen($body) < $need) {
        $more = fread($conn, $need - strlen($body));
        if ($more === false || $more === '') {
            break;
        }
        $body .= $more;
    }

    if ($method === 'GET' && str_starts_with($path, '/api/')) {
        $response = json_encode(['ok' => false, 'error' => 'use POST'], JSON_UNESCAPED_UNICODE);
        $status = '405 Method Not Allowed';
    } elseif ($method === 'GET') {
        $response = renderConsolePage($cfg['username']);
        $status = '200 OK';
    } elseif ($method === 'POST' && $path === '/api/exec') {
        $input = json_decode($body, true);
        $command = is_array($input) ? ($input['command'] ?? null) : null;
        if (!is_string($command) || $command === '') {
            $response = json_encode(['ok' => false, 'error' => '缺少 command 字段'], JSON_UNESCAPED_UNICODE);
            $status = '400 Bad Request';
        } else {
            $result = gmExecute($cfg, is_array($input) ? $input : []);
            $response = json_encode($result, JSON_UNESCAPED_UNICODE);
            $status = '200 OK';
        }
    } else {
        $response = json_encode(['ok' => false, 'error' => 'not found'], JSON_UNESCAPED_UNICODE);
        $status = '404 Not Found';
    }

    $contentType = str_starts_with($response, '<!doctype') ? 'text/html; charset=utf-8' : 'application/json; charset=utf-8';
    fwrite($conn, "HTTP/1.1 {$status}\r\nContent-Type: {$contentType}\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
    fclose($conn);
}
