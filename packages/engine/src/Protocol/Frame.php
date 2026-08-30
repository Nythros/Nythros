<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * 帧（Frame）：协议消息的原始字节承载对象。
 * Frame: the raw byte carrier of a protocol message.
 */
final readonly class Frame implements FrameInterface
{
    /**
     * 构造帧。
     * Create a frame.
     *
     * @param string $bytes 帧的原始字节内容 Raw byte content of the frame.
     */
    public function __construct(private string $bytes)
    {
    }

    /**
     * 返回帧的原始字节内容。
     * Return the raw byte content of the frame.
     *
     * @return string 帧字节 Frame bytes.
     */
    public function bytes(): string
    {
        return $this->bytes;
    }
}
