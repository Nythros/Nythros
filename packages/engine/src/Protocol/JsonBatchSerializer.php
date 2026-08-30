<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * JSON 批量序列化器：在单条序列化器之上提供「一包多帧」的批量编解码。
 * 用途：既让二进制尚未接管的路径（单测 harness、开发调试、降级）以自描述形式观察批量帧，又不破坏社交/网关层
 * 仍在使用的单帧格式。批量包 = 容器数组，数组元素即单帧对象；解码时兼容「单帧对象」输入
 * （客户端请求常以单帧对象发送），一律归一为消息列表。
 *
 * 热路径扩展点（architecture.md §5 / ADR-022 双轨制装配点）：
 * - 单条序列化器经构造注入（缺省 JsonSerializer 保持既有行为），encode/decode 与逐帧解码全部复用注入实例；
 * - 批量容器格式跟随单条序列化器：缺省 JSON 数组（json_encode/json_decode）；注入 MsgpackSerializer 时
 *   传入其 pack/unpack 作为容器编解码器，容器即 msgpack 数组。选择发生在装配层（如 run-worker 按环境变量），
 *   本类不感知具体格式。
 *
 * Batch serializer on top of an injectable single-frame serializer, providing "many frames in one packet" batch
 * encode/decode. It lets non-binary paths (unit-test harness, debugging, transition) keep a self-describing shape,
 * while the single-frame format stays untouched for the social layer. A batch is a container array whose elements are
 * frame objects; decoding accepts either a batch array or a single frame object (clients often send single-frame
 * requests) and normalizes to a message list.
 *
 * Hot-path extension point (architecture.md §5 / the ADR-022 dual-track assembly point): the single-frame serializer is
 * constructor-injected (default JsonSerializer keeps the existing behavior), and encode/decode plus per-frame decoding
 * all reuse that instance; the batch container format follows the single serializer — a JSON array by default
 * (json_encode/json_decode), or a msgpack array when MsgpackSerializer is injected together with its pack/unpack as the
 * container codec. The choice happens at the assembly layer (e.g. run-worker via an env var); this class stays
 * format-agnostic.
 * @internal 引擎内部实现，非公开 API。Engine-internal implementation, not part of the public API.
 */
final class JsonBatchSerializer implements BatchSerializerInterface
{
    /** @var (callable(mixed): string)|null 值级容器编码器；null = 缺省 JSON。 Value-level container encoder; null = default JSON. */
    private readonly mixed $pack;

    /** @var (callable(string): mixed)|null 值级容器解码器；null = 缺省 JSON。 Value-level container decoder; null = default JSON. */
    private readonly mixed $unpack;

    /**
     * @param SerializerInterface $single 单条序列化器（缺省 JsonSerializer，保持既往行为）。 Single-frame serializer (default JsonSerializer, preserving prior behavior).
     * @param (callable(mixed): string)|null $pack 批量容器/元素的值级编码器（缺省 json_encode，字节与既往一致）。 Value-level encoder for the batch container and elements (default json_encode, byte-identical to before).
     * @param (callable(string): mixed)|null $unpack 批量容器的值级解码器（缺省 json_decode 关联数组）。 Value-level decoder for the batch container (default json_decode as associative arrays).
     */
    public function __construct(
        private readonly SerializerInterface $single = new JsonSerializer(),
        ?callable $pack = null,
        ?callable $unpack = null,
    ) {
        $this->pack = $pack;
        $this->unpack = $unpack;
    }

    /** 单帧编码：委托注入的单条序列化器。 @throws ProtocolException */
    public function encode(Message $message): FrameInterface
    {
        return $this->single->encode($message);
    }

    /** 批量编码：容器数组（元素为单帧对象），容器格式跟随单条序列化器。 @throws ProtocolException */
    public function encodeBatch(array $messages): string
    {
        return $this->packValue(array_map(static fn (Message $m): array => [
            'type' => $m->type,
            'requestId' => $m->requestId,
            'timestamp' => $m->timestamp,
            'payload' => $m->payload,
        ], $messages));
    }

    /** 单帧解码：委托注入的单条序列化器。 @throws DecodeException */
    public function decode(FrameInterface $frame): Message
    {
        return $this->single->decode($frame);
    }

    /** 批量解码：接受批量容器数组或单个帧对象，归一为消息列表。 @throws DecodeException */
    public function decodeBatch(string $bytes): array
    {
        if (trim($bytes) === '') {
            return [];
        }
        $decoded = $this->unpackContainer($bytes);
        if (!is_array($decoded)) {
            throw new DecodeException('批量包顶层必须是数组或对象');
        }

        // 单帧对象（非 list 顶层）视为 1 帧批量：原始字节直接交单条序列化器。
        // A single frame object (a non-list top level) is treated as a one-frame batch: raw bytes go straight to the single serializer.
        if (!array_is_list($decoded)) {
            return [$this->single->decode(new Frame($bytes))];
        }

        // list 则逐元素经容器编码器还原帧字节后解码——复用注入实例，不逐帧新建（原 P2 泄漏点）。
        // For lists each element's frame bytes are rebuilt via the container encoder and decoded — reusing the injected instance instead of per-frame construction (the former P2 leak).
        $messages = [];
        foreach ($decoded as $frameData) {
            if (!is_array($frameData)) {
                throw new DecodeException('批量包元素必须是帧对象');
            }
            $messages[] = $this->single->decode(new Frame($this->packValue($frameData)));
        }

        return $messages;
    }

    /** 容器/元素值级编码：缺省 JSON（UNICODE/斜杠不转义、出错抛异常），否则调用注入编码器。 @throws ProtocolException */
    private function packValue(mixed $value): string
    {
        if ($this->pack === null) {
            try {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new ProtocolException('JSON 批量编码失败：' . $e->getMessage(), 0, $e);
            }
        }

        return ($this->pack)($value);
    }

    /** 容器值级解码：缺省 JSON（关联数组、深度上限 512、出错抛异常），否则调用注入解码器。 @throws DecodeException */
    private function unpackContainer(string $bytes): mixed
    {
        if ($this->unpack === null) {
            try {
                return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new DecodeException('非法 JSON：' . $e->getMessage(), 0, $e);
            }
        }

        return ($this->unpack)($bytes);
    }
}
