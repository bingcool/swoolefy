<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket\Support;

use RuntimeException;

/**
 * WebsocketService 探活失败（供 Smoke 用例 markTestSkipped）。
 */
final class WebsocketServerUnavailableException extends RuntimeException
{
}
