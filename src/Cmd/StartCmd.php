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
        $this->setDescription('start the application')->setHelp('use php cli.php start XXXXX or php daemon.php start XXXXX');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        global $beforeFunc;
        if (isset($beforeFunc) && is_callable($beforeFunc)) {
            call_user_func($beforeFunc);
        }

        $serverName = $input->getArgument('app_name');
        foreach (APP_NAMES as $appName => $protocol) {
            try {
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
                fmtPrintError($throwable->getMessage());
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
            $search_str = "protocol\\http";
            $replace_str = "{$appName}";
            $file_content_string = file_get_contents(ROOT_PATH . '/src/Stubs/'.$serverName.'.php');
            $count = 1;
            $file_content_string = str_replace($search_str, $replace_str, $file_content_string, $count);
            file_put_contents($eventServerFile, $file_content_string);
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
            $search_str = "protocol\\websocket";
            $replace_str = "{$appName}";
            $file_content_string = file_get_contents(ROOT_PATH . '/src/Stubs/'.$serverName.'.php');
            $count = 1;
            $file_content_string = str_replace($search_str, $replace_str, $file_content_string, $count);
            file_put_contents($eventServerFile, $file_content_string);
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
            $search_str = "protocol\\rpc";
            $replace_str = "{$appName}";
            $file_content_string = file_get_contents(ROOT_PATH . '/src/Stubs/'.$serverName.'.php');
            $count = 1;
            $file_content_string = str_replace($search_str, $replace_str, $file_content_string, $count);
            file_put_contents($eventServerFile, $file_content_string);
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
            $search_str = "protocol\\udp";
            $replace_str = "{$appName}";
            $file_content_string = file_get_contents(ROOT_PATH . '/src/Stubs/'.$serverName.'.php');
            $count = 1;
            $file_content_string = str_replace($search_str, $replace_str, $file_content_string, $count);
            file_put_contents($eventServerFile, $file_content_string);
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
            $search_str = "protocol\\mqtt";
            $replace_str = "{$appName}";
            $file_content_string = file_get_contents(ROOT_PATH . '/src/Stubs/'.$serverName.'.php');
            $count = 1;
            $file_content_string = str_replace($search_str, $replace_str, $file_content_string, $count);
            file_put_contents($eventServerFile, $file_content_string);
        }
        $this->commonHandle($config);
        $class = "{$appName}\\{$serverName}";
        $mqtt = new $class($config);
        $mqtt->start();
    }
}