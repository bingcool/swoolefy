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

namespace Swoolefy\Support\Nacos\Discovery\Contract;

use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;

/**
 * 服务发现驱动：从注册中心拉取实例列表。
 */
interface DiscoveryDriverInterface
{
    /**
     * @return ServiceInstance[]
     */
    public function getInstances(string $serviceName): array;
}
