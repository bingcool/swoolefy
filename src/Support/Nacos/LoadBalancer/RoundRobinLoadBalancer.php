<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\LoadBalancer;

use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;

/**
 * 轮询负载均衡。
 */
final class RoundRobinLoadBalancer extends AbstractLoadBalancer
{
    private int $position = 0;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $count = \count($this->getInstances());
        if ($count > 0) {
            $this->position = (new \Random\Randomizer())->getInt(0, $count - 1);
        }
    }

    public function choose(): ?ServiceInstance
    {
        $instances = $this->getInstances();
        $maxIndex = \count($instances) - 1;
        if ($maxIndex < 0) {
            return null;
        }

        if ($this->position > $maxIndex) {
            $this->position = 0;
        }

        $instance = $instances[$this->position] ?? null;
        if (++$this->position > $maxIndex) {
            $this->position = 0;
        }

        return $instance;
    }
}
