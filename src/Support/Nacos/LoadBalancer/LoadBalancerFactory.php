<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\LoadBalancer;

use Swoolefy\Exception\NacosDiscoveryException;
use Swoolefy\Support\Nacos\Discovery\DiscoveryClient;
use Swoolefy\Support\Nacos\Discovery\DiscoveryConfig;
use Swoolefy\Support\Nacos\LoadBalancer\Contract\LoadBalancerInterface;

final class LoadBalancerFactory
{
    /**
     * @param class-string<LoadBalancerInterface>|string $type random|round_robin|weight 或自定义类名
     */
    public static function create(string $type, DiscoveryClient $discoveryClient): LoadBalancerInterface
    {
        $type = strtolower(trim($type));

        return match ($type) {
            DiscoveryConfig::LOAD_BALANCER_RANDOM, 'randomloadbalancer' => new RandomLoadBalancer($discoveryClient),
            DiscoveryConfig::LOAD_BALANCER_ROUND_ROBIN, 'roundrobinloadbalancer', 'round_robin', 'roundrobin' => new RoundRobinLoadBalancer($discoveryClient),
            DiscoveryConfig::LOAD_BALANCER_WEIGHT, 'weightloadbalancer' => new WeightLoadBalancer($discoveryClient),
            default => self::createFromClass($type, $discoveryClient),
        };
    }

    private static function createFromClass(string $class, DiscoveryClient $discoveryClient): LoadBalancerInterface
    {
        if (!class_exists($class)) {
            throw NacosDiscoveryException::throw('unsupported load balancer: ' . $class);
        }

        $balancer = new $class($discoveryClient);
        if (!$balancer instanceof LoadBalancerInterface) {
            throw NacosDiscoveryException::throw('load balancer must implement LoadBalancerInterface: ' . $class);
        }

        return $balancer;
    }
}
