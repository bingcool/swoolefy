<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\LoadBalancer;

use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;

/**
 * 随机负载均衡。
 */
final class RandomLoadBalancer extends AbstractLoadBalancer
{
    public function choose(): ?ServiceInstance
    {
        $instances = $this->getInstances();
        $count = \count($instances);
        if ($count <= 0) {
            return null;
        }
        $index = (new \Random\Randomizer())->getInt(0, $count - 1);

        return $instances[$index] ?? null;
    }
}
