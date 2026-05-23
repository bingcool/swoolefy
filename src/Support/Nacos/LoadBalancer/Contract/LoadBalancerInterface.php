<?php

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
