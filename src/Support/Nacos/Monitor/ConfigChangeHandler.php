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

namespace Swoolefy\Support\Nacos\Monitor;

use Swoolefy\Library\Nacos\Client;
use Swoolefy\Support\Nacos\ConfigFetcher;
use Swoolefy\Support\Nacos\ConfigFileWriter;
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Support\Nacos\NacosFactory;
use Swoolefy\Support\Nacos\NacosLogger;
use Swoolefy\Util\Log;

/**
 * 配置变更：拉取 Nacos → 写 .env → restart 整个服务。
 */
final class ConfigChangeHandler
{
    private readonly ConfigFileWriter $configFileWriter;
    private readonly ServiceRestarter $restarter;
    private readonly ConfigFetcher $configFetcher;

    private readonly Log $logger;

    public function __construct(
        private readonly MonitorConfig $config,
        ?ConfigFileWriter $configFileWriter = null,
        ?ServiceRestarter $restarter = null,
        ?ConfigFetcher $configFetcher = null,
    ) {
        $this->logger = NacosLogger::get();
        $this->configFileWriter = $configFileWriter ?? new ConfigFileWriter();
        $this->restarter = $restarter ?? new ServiceRestarter($this->logger);
        $this->configFetcher = $configFetcher ?? new ConfigFetcher($config->nacosConfig, $config->serviceConfig);
    }

    public function handle(): void
    {
        $lockFp = fopen($this->config->lockFile, 'c');
        if (false === $lockFp) {
            $this->logger->error('cannot open lock file: ' . $this->config->lockFile);
            return;
        }

        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            // 并发变更：跳过即可，无需刷 info
            fclose($lockFp);
            return;
        }

        try {
            $content = $this->configFetcher->get();

            // 与 NacosFactory::fetchConfigToEnv 对齐：非法 .env 拒绝写入，避免 restart 后启动失败
            NacosFactory::assertValidEnvContent($content);

            $this->configFileWriter->write($this->config->envFile, $content);
            $delaySeconds = new \Random\Randomizer()->getInt(1, 5);
            sleep($delaySeconds);

            $this->restarter->restart();

            $this->logger->info(sprintf(
                'nacos config applied: dataId=%s group=%s, restart triggered',
                $this->config->serviceConfig->dataId,
                $this->config->serviceConfig->group,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('handle config change failed: ' . $e->getMessage());
            throw $e;
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    public static function createClient(NacosConfig $nacosConfig): Client
    {
        return $nacosConfig->createClient();
    }
}
