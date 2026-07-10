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

namespace Swoolefy\Support\Nacos\LoadBalancer;

use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;

/**
 * 权重负载均衡。
 */
final class WeightLoadBalancer extends AbstractLoadBalancer
{
    public function choose(): ?ServiceInstance
    {
        $instances = $this->getInstances();
        $weightSum = 0.0;
        foreach ($instances as $instance) {
            if ($instance->weight > 0) {
                $weightSum += $instance->weight;
            }
        }

        if ($weightSum <= 0) {
            return null;
        }

        $randomizer = new \Random\Randomizer();
        $scale = 1000;
        $total = max(1, (int) round($weightSum * $scale));
        $randomValue = $randomizer->getInt(1, $total) / $scale;
        foreach ($instances as $instance) {
            $randomValue -= $instance->weight;
            if ($randomValue <= 0) {
                return $instance;
            }
        }

        return $instances[\array_key_last($instances)] ?? null;
    }
}
