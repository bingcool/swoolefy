<?php

declare(strict_types=1);

namespace Test\Scripts\TestScript;

use Swoolefy\Exception\SystemException;
use Swoolefy\Script\MainCliScript;
use Swoolefy\Support\Nacos\ConfigFetcher;
use Swoolefy\Support\Nacos\Discovery\DiscoveryClient;
use Swoolefy\Support\Nacos\Discovery\DiscoveryConfig;
use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;
use Swoolefy\Support\Nacos\LoadBalancer\LoadBalancerFactory;
use Swoolefy\Support\Nacos\LoadBalancer\RandomLoadBalancer;
use Swoolefy\Support\Nacos\LoadBalancer\RoundRobinLoadBalancer;
use Swoolefy\Support\Nacos\LoadBalancer\WeightLoadBalancer;
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Support\Nacos\NacosServiceRegisterConfig;
use Swoolefy\Support\Nacos\ServiceConfig;
use Swoolefy\Support\Nacos\ServiceRegister;

/**
 * Nacos SDK smoke test.
 *
 * php script.php start Test --c=nacos:test --a=testNacos
 * php script.php start Test --c=nacos:test --a=testDiscovery
 */
class NacosTest extends MainCliScript
{
    public const command = 'nacos:test';

    public function handle(): void
    {
        $action = $this->getOption('a');
        if (!\is_string($action) || '' === $action) {
            $action = 'testNacos';
        }
        if (!method_exists($this, $action)) {
            throw new SystemException('method ' . $action . ' not exists in class=' . static::class);
        }
        $this->{$action}();
    }

    /**
     * 配置中心 + 服务注册冒烟测试。
     */
    public function testNacos(): void
    {
        $nacosConfig = $this->loadNacosConfig();
        $serviceConfig = ServiceConfig::load();
        $configFetcher = new ConfigFetcher($nacosConfig, $serviceConfig);
        $serviceRegistrar = ServiceRegister::create();

        $dataId = $serviceConfig->dataId;
        $group = $serviceConfig->group;
        $content = 'APP_NAME: Test';

        $configFetcher->set($content, $dataId, $group);
        echo "config set ok: dataId={$dataId}, group={$group}\n";

        usleep(100_000);
        $value = $configFetcher->get($dataId, $group);
        if ($value !== $content) {
            throw new SystemException(sprintf('config get mismatch, expected=%s, actual=%s', $content, $value));
        }
        echo "config get ok: {$value}\n";

        [$registerIp, $registerPort, $registerName] = $this->resolveServiceRegisterParams($nacosConfig);

        $serviceRegistrar->register($registerIp, $registerPort, $registerName, startHeartbeat: false);
        echo "instance register ok: {$registerIp}:{$registerPort} -> {$registerName}\n";

        $list = $serviceRegistrar->list($registerName);
        echo 'instance list hosts: ' . count($list->getHosts()) . "\n";

        echo "Nacos config & register test passed\n";
    }

    /**
     * DiscoveryClient 服务发现 + 负载均衡验证。
     */
    public function testDiscovery(): void
    {
        $nacosConfig = $this->loadNacosConfig();
        [$registerIp, $registerPort, $registerName] = $this->resolveServiceRegisterParams($nacosConfig);

        $this->ensureServiceRegistered($registerIp, $registerPort, $registerName);

        $discoveryConfig = DiscoveryConfig::load();
        echo sprintf(
            "discovery config: service=%s, load_balancer=%s, cache_ttl=%ds\n",
            $registerName,
            $discoveryConfig->loadBalancer,
            $discoveryConfig->cacheTtl,
        );

        $client = DiscoveryClient::create($registerName, $nacosConfig, $discoveryConfig);

        $this->assertDiscoveryInstances($client, $registerIp, $registerPort, $registerName);
        $this->assertDiscoveryRefresh($client);
        $this->assertDefaultLoadBalancerChoose($client, $registerIp, $registerPort);
        $this->assertAllLoadBalancers($client, $registerIp, $registerPort);
        $this->assertChooseUri($client, $registerIp, $registerPort);

        echo "DiscoveryClient test passed\n";
    }

    private function loadNacosConfig(): NacosConfig
    {
        return NacosConfig::load();
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function resolveServiceRegisterParams(NacosConfig $nacosConfig): array
    {
        $serviceConfig = NacosServiceRegisterConfig::load();
        $ip = '' !== $serviceConfig->ip ? $serviceConfig->ip : '192.168.1.103';
        $port = $serviceConfig->port > 0 ? $serviceConfig->port : 9501;
        $name = '' !== $serviceConfig->serviceName ? $serviceConfig->serviceName : 'my-service';

        return [$ip, $port, $name];
    }

    private function ensureServiceRegistered(
        string $ip,
        int $port,
        string $serviceName,
    ): void {
        $registrar = ServiceRegister::create();
        $registrar->register($ip, $port, $serviceName, startHeartbeat: false);
        usleep(100_000);
        echo "ensure instance registered: {$ip}:{$port} -> {$serviceName}\n";
    }

    private function assertDiscoveryInstances(
        DiscoveryClient $client,
        string $expectedIp,
        int $expectedPort,
        string $serviceName,
    ): void {
        $instances = $client->getInstances(true);
        if ([] === $instances) {
            throw new SystemException('discovery getInstances returned empty list');
        }

        echo 'getInstances count: ' . count($instances) . "\n";
        foreach ($instances as $instance) {
            echo sprintf(
                "  - %s weight=%s healthy=%s enabled=%s\n",
                $instance->getAddress(),
                $instance->weight,
                $instance->healthy ? 'true' : 'false',
                $instance->enabled ? 'true' : 'false',
            );
        }

        if ($client->getServiceName() !== $serviceName) {
            throw new SystemException('discovery service name mismatch');
        }

        if (!$this->containsInstance($instances, $expectedIp, $expectedPort)) {
            throw new SystemException(sprintf(
                'registered instance %s:%d not found in discovery list',
                $expectedIp,
                $expectedPort,
            ));
        }

        echo "assert getInstances ok\n";
    }

    private function assertDiscoveryRefresh(DiscoveryClient $client): void
    {
        $before = $client->getInstances();
        $after = $client->refresh();

        if ([] === $after) {
            throw new SystemException('discovery refresh returned empty list');
        }

        echo sprintf(
            "assert refresh ok, before=%d, after=%d\n",
            count($before),
            count($after),
        );
    }

    private function assertDefaultLoadBalancerChoose(
        DiscoveryClient $client,
        string $expectedIp,
        int $expectedPort,
    ): void {
        $chosen = $client->choose();
        if (!$chosen instanceof ServiceInstance) {
            throw new SystemException('discovery choose returned null');
        }

        echo sprintf(
            "default load balancer [%s] choose: %s\n",
            $client->getDiscoveryConfig()->loadBalancer,
            $chosen->getUri(),
        );

        if (!$this->isSameEndpoint($chosen, $expectedIp, $expectedPort)
            && !$this->containsInstance($client->getInstances(), $chosen->ip, $chosen->port)) {
            throw new SystemException('chosen instance is not in discovery instance list');
        }

        echo "assert default load balancer choose ok\n";
    }

    private function assertAllLoadBalancers(
        DiscoveryClient $client,
        string $expectedIp,
        int $expectedPort,
    ): void {
        $strategies = [
            DiscoveryConfig::LOAD_BALANCER_RANDOM => RandomLoadBalancer::class,
            DiscoveryConfig::LOAD_BALANCER_ROUND_ROBIN => RoundRobinLoadBalancer::class,
            DiscoveryConfig::LOAD_BALANCER_WEIGHT => WeightLoadBalancer::class,
        ];

        foreach ($strategies as $type => $class) {
            $balancer = LoadBalancerFactory::create($type, $client);
            if (!$balancer instanceof $class) {
                throw new SystemException('load balancer factory type mismatch: ' . $type);
            }

            $chosenSet = [];
            for ($i = 0; $i < 5; ++$i) {
                $chosen = $balancer->choose();
                if (!$chosen instanceof ServiceInstance) {
                    throw new SystemException('load balancer choose returned null: ' . $type);
                }
                $chosenSet[$chosen->getAddress()] = true;
            }

            $sample = array_key_first($chosenSet);
            echo sprintf("[%s] choose sample: %s (unique=%d)\n", $type, $sample, count($chosenSet));

            if (!isset($chosenSet[$expectedIp . ':' . $expectedPort]) && 1 === count($client->getInstances())) {
                throw new SystemException('load balancer did not return registered instance: ' . $type);
            }
        }

        echo "assert all load balancers ok\n";
    }

    private function assertChooseUri(
        DiscoveryClient $client,
        string $expectedIp,
        int $expectedPort,
    ): void {
        $uri = $client->chooseUri('http');
        $expectedUri = 'http://' . $expectedIp . ':' . $expectedPort;

        if (!\is_string($uri) || '' === $uri) {
            throw new SystemException('chooseUri returned empty');
        }

        echo "chooseUri: {$uri}\n";

        if (1 === count($client->getInstances()) && $uri !== $expectedUri) {
            throw new SystemException(sprintf('chooseUri mismatch, expected=%s, actual=%s', $expectedUri, $uri));
        }

        echo "assert chooseUri ok\n";
    }

    /**
     * @param ServiceInstance[] $instances
     */
    private function containsInstance(array $instances, string $ip, int $port): bool
    {
        foreach ($instances as $instance) {
            if ($this->isSameEndpoint($instance, $ip, $port)) {
                return true;
            }
        }

        return false;
    }

    private function isSameEndpoint(ServiceInstance $instance, string $ip, int $port): bool
    {
        return $instance->ip === $ip && $instance->port === $port;
    }
}
