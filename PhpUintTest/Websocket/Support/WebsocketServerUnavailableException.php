<?php

declare(strict_types=1);

namespace PhpUintTest\Websocket\Support;

use RuntimeException;

/**
 * WebsocketService 探活失败（供 Smoke 用例 markTestSkipped）。
 */
final class WebsocketServerUnavailableException extends RuntimeException
{
}
