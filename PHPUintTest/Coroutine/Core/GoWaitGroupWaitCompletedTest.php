<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Exception\SystemException;

/**
 * P1-01：wait 结束后 waitCompleted 必须保持 true，late callback 不得再改运行态。
 */
final class GoWaitGroupWaitCompletedTest extends CoroutineTestCase
{
    public function testNormalCompletionReturnsFullResult(): void
    {
        $this->runInCoroutine(function (): void {
            $wg = new GoWaitGroup();
            $wg->add(2);
            $wg->done('a', 'A');
            $wg->done('b', 'B');

            $result = $wg->wait(1.0);
            $this->assertSame('A', $result['a']);
            $this->assertSame('B', $result['b']);
        });
    }

    public function testTimeoutThrowsSystemException(): void
    {
        $this->expectException(SystemException::class);
        $this->expectExceptionMessage('timed out');
        $this->runInCoroutine(function (): void {
            $wg = new GoWaitGroup();
            $wg->add(1);
            $wg->wait(0.05);
        });
    }

    public function testTimeoutLateDoneDoesNotChangeCountOrPushChannel(): void
    {
        $this->runInCoroutine(function (): void {
            $wg = new GoWaitGroup();
            $wg->add(1);

            try {
                $wg->wait(0.05);
                $this->fail('expected timeout');
            } catch (SystemException $e) {
                $this->assertStringContainsString('timed out', $e->getMessage());
            }

            $this->assertTrue($this->waitCompleted($wg), 'timeout must keep waitCompleted sticky');
            $this->assertSame(0, $wg->count());

            $channel = $this->waitGroupChannel($wg);
            $lenBefore = $channel->length();
            $wg->done('late', 'should-ignore');
            $wg->initResult('late-init', 'nope');

            $this->assertSame(0, $wg->count(), 'late done must not change count');
            $this->assertSame($lenBefore, $channel->length(), 'late done must not push channel');
            $this->assertSame([], $this->waitGroupResult($wg), 'late initResult must not rewrite result');
        });
    }

    public function testSuccessLateDoneIsIgnored(): void
    {
        $this->runInCoroutine(function (): void {
            $wg = new GoWaitGroup();
            $wg->add(1);
            $wg->done('ok', 'value');
            $result = $wg->wait(1.0);

            $this->assertSame(['ok' => 'value'], $result);
            $this->assertTrue($this->waitCompleted($wg));

            $wg->done('late', 'ignored');
            $this->assertSame(0, $wg->count());
            $this->assertSame([], $this->waitGroupResult($wg));

            $this->expectException(SystemException::class);
            $wg->wait(1.0);
        });
    }

    public function testNewInstanceCanWaitAfterPreviousTimeout(): void
    {
        $this->runInCoroutine(function (): void {
            $first = new GoWaitGroup();
            $first->add(1);
            try {
                $first->wait(0.05);
            } catch (SystemException) {
            }
            $this->assertTrue($this->waitCompleted($first));

            $second = new GoWaitGroup();
            $second->add(1);
            $second->done('next', 1);
            $this->assertSame(['next' => 1], $second->wait(1.0));
        });
    }

    public function testFailFastEndPathKeepsWaitCompletedSticky(): void
    {
        $this->runInCoroutine(function (): void {
            $wg = new GoWaitGroup();
            $wg->add(2);
            $errorChannel = new Channel(2);
            $errorChannel->push(new RuntimeException('fail-fast'), 0);
            $wg->done('fast', null);

            $waitWithError = new ReflectionMethod(GoWaitGroup::class, 'waitWithErrorChannel');
            $waitWithError->setAccessible(true);

            try {
                $waitWithError->invoke($wg, 1.0, $errorChannel);
                $this->fail('expected failFast error');
            } catch (RuntimeException $e) {
                $this->assertSame('fail-fast', $e->getMessage());
            }

            $this->assertTrue($this->waitCompleted($wg), 'failFast must keep waitCompleted sticky');
            $countAfter = $wg->count();
            $wg->done('after', 'nope');
            $this->assertSame($countAfter, $wg->count(), 'late done after failFast must be ignored');
        });
    }

    private function waitCompleted(GoWaitGroup $wg): bool
    {
        $prop = new ReflectionProperty(GoWaitGroup::class, 'waitCompleted');
        $prop->setAccessible(true);

        return (bool) $prop->getValue($wg);
    }

    private function waitGroupResult(GoWaitGroup $wg): array
    {
        $prop = new ReflectionProperty(GoWaitGroup::class, 'result');
        $prop->setAccessible(true);
        $value = $prop->getValue($wg);

        return is_array($value) ? $value : [];
    }

    private function waitGroupChannel(GoWaitGroup $wg): Channel
    {
        $prop = new ReflectionProperty(GoWaitGroup::class, 'channel');
        $prop->setAccessible(true);
        $channel = $prop->getValue($wg);
        $this->assertInstanceOf(Channel::class, $channel);

        return $channel;
    }
}
