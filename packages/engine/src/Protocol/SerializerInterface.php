<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * 序列化器接口：负责 Message 与帧字节之间的双向转换。
 * 热路径扩展点（architecture.md §5）：序列化替换点 = 本接口 + 装配层选择，实现可按需替换
 * （JsonSerializer / MsgpackSerializer / BinaryBatchSerializer 等），引擎不预设偏好（ADR-022 双轨制）。
 * Serializer interface: responsible for bidirectional conversion between Message and frame bytes.
 * Hot-path extension point (architecture.md §5): the serialization swap point is this interface plus assembly-layer
 * selection; implementations are swappable as needed (JsonSerializer / MsgpackSerializer / BinaryBatchSerializer, etc.)
 * with no engine-side preference (the ADR-022 dual-track decision).
 */
interface SerializerInterface
{
    /**
     * 将消息编码为帧。
     * Encode a message into a frame.
     *
     * @param Message $message 待编码的协议消息 Protocol message to encode.
     * @return FrameInterface 编码后的帧 Encoded frame.
     * @throws ProtocolException 编码失败 Encoding failed.
     */
    public function encode(Message $message): FrameInterface;

    /**
     * 将帧字节解码为消息。
     * Decode frame bytes into a message.
     *
     * @param FrameInterface $frame 待解码的帧 Frame to decode.
     * @return Message 解码后的协议消息 Decoded protocol message.
     * @throws DecodeException 字节串不是合法协议包 Byte string is not a valid protocol packet.
     */
    public function decode(FrameInterface $frame): Message;
}
