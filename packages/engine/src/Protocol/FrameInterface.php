<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * 帧接口：任何可提供原始字节的协议包载体都必须实现它。
 * Frame interface: any protocol packet carrier that can expose raw bytes must implement it.
 */
interface FrameInterface
{
    /**
     * 返回帧的原始字节内容。
     * Return the raw byte content of the frame.
     *
     * @return string 帧字节 Frame bytes.
     */
    public function bytes(): string;
}
