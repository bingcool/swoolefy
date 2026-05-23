<?php

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
