// NythrosClient.cs —— Nythros 官方 Unity/C# 参考客户端（单文件、零外部依赖）。
// NythrosClient.cs — the official Nythros Unity/C# reference client (single file, zero external dependencies).
//
// 覆盖：二进制批量协议编解码（与 PHP BinaryBatchSerializer / JS NythrosCodec 逐字节对称，
// wire 格式权威文档 docs/protocol.md）+ 登录链路（gateway JSON 文本 auth → token → Map 二进制 auth）
// + 事件订阅 + requestId 回执。插值/重连等高阶能力参考 JS SDK（packages/client-js/nythros-client.js）
// 与 docs/state-sync.md 的规则，按需移植。
// Covers: the binary batch codec (byte-for-byte symmetric with PHP BinaryBatchSerializer / JS NythrosCodec;
// the authoritative wire doc is docs/protocol.md), the login chain (gateway JSON text auth -> token ->
// Map binary auth), event subscription and requestId receipts. Interpolation/reconnect follow the rules in
// docs/state-sync.md and the JS SDK — port as needed.
//
// 运行环境 Runtime：.NET Standard 2.1+ / Unity 2021.2+（System.Net.WebSockets.ClientWebSocket、
// System.Text.Json 均内置）。若目标平台不支持 ClientWebSocket，可换 NativeWebSocket 包——
// 本文件的编解码层（NythrosCodec）与传输层解耦，替换传输不影响协议正确性。
//
// ⚠ 本文件为参考实现：仓库 CI 无 C# 编译器，未做自动化编译验证；接入时先跑 unity-guide.md §4 的
//   三步冒烟（登录 → 移动 → attack→combat:hit）。The file is a reference implementation: the repo CI has no
//   C# compiler, so it is not build-verified; run the three-step smoke in unity-guide.md §4 first.

using System;
using System.Buffers.Binary;
using System.Collections.Generic;
using System.Net.WebSockets;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace Nythros.Client
{
    /// <summary>解码后的协议帧（与 PHP Message / JS frame 同构）。 A decoded frame (same shape as PHP Message / the JS frame).</summary>
    public sealed class NythrosFrame
    {
        public string Type = "";
        public string? RequestId;
        public Dictionary<string, object?> Payload = new();
    }

    /// <summary>
    /// 二进制批量协议编解码器：字节序约定——u16/u32 长度大端（pack('n')/pack('N')），
    /// int64/double 小端（pack('q')/pack('d') 机器序），POS 的两个 int16 大端。
    /// The binary batch codec. Byte order: u16/u32 lengths big-endian; int64/double little-endian;
    /// the two POS int16s big-endian.
    /// </summary>
    public static class NythrosCodec
    {
        // 帧类型码表（与 packages/demo/src/Protocol/FrameType::codeMap() 同步；新增帧两端同步）。
        public static readonly Dictionary<string, ushort> FrameTypes = new()
        {
            ["entity_moved"] = 1, ["entity_enter"] = 2, ["entity_leave"] = 3, ["combat:hit"] = 4,
            ["skill:cast"] = 5, ["drop:spawned"] = 6, ["drop:removed"] = 7, ["item:added"] = 8,
            ["entity_dead"] = 9, ["monster:spawned"] = 10, ["player:stats"] = 11, ["combat:error"] = 12,
            ["error"] = 13, ["auth_ok"] = 14, ["auth_failed"] = 15, ["auth"] = 16,
            ["move"] = 17, ["attack"] = 18, ["pickup"] = 19, ["logout"] = 20,
            // 完整 88 帧码表：接入时由 FrameType::codeMap() 再生成（见 unity-guide.md §2），
            // 此处只保留冒烟链路所需帧——缺帧会在 Encode 时抛 NythrosProtocolException。
        };

        // 负载字段码表（PayloadKey::codeMap() 子集，冒烟链路所需）。
        public static readonly Dictionary<string, ushort> PayloadKeys = new()
        {
            ["id"] = 1, ["position"] = 3, ["x"] = 4, ["y"] = 5, ["dx"] = 6, ["dy"] = 7,
            ["token"] = 8, ["code"] = 9, ["message"] = 10, ["username"] = 11, ["password"] = 12,
            ["mapId"] = 13, ["targetId"] = 15, ["damage"] = 16, ["hp"] = 17,
        };

        private const ushort KRequestId = 0xF2, KType = 0xF3;
        private const byte TNull = 0x00, TInt = 0x01, TFloat = 0x02, TString = 0x03, TString32 = 0x04,
            TList = 0x05, TPos = 0x06, TEmptyString = 0x07, TTrue = 0xF0, TFalse = 0xF1;
        private static readonly byte[] Magic = { 0x4E, 0x58, 0x00, 0x01 }; // "NX\0\x01"

        public sealed class NythrosProtocolException : Exception
        {
            public NythrosProtocolException(string message) : base(message) { }
        }

        /// <summary>编码一批帧为一个二进制批量包（入站格式：批量含 1 帧）。 Encodes frames into one batch packet.</summary>
        public static byte[] EncodeBatch(List<NythrosFrame> frames)
        {
            var bodies = new List<byte[]>(frames.Count);
            var total = 8;
            foreach (var f in frames) { var b = EncodeFrameBody(f); bodies.Add(b); total += 4 + b.Length; }
            var packet = new byte[total];
            var offset = 0;
            Magic.CopyTo(packet, offset); offset += 4;
            BinaryPrimitives.WriteUInt32BigEndian(packet.AsSpan(offset), (uint) frames.Count); offset += 4;
            foreach (var body in bodies)
            {
                BinaryPrimitives.WriteUInt32BigEndian(packet.AsSpan(offset), (uint) body.Length); offset += 4;
                Buffer.BlockCopy(body, 0, packet, offset, body.Length); offset += body.Length;
            }
            return packet;
        }

        private static byte[] EncodeFrameBody(NythrosFrame frame)
        {
            using var body = new MemoryStream();
            using var w = new BinaryWriter(body, Encoding.UTF8, leaveOpen: true);
            var fields = new List<(ushort key, byte type, byte[] data)>();

            fields.Add((KType, TString, EncodeString(frame.Type)));
            if (frame.RequestId != null) fields.Add((KRequestId, TString, EncodeString(frame.RequestId)));
            foreach (var (key, value) in frame.Payload)
            {
                if (!PayloadKeys.TryGetValue(key, out var keyCode))
                    throw new NythrosProtocolException($"未知负载字段 {key}（未登记进 PayloadKey 枚举）");
                fields.Add((keyCode, EncodeValue(value, out var data), data));
            }

            w.Write((ushort) fields.Count);
            foreach (var (key, type, data) in fields)
            {
                w.Write(key);
                w.Write(type);
                w.Write(data);
            }
            w.Flush();
            return body.ToArray();
        }

        private static byte[] EncodeString(string s)
        {
            var bytes = Encoding.UTF8.GetBytes(s);
            if (bytes.Length == 0) return Array.Empty<byte>();
            if (bytes.Length <= 255)
            {
                var outBytes = new byte[1 + bytes.Length];
                outBytes[0] = (byte) bytes.Length;
                Buffer.BlockCopy(bytes, 0, outBytes, 1, bytes.Length);
                return outBytes;
            }
            var longBytes = new byte[4 + bytes.Length];
            BinaryPrimitives.WriteUInt32BigEndian(longBytes, (uint) bytes.Length);
            Buffer.BlockCopy(bytes, 0, longBytes, 4, bytes.Length);
            return longBytes;
        }

        private static byte EncodeValue(object? value, out byte[] data)
        {
            switch (value)
            {
                case null: data = Array.Empty<byte>(); return TNull;
                case true: data = Array.Empty<byte>(); return TTrue;
                case false: data = Array.Empty<byte>(); return TFalse;
                case int i:
                    data = new byte[8];
                    BinaryPrimitives.WriteInt64LittleEndian(data, i);
                    return TInt;
                case long l:
                    data = new byte[8];
                    BinaryPrimitives.WriteInt64LittleEndian(data, l);
                    return TInt;
                case double d:
                    data = new byte[8];
                    BinaryPrimitives.WriteDoubleLittleEndian(data, d);
                    return TFloat;
                case string s:
                    data = EncodeString(s);
                    return data.Length == 0 ? TEmptyString : (data.Length <= 256 ? TString : TString32);
                case (int x, int y) pos:
                    data = new byte[4];
                    BinaryPrimitives.WriteInt16BigEndian(data.AsSpan(0, 2), (short) x);
                    BinaryPrimitives.WriteInt16BigEndian(data.AsSpan(2, 2), (short) y);
                    return TPos;
                default:
                    throw new NythrosProtocolException($"不支持的值类型 {value?.GetType().Name}");
            }
        }

        /// <summary>解码一个批量包为帧列表。 Decodes a batch packet into frames.</summary>
        public static List<NythrosFrame> DecodeBatch(byte[] bytes)
        {
            var frames = new List<NythrosFrame>();
            if (bytes.Length == 0) return frames;
            for (var i = 0; i < 4; i++)
                if (bytes[i] != Magic[i])
                    throw new NythrosProtocolException("魔数不匹配（非本协议二进制包）");
            var count = BinaryPrimitives.ReadUInt32BigEndian(bytes.AsSpan(4));
            var offset = 8;
            for (var i = 0; i < count; i++)
            {
                var len = BinaryPrimitives.ReadUInt32BigEndian(bytes.AsSpan(offset));
                frames.Add(DecodeFrameBody(bytes, (int) offset + 4, (int) len));
                offset += 4 + (int) len;
            }
            return frames;
        }

        private static NythrosFrame DecodeFrameBody(byte[] bytes, int offset, int length)
        {
            var frame = new NythrosFrame();
            var end = offset + length;
            var fieldCount = BinaryPrimitives.ReadUInt16BigEndian(bytes.AsSpan(offset));
            offset += 2;
            for (var i = 0; i < fieldCount; i++)
            {
                if (offset + 3 > end) throw new NythrosProtocolException("字段槽位越界");
                var keyCode = BinaryPrimitives.ReadUInt16BigEndian(bytes.AsSpan(offset));
                var valueType = bytes[offset + 2];
                offset += 3;
                switch (keyCode)
                {
                    case KType:
                        var (t, tUsed) = DecodeValue(bytes, offset, valueType);
                        frame.Type = (string) t!; offset += tUsed; break;
                    case KRequestId:
                        var (r, rUsed) = DecodeValue(bytes, offset, valueType);
                        frame.RequestId = (string) r!; offset += rUsed; break;
                    case >= 1 and <= 0xF0:
                        if (!KeyNames.TryGetValue(keyCode, out var name))
                            throw new NythrosProtocolException($"未知 keyCode {keyCode}");
                        var (v, used) = DecodeValue(bytes, offset, valueType);
                        frame.Payload[name] = v; offset += used; break;
                    default:
                        throw new NythrosProtocolException($"未知 keyCode {keyCode}");
                }
            }
            if (frame.Type.Length == 0) throw new NythrosProtocolException("帧体缺少 type 字段");
            return frame;
        }

        private static readonly Dictionary<ushort, string> KeyNames = BuildKeyNames();
        private static Dictionary<ushort, string> BuildKeyNames()
        {
            var map = new Dictionary<ushort, string>();
            foreach (var (name, code) in PayloadKeys) map[code] = name;
            return map;
        }

        private static (object?, int) DecodeValue(byte[] bytes, int offset, byte type)
        {
            switch (type)
            {
                case TNull: return (null, 0);
                case TTrue: return (true, 0);
                case TFalse: return (false, 0);
                case TInt: return (BinaryPrimitives.ReadInt64LittleEndian(bytes.AsSpan(offset)), 8);
                case TFloat: return (BinaryPrimitives.ReadDoubleLittleEndian(bytes.AsSpan(offset)), 8);
                case TString:
                {
                    var len = bytes[offset];
                    return (Encoding.UTF8.GetString(bytes, offset + 1, len), 1 + len);
                }
                case TString32:
                {
                    var len = (int) BinaryPrimitives.ReadUInt32BigEndian(bytes.AsSpan(offset));
                    return (Encoding.UTF8.GetString(bytes, offset + 4, len), 4 + len);
                }
                case TEmptyString: return ("", 0);
                case TPos:
                {
                    var x = BinaryPrimitives.ReadInt16BigEndian(bytes.AsSpan(offset));
                    var y = BinaryPrimitives.ReadInt16BigEndian(bytes.AsSpan(offset + 2));
                    return ((ValueTuple<int, int>) (x, y), 4);
                }
                default: throw new NythrosProtocolException($"未知值类型 0x{type:X2}");
            }
        }
    }

    /// <summary>
    /// Nythros C# 客户端：登录链路 + 事件订阅 + requestId 回执。
    /// The Nythros C# client: the login chain + event subscription + requestId receipts.
    /// </summary>
    public sealed class NythrosClient : IDisposable
    {
        private readonly string _username, _password, _mapId, _gatewayUrl, _mapUrl;
        private ClientWebSocket? _ws;
        private long _seq;
        private readonly Dictionary<string, TaskCompletionSource<NythrosFrame>> _pending = new();
        private readonly Dictionary<string, List<Action<NythrosFrame>>> _handlers = new();
        private readonly CancellationTokenSource _cts = new();
        private bool _disposed;

        public string? Token { get; private set; }
        public string? EntityId { get; private set; }

        public NythrosClient(string username, string password,
            string gatewayUrl = "ws://127.0.0.1:18285", string mapUrl = "ws://127.0.0.1:18081",
            string mapId = "map-1")
        {
            if (string.IsNullOrEmpty(username) || string.IsNullOrEmpty(password))
                throw new ArgumentException("缺少 username/password（网关账号表凭据）");
            _username = username; _password = password; _mapId = mapId;
            _gatewayUrl = gatewayUrl; _mapUrl = mapUrl;
        }

        /// <summary>登录链路：gateway auth(JSON) → token → Map auth(二进制) → entityId。</summary>
        public async Task<(string entityId, string token)> ConnectAsync(CancellationToken ct = default)
        {
            Token = await LoginGatewayAsync(ct).ConfigureAwait(false);

            _ws = new ClientWebSocket();
            await _ws.ConnectAsync(new Uri(_mapUrl), ct).ConfigureAwait(false);
            await SendBatchAsync(new List<NythrosFrame>
            {
                new() { Type = "auth", RequestId = "map-auth:t", Payload = { ["token"] = Token } },
            }, ct).ConfigureAwait(false);

            var authOk = await WaitFrameAsync("auth_ok", TimeSpan.FromSeconds(10), ct).ConfigureAwait(false);
            EntityId = authOk.Payload.TryGetValue("id", out var id) ? id?.ToString() : null;
            _ = ReadLoopAsync(_cts.Token); // 世界帧后台分发 The world-frame dispatch loop.
            return (EntityId ?? "", Token);
        }

        private async Task<string> LoginGatewayAsync(CancellationToken ct)
        {
            using var gw = new ClientWebSocket();
            await gw.ConnectAsync(new Uri(_gatewayUrl), ct).ConfigureAwait(false);
            var authJson = JsonSerializer.Serialize(new Dictionary<string, object?>
            {
                ["type"] = "auth",
                ["requestId"] = "login:" + _username,
                ["timestamp"] = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0,
                ["payload"] = new Dictionary<string, object?>
                    { ["username"] = _username, ["password"] = _password, ["mapId"] = _mapId },
            });
            await gw.SendAsync(Encoding.UTF8.GetBytes(authJson), WebSocketMessageType.Text, true, ct)
                .ConfigureAwait(false);

            var buffer = new byte[4096];
            while (gw.State == WebSocketState.Open)
            {
                var result = await gw.ReceiveAsync(buffer, ct).ConfigureAwait(false);
                var json = Encoding.UTF8.GetString(buffer, 0, result.Count);
                using var doc = JsonDocument.Parse(json);
                if (doc.RootElement.GetProperty("type").GetString() != "auth_ok") continue;
                var token = doc.RootElement.GetProperty("payload").GetProperty("token").GetString();
                if (string.IsNullOrEmpty(token)) throw new InvalidOperationException("gateway auth_ok 缺少 token");
                return token;
            }
            throw new InvalidOperationException("gateway 连接在 auth_ok 前关闭");
        }

        /// <summary>订阅服务器帧；返回退订函数。 Subscribes to server frames; returns an unsubscribe action.</summary>
        public Action On(string type, Action<NythrosFrame> cb)
        {
            if (!_handlers.TryGetValue(type, out var list)) _handlers[type] = list = new List<Action<NythrosFrame>>();
            list.Add(cb);
            return () => list.Remove(cb);
        }

        /// <summary>发送一帧并等待同 requestId 回执（错误帧与 room:ok 等回显路由）。</summary>
        public Task<NythrosFrame> RequestAsync(string type, Dictionary<string, object?> payload,
            TimeSpan? timeout = null, CancellationToken ct = default)
        {
            var requestId = $"req-{_username}-{++_seq}";
            var tcs = new TaskCompletionSource<NythrosFrame>(TaskCreationOptions.RunContinuationsAsynchronously);
            lock (_pending) _pending[requestId] = tcs;
            _ = SendOneAsync(type, payload, requestId, ct)
                .ContinueWith(t =>
                {
                    if (t.IsFaulted) { lock (_pending) _pending.Remove(requestId); tcs.TrySetException(t.Exception!); }
                }, CancellationToken.None);
            var linked = CancellationTokenSource.CreateLinkedTokenSource(ct);
            linked.CancelAfter(timeout ?? TimeSpan.FromSeconds(10));
            linked.Token.Register(() =>
            {
                if (tcs.TrySetException(new TimeoutException($"{type} 回执超时（{requestId}）")))
                    lock (_pending) _pending.Remove(requestId);
            });
            return tcs.Task;
        }

        /// <summary>火发一帧（move/attack 等无回执路由：用 On 订阅世界帧确认）。</summary>
        public Task SendAsync(string type, Dictionary<string, object?> payload, CancellationToken ct = default)
            => SendOneAsync(type, payload, null, ct);

        private async Task SendOneAsync(string type, Dictionary<string, object?> payload,
            string? requestId, CancellationToken ct)
        {
            if (_ws is not { State: WebSocketState.Open }) throw new InvalidOperationException("Map 连接未建立");
            await SendBatchAsync(new List<NythrosFrame>
                { new() { Type = type, RequestId = requestId, Payload = payload } }, ct).ConfigureAwait(false);
        }

        private async Task SendBatchAsync(List<NythrosFrame> frames, CancellationToken ct)
        {
            var bytes = NythrosCodec.EncodeBatch(frames);
            await _ws!.SendAsync(bytes, WebSocketMessageType.Binary, true, ct).ConfigureAwait(false);
        }

        private async Task ReadLoopAsync(CancellationToken ct)
        {
            var buffer = new byte[64 * 1024];
            while (!ct.IsCancellationRequested && _ws is { State: WebSocketState.Open })
            {
                WebSocketReceiveResult result;
                using var message = new MemoryStream();
                do
                {
                    result = await _ws.ReceiveAsync(buffer, ct).ConfigureAwait(false);
                    if (result.MessageType == WebSocketMessageType.Close)
                    {
                        FailAllPending(new InvalidOperationException("连接已关闭"));
                        return;
                    }
                    message.Write(buffer, 0, result.Count);
                } while (!result.EndOfMessage);

                if (result.MessageType != WebSocketMessageType.Binary || message.Length == 0) continue;
                List<NythrosFrame> frames;
                try { frames = NythrosCodec.DecodeBatch(message.ToArray()); }
                catch (NythrosCodec.NythrosProtocolException) { continue; } // 解码失败静默（SDK 同口径）
                foreach (var frame in frames) Dispatch(frame);
            }
        }

        private void Dispatch(NythrosFrame frame)
        {
            if (frame.RequestId != null)
            {
                TaskCompletionSource<NythrosFrame>? tcs;
                lock (_pending)
                {
                    if (_pending.TryGetValue(frame.RequestId, out tcs)) _pending.Remove(frame.RequestId);
                }
                tcs?.TrySetResult(frame);
            }
            if (_handlers.TryGetValue(frame.Type, out var list))
                foreach (var cb in list.ToArray()) cb(frame);
        }

        private Task<NythrosFrame> WaitFrameAsync(string type, TimeSpan timeout, CancellationToken ct)
        {
            var tcs = new TaskCompletionSource<NythrosFrame>(TaskCreationOptions.RunContinuationsAsynchronously);
            var unsub = On(type, f => tcs.TrySetResult(f));
            var linked = CancellationTokenSource.CreateLinkedTokenSource(ct);
            linked.CancelAfter(timeout);
            linked.Token.Register(() => { tcs.TrySetException(new TimeoutException($"{type} 超时")); unsub(); });
            return tcs.Task.ContinueWith(t => { unsub(); return t; }, CancellationToken.None).Unwrap();
        }

        private void FailAllPending(Exception reason)
        {
            lock (_pending)
            {
                foreach (var tcs in _pending.Values) tcs.TrySetException(reason);
                _pending.Clear();
            }
        }

        /// <summary>登出并关闭（火发 logout 帧）。</summary>
        public void Dispose()
        {
            if (_disposed) return;
            _disposed = true;
            try { SendOneAsync("logout", new Dictionary<string, object?>(), null, CancellationToken.None).Wait(100); }
            catch { /* 已关闭静默 Already closed; silent. */ }
            _cts.Cancel();
            _ws?.Dispose();
            _cts.Dispose();
            FailAllPending(new InvalidOperationException("客户端已关闭"));
        }
    }
}
