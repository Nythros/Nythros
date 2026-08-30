<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * 解码异常：字节串不是合法协议包时抛出。
 * Decode exception: thrown when the byte string is not a valid protocol packet.
 */
class DecodeException extends ProtocolException
{
}
