<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use PHPUintTest\TestCase;
use Swoole\Http\Status;
use Swoolefy\Core\Coroutine\PoolFallbackLease;
use Swoolefy\Core\Coroutine\PoolsHandler;
use Swoolefy\Exception\ComponentPoolExhaustedException;

/**
 * fallback 配额账本与 503 文案（无需协程）。
 *
 * PoolsHandler 的 inflight 与 Channel 无关：reserve/release 是无 yield 整数账本。
 */
final class ComponentPoolFallbackQuotaTest extends TestCase
{
    /** 预占成功才 ++inflight；超额立即 false，不得 sleep。 */
    public function testReserveAndReleaseStayWithinMax(): void
    {
        $pool = new PoolsHandler();
        $pool->setFallbackPolicy(true, 2);

        $this->assertTrue($pool->reserveFallback());
        $this->assertTrue($pool->reserveFallback());
        $this->assertFalse($pool->reserveFallback(), 'third reserve must fail immediately');
        $this->assertSame(2, $pool->getFallbackInflight());

        $pool->releaseFallback();
        $this->assertSame(1, $pool->getFallbackInflight());
        $this->assertTrue($pool->reserveFallback());
        $this->assertFalse($pool->reserveFallback());
    }

    /** enabled=false 或 max=0 时永远 reserve 失败。 */
    public function testDisabledOrZeroMaxNeverReserves(): void
    {
        $disabled = new PoolsHandler();
        $disabled->setFallbackPolicy(false, 40);
        $this->assertFalse($disabled->reserveFallback());
        $this->assertSame(0, $disabled->getFallbackInflight());

        $zero = new PoolsHandler();
        $zero->setFallbackPolicy(true, 0);
        $this->assertFalse($zero->reserveFallback());
    }

    /** clear 与析构都会 release，只允许 --inflight 一次。 */
    public function testLeaseReleaseIsIdempotent(): void
    {
        $pool = new PoolsHandler();
        $pool->setFallbackPolicy(true, 1);
        $this->assertTrue($pool->reserveFallback());

        $lease = new PoolFallbackLease($pool);
        $lease->release();
        $lease->release();
        $this->assertTrue($lease->isReleased());
        $this->assertSame(0, $pool->getFallbackInflight());
        $this->assertTrue($pool->reserveFallback());
    }

    /** 类外 isset($dto->__coroutineId) 必须为 true，否则 get() 会误判跨协程。 */
    public function testContainerObjectDtoIssetExposesLeaseAttributes(): void
    {
        $dto = new \Swoolefy\Core\Dto\ContainerObjectDto();
        $dto->__coroutineId = 7;
        $this->assertTrue(isset($dto->__coroutineId));
        $this->assertSame(7, $dto->__coroutineId);
        $this->assertFalse(isset($dto->__fallbackLease));
    }

    /** HTTP 503 + 中文「达到最大上限」文案，contextData 只含容量账本。 */
    public function testExhaustedExceptionMessageIsChineseWithLimits(): void
    {
        $e = new ComponentPoolExhaustedException('db', 20, 40, 40);
        $this->assertSame(Status::SERVICE_UNAVAILABLE, $e->getCode());
        $this->assertSame(
            '连接池[db]已达到最大上限60（池容量20 + 降级额度40，当前降级占用40）',
            $e->getMessage(),
        );
        $this->assertSame([
            'pool' => 'db',
            'max_pool_num' => 20,
            'fallback_max' => 40,
            'fallback_inflight' => 40,
            'total_cap' => 60,
        ], $e->getContextData());
    }
}
