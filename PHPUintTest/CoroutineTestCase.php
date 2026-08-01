<?php

declare(strict_types=1);

namespace PHPUintTest;

use Swoole\Coroutine;
use Throwable;

/**
 * 协程单测基类：Support stub + 用例内单层 Coroutine\run。
 *
 * 禁止在 $fn 内再套 Coroutine\run。
 * （Swoole 6+ enableCoroutine 需 int flags，此处不强制 hook，由 Coroutine\run 调度即可。）
 */
abstract class CoroutineTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/src/Support/Tests/SwoolefyTestBootstrap.php';
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

    /**
     * 可选：用例前后 coroutine_num 差值不超过阈值（泄漏加分项）。
     *
     * @param callable(): void $fn
     */
    protected function assertCoroutineLeakWithin(callable $fn, int $maxDelta = 2): void
    {
        $before = (int) (Coroutine::stats()['coroutine_num'] ?? 0);
        $this->runInCoroutine($fn);
        $after = (int) (Coroutine::stats()['coroutine_num'] ?? 0);
        $this->assertLessThanOrEqual(
            $maxDelta,
            $after - $before,
            sprintf('coroutine_num leak: before=%d after=%d (maxDelta=%d)', $before, $after, $maxDelta),
        );
    }
}
