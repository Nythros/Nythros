<?php

declare(strict_types=1);

namespace Nythros\Demo\Protocol;

use Nythros\Protocol\BinaryBatchSerializer;
use Nythros\Protocol\ProtocolVocabulary;

/**
 * Map 频道编解码器工厂：把 FrameType/PayloadKey 两枚权威枚举组装成引擎的通用词汇表，
 * 返回配置好的二进制批量序列化器。业务层只依赖这个工厂拿编解码器，不直接构造词表。
 * Map-channel codec factory: assembles the engine's generic vocabulary from the two authoritative enums
 * (FrameType/PayloadKey) and returns a configured binary batch serializer. Business code obtains the codec
 * from this factory and never builds the vocabulary by hand.
 */
final class MapCodec
{
    /**
     * 创建 Map 频道二进制编解码器（不编码 timestamp，客户端以帧边界为时间基准，协议更省）。
     * Creates the Map-channel binary codec (timestamp omitted — the client uses frame boundaries as its clock).
     */
    public static function create(): BinaryBatchSerializer
    {
        return new BinaryBatchSerializer(new ProtocolVocabulary(
            typeCodes: FrameType::codeMap(),
            keyCodes: PayloadKey::codeMap(),
        ));
    }

    /**
     * 清单指纹（ADR-030）：typeCodes+keyCodes 两份码表的稳定哈希,装配期注入 auth_ok 回传,
     * 客户端与自身编译期生成物比对——不一致即断开升级(A 模型:拒绝而非适配)。
     * Manifest fingerprint (ADR-030): a stable hash over both code maps, echoed via auth_ok so clients can
     * reject on mismatch against their build-time generated tables (Model A: reject, never adapt).
     */
    public static function manifestVersion(): int
    {
        return crc32((string) json_encode([FrameType::codeMap(), PayloadKey::codeMap()]));
    }
}
