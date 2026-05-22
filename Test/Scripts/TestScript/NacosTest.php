<?php

declare(strict_types=1);

namespace Test\Scripts\TestScript;

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Exception\SystemException;
use Swoolefy\Script\MainCliScript;
use Swoolefy\Support\Nacos\ConfigFetcher;
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Support\Nacos\ServiceRegistrar;

/**
 * Nacos SDK smoke test.
 *
 * php script.php start Test --c=nacos:test --a=testNacos
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

    public function testNacos(): void
    {
        $nacosConfig = NacosConfig::load(defined('APP_PATH') ? APP_PATH : null);
        $logger = LogManager::getInstance()->getLogger('nacos_log');
        $configFetcher = new ConfigFetcher($nacosConfig, $logger);
        $serviceRegistrar = new ServiceRegistrar($nacosConfig, $logger);

        $dataId = $nacosConfig->dataId;
        $group = $nacosConfig->group;
        $content = 'APP_NAME: Test';

        $configFetcher->set($content, $dataId, $group);
        echo "config set ok: dataId={$dataId}, group={$group}\n";

        usleep(100_000);
        $value = $configFetcher->get($dataId, $group);
        if ($value !== $content) {
            throw new SystemException(sprintf('config get mismatch, expected=%s, actual=%s', $content, $value));
        }
        echo "config get ok: {$value}\n";

        $registerIp = '' !== $nacosConfig->serviceIp ? $nacosConfig->serviceIp : '192.168.1.10';
        $registerPort = $nacosConfig->servicePort > 0 ? $nacosConfig->servicePort : 8080;
        $registerName = '' !== $nacosConfig->serviceName ? $nacosConfig->serviceName : 'my-service';

        $serviceRegistrar->register($registerIp, $registerPort, $registerName);
        echo "instance register ok: {$registerIp}:{$registerPort} -> {$registerName}\n";

        $list = $serviceRegistrar->list($registerName);
        echo 'instance list hosts: ' . count($list->getHosts()) . "\n";

        echo "Nacos test passed\n";
    }
}
