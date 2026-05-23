<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\LoadBalancer;

use Swoolefy\Support\Nacos\Discovery\DiscoveryClient;
use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;
use Swoolefy\Support\Nacos\LoadBalancer\Contract\LoadBalancerInterface;

abstract class AbstractLoadBalancer implements LoadBalancerInterface
{
    /** @var ServiceInstance[] */
    private array $instances = [];

    public function __construct(
        private readonly ?DiscoveryClient $discoveryClient = null,
        array $instances = [],
    ) {
        if (null !== $discoveryClient) {
            $this->instances = $discoveryClient->getInstances();
        } else {
            $this->instances = $instances;
        }
    }

    public function getInstances(): array
    {
        if (null !== $this->discoveryClient) {
            return $this->discoveryClient->getInstances();
        }

        return $this->instances;
    }

    public function getDiscoveryClient(): ?DiscoveryClient
    {
        return $this->discoveryClient;
    }
}
