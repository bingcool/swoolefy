<?php

declare(strict_types=1);

namespace Test\Scripts\TestScript;

use Common\Library\Nacos\Client;
use Common\Library\Nacos\ClientConfig;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Exception\SystemException;
use Swoolefy\Script\MainCliScript;

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
        $client = new Client(new ClientConfig([
            'host' => getenv('NACOS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('NACOS_PORT') ?: 8848),
            'username' => getenv('NACOS_USERNAME') ?: '',
            'password' => getenv('NACOS_PASSWORD') ?: '',
            'authorizationBearer' => '' !== (getenv('NACOS_USERNAME') ?: '') && '' !== (getenv('NACOS_PASSWORD') ?: ''),
            'useCoroutinePool' => \extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0,
        ]), LogManager::getInstance()->getLogger('nacos_log'));

        $dataId = 'app.yaml';
        $group = 'DEFAULT_GROUP';
        $content = 'APP_NAME: Test';

        $setOk = $client->config->set($dataId, $group, $content);
        if (!$setOk) {
            throw new \RuntimeException('Nacos config set failed');
        }
        echo "config set ok: dataId={$dataId}, group={$group}\n";

        usleep(100_000);
        $value = $client->config->get($dataId, $group);
        if ($value !== $content) {
            throw new \RuntimeException(sprintf('config get mismatch, expected=%s, actual=%s', $content, $value));
        }
        echo "config get ok: {$value}\n";

        $registerOk = $client->instance->register('192.168.1.10', 8080, 'my-service');
        if (!$registerOk) {
            throw new \RuntimeException('Nacos instance register failed');
        }
        echo "instance register ok: 192.168.1.10:8080 -> my-service\n";

        $list = $client->instance->list('my-service');
        echo 'instance list hosts: ' . count($list->getHosts()) . "\n";

        echo "Nacos test passed\n";
    }
}
