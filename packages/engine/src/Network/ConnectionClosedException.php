<?php

declare(strict_types=1);

namespace Nythros\Network;

/**
 * 连接已关闭异常：对已关闭连接执行发送等操作时抛出。
 * Exception thrown when an operation such as send is attempted on a closed connection.
 */
final class ConnectionClosedException extends \RuntimeException
{
}
