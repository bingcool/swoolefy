<?php

declare(strict_types=1);

namespace PhpUintTest\Websocket;

use Swoole\Coroutine;
use PhpUintTest\TestCase;
use PhpUintTest\Websocket\Support\SmokeTestSupport;
use PhpUintTest\Websocket\Support\WebsocketServerUnavailableException;
use Throwable;

/**
 * Websocket 冒烟基类：真 WebsocketService + skip-if-down。
 * 靠 suite「websocket」隔离，勿标会被默认 exclude 的 @group。
 */
abstract class WebsocketSmokeTestCase extends TestCase
{
    protected static string $wsHost;

    protected static int $wsPort;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (extension_loaded('swoole') && defined('SWOOLE_HOOK_ALL')) {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        }
        [$host, $port] = SmokeTestSupport::endpoint();
        self::$wsHost = $host;
        self::$wsPort = $port;
        try {
            SmokeTestSupport::ensureServerAvailable($host, $port);
        } catch (WebsocketServerUnavailableException $e) {
            if (SmokeTestSupport::shouldSkipIfServerDown()) {
                self::markTestSkipped($e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    protected function runInCoroutine(callable $fn): mixed
    {
        $result = null;
        $error = null;
        Coroutine\run(static function () use ($fn, &$result, &$error): void {
            try {
                $result = $fn();
            } catch (Throwable $e) {
                $error = $e;
            }
        });
        if ($error instanceof Throwable) {
            throw $error;
        }

        return $result;
    }
}
