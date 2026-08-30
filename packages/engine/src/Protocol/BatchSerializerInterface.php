<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * 批量序列化器契约：在单帧序列化（SerializerInterface）之上增加「一包多帧」的批量编码/解码。
 * 批量格式由实现约定（如 BinaryBatchSerializer 的长度前缀 + 枚举压缩）；客户端到服务器方向
 * 以「批量包含 1 帧」的形式发送单条请求，服务器到客户端方向以「批量包含 N 帧」的形式帧末统一下发。
 * Batch serializer contract: on top of single-frame serialization (SerializerInterface) it adds
 * "many frames in one packet" batch encoding/decoding. The batch layout is implementation-defined (e.g. the
 * BinaryBatchSerializer's length-prefix + enum compression); client→server requests travel as a batch holding
 * exactly one frame, while server→client broadcasts travel as a batch holding N frames flushed at frame end.
 */
interface BatchSerializerInterface extends SerializerInterface
{
    /**
     * 将多条消息编码为一个批量包字节串。
     * Encodes a list of messages into one batch packet byte string.
     *
     * @param list<Message> $messages 待编码消息 Messages to encode.
     * @return string 批量包字节 Batch packet bytes.
     * @throws ProtocolException 未知帧类型/字段或编码失败 Unknown frame type/key or encoding failure.
     */
    public function encodeBatch(array $messages): string;

    /**
     * 解码批量包字节为消息列表（空包返回空列表）。
     * Decodes a batch packet into a list of messages (an empty packet yields an empty list).
     *
     * @param string $bytes 批量包字节 Batch packet bytes.
     * @return list<Message> 解码后的消息列表 Decoded messages.
     * @throws DecodeException 包结构非法/未知编码 Illegal packet structure or unknown codes.
     */
    public function decodeBatch(string $bytes): array;
}
