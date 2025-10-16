<?php
namespace Swoolefy\Cmd;

use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'start',
)]
class StartCmd extends BaseCmd
{
    protected static $defaultName = 'start';

    protected function configure()
    {
        $this->addOption(self::START_MODEL, null,InputOption::VALUE_OPTIONAL, 'start model', '');
        parent::configure();
        $restartPidFile = SystemEnv::getRestartModelPidFile();
        if (file_exists($restartPidFile)) {
            unlink($restartPidFile);
        }
        $this->setDescription('start the application')->setHelp('<info>use php cli.php start XXXXX or php daemon.php start XXXXX</info>');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        global $beforeFunc;
        if (isset($beforeFunc) && is_callable($beforeFunc)) {
            call_user_func($beforeFunc);
        }

        $serverName = $input->getArgument(self::APP_NAME);
        foreach (APP_META_ARR as $appName => $appItem) {
            try {
                $protocol = $appItem['protocol'];
                if ($appName == $serverName) {
                    switch ($protocol) {
                        case 'http':
                            $this->startHttpServer($appName,$protocol);
                            break;
                        case 'websocket':
                            $this->startWebsocket($appName,$protocol);
                            break;
                        case 'rpc':
                            $this->startRpc($appName,$protocol);
                            break;
                        case 'udp':
                            $this->startUdp($appName,$protocol);
                            break;
                        case 'mqtt':
                            $this->startMqtt($appName,$protocol);
                            break;
                        default:
                            fmtPrintError("Protocol is not in 【'http','websocket','rpc','udp','mqtt'】");
                            break;
                    }
                }
            } catch (\Throwable $throwable) {
                fmtPrintError($throwable->getMessage().', trace='.$throwable->getTraceAsString());
                exit(0);
            }
        }
        return 0;
    }

    protected function startHttpServer(string $appName, string $protocol)
    {
        $serverName = $this->protocolMap[$protocol]['server_name'];
        $this->startServer($appName, $serverName);
    }

    protected function startWebsocket(string $appName, string $protocol)
    {
        $serverName = $this->protocolMap[$protocol]['server_name'];
        $this->startServer($appName, $serverName);
    }

    protected function startRpc(string $appName, string $protocol)
    {
        $serverName = $this->protocolMap[$protocol]['server_name'];
        $this->startServer($appName, $serverName);
    }

    protected function startUdp(string $appName, string $protocol)
    {
        $serverName = $this->protocolMap[$protocol]['server_name'];
        $this->startServer($appName, $serverName);
    }

    protected function startMqtt(string $appName, string $protocol)
    {
        $serverName = $this->protocolMap[$protocol]['server_name'];
        $this->startServer($appName, $serverName);
    }

    protected function startServer(string $appName, string $serverName)
    {

        $this->writeLog("启动服务：".WORKER_SERVICE_NAME);
        $config = $this->loadGlobalConf();
        $this->checkRunning($config);
        $class = "{$appName}\\{$serverName}";
        $server = new $class($config);
        $server->start();
    }
}