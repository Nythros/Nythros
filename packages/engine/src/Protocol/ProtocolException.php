<?php

declare(strict_types=1);

namespace Nythros\Protocol;

/**
 * 协议异常：协议层编解码失败时抛出。
 * Protocol exception: thrown when encoding/decoding fails at the protocol layer.
 */
class ProtocolException extends \RuntimeException
{
}
