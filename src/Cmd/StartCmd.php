<?php
namespace Swoolefy\Cmd;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StartCmd extends BaseCmd
{
    protected static $defaultName = 'start';

    protected function configure()
    {
        parent::configure();
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

        $serverName = $input->getArgument('app_name');
        foreach (APP_META_ARR as $appName => $appItem) {
            try {
                $protocol = $appItem['protocol'];
                if ($appName == $serverName) {
                    switch ($protocol) {
                        case 'http':
                            $this->startHttpService($appName);
                            break;
                        case 'websocket':
                            $this->startWebsocket($appName);
                            break;
                        case 'rpc':
                            $this->startRpc($appName);
                            break;
                        case 'udp':
                            $this->startUdp($appName);
                            break;
                        case 'mqtt':
                            $this->startMqtt($appName);
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


    protected function startHttpService(string $appName)
    {
        $serverName = 'HttpServer';
        $config = $this->loadGlobalConf();
        $this->checkRunning($config);
        $eventServerFile = APP_PATH.'/'.$serverName.'.php';
        if (!file_exists($eventServerFile)) {
            $searchStr = "protocol\\http";
            $replaceStr = "{$appName}";
            $fileContentString = file_get_contents(ROOT_PATH . '/src/Stubs/'.$serverName.'.stub.php');
            $count = 1;
            $fileContentString = str_replace($searchStr, $replaceStr, $fileContentString, $count);
            file_put_contents($eventServerFile, $fileContentString);
        }

        $routeDir = APP_PATH.'/Router';
        if (!is_dir($routeDir)) {
            mkdir($routeDir, 0777, true);
        }
        $this->commonHandle($config);
        $class = "{$appName}\\{$serverName}";
        $http = new $class($config);
        $http->start();
    }

    protected function startWebsocket($appName)
    {
        $serverName = 'WebsocketEventServer';
        $config = $this->loadGlobalConf();
        $this->checkRunning($config);
        $eventServerFile = APP_PATH.'/'.$serverName.'.php';
        if (!file_exists($eventServerFile)) {
            $searchStr = "protocol\\websocket";
            $replaceStr = "{$appName}";
            $fileContentString = file_get_contents(ROOT_PATH . '/src/Stubs/'.$serverName.'.stub.php');
            $count = 1;
            $fileContentString = str_replace($searchStr, $replaceStr, $fileContentString, $count);
            file_put_contents($eventServerFile, $fileContentString);
        }
        $routerDir = APP_PATH . "/Router";
        if (!is_dir($routerDir)) {
            mkdir($routerDir, 0777, true);
        }
        $this->commonHandle($config);
        $class = "{$appName}\\{$serverName}";
        $websocket = new $class($config);
        $websocket->start();
    }

    function startRpc($appName)
    {
        $serverName = 'RpcServer';
        $config = $this->loadGlobalConf();
        $this->checkRunning($config);
        $eventServerFile = APP_PATH.'/'.$serverName.'.php';
        if (!file_exists($eventServerFile)) {
            $searchStr = "protocol\\rpc";
            $replaceStr = "{$appName}";
            $fileContentString = file_get_contents(ROOT_PATH . '/src/Stubs/'.$serverName.'.stub.php');
            $count = 1;
            $fileContentString = str_replace($searchStr, $replaceStr, $fileContentString, $count);
            file_put_contents($eventServerFile, $fileContentString);
        }

        $this->commonHandle($config);
        $class = "{$appName}\\{$serverName}";
        $rpc = new $class($config);
        $rpc->start();
    }

    function startUdp($appName)
    {
        $serverName = 'UdpEventServer';
        $config = $this->loadGlobalConf();
        $this->checkRunning($config);
        $eventServerFile = APP_PATH.'/'.$serverName.'.php';
        if (!file_exists($eventServerFile)) {
            $searchStr = "protocol\\udp";
            $replaceStr = "{$appName}";
            $fileContentString = file_get_contents(ROOT_PATH . '/src/Stubs/'.$serverName.'.stub.php');
            $count = 1;
            $fileContentString = str_replace($searchStr, $replaceStr, $fileContentString, $count);
            file_put_contents($eventServerFile, $fileContentString);
        }

        $routerDir = APP_PATH . "/Router";
        if (!is_dir($routerDir)) {
            mkdir($routerDir, 0777, true);
        }

        $this->commonHandle($config);
        $class = "{$appName}\\{$serverName}";
        $udp = new $class($config);
        $udp->start();
    }

    protected function startMqtt($appName)
    {
        $serverName = 'MqttServer';
        $config = $this->loadGlobalConf();
        $this->checkRunning($config);
        $eventServerFile = APP_PATH.'/'.$serverName.'.php';
        if (!file_exists($eventServerFile)) {
            $searchStr = "protocol\\mqtt";
            $replaceStr = "{$appName}";
            $fileContentString = file_get_contents(ROOT_PATH . '/src/Stubs/'.$serverName.'.stub.php');
            $count = 1;
            $fileContentString = str_replace($searchStr, $replaceStr, $fileContentString, $count);
            file_put_contents($eventServerFile, $fileContentString);
        }
        $this->commonHandle($config);
        $class = "{$appName}\\{$serverName}";
        $mqtt = new $class($config);
        $mqtt->start();
    }
}