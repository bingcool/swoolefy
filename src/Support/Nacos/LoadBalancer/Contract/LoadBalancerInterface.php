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

namespace Swoolefy\Support\Nacos\LoadBalancer\Contract;

use Swoolefy\Support\Nacos\Discovery\DiscoveryClient;
use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;

/**
 * 客户端负载均衡器。
 */
interface LoadBalancerInterface
{
    /**
     * @return ServiceInstance[]
     */
    public function getInstances(): array;

    public function getDiscoveryClient(): ?DiscoveryClient;

    public function choose(): ?ServiceInstance;
}
