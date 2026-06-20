<?php
namespace Swoolefy\Cmd;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'create',
)]
class CreateCmd extends BaseCmd
{
    protected static $defaultName = 'create';

    protected static $dirPermission = 0755;

    protected function configure()
    {
        parent::configure();
        $this->setDescription('create application init skeleton')->setHelp('<info>use php cli.php create XXXXX</info>info>');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dirs = ['Config', 'Service', 'Protocol', 'Router', 'Storage', 'Middleware', 'Scripts'];
        $appName = APP_NAME;
        $appPathDir = APP_PATH;
        if (is_dir($appPathDir)) {
            fmtPrintError("You had create {$appName} project dir");
            exit(0);
        }

        fmtPrintInfo("开始创建【{$appName}】应用骨架，请稍等......");
        sleep(1);

        $protocol = APP_META_ARR[$appName]['protocol'];
        if (!$protocol) {
            fmtPrintError("The app_name={$appName} is not in APP_NAME array in swoolefy file, please check it");
            exit(0);
        }

        if ($protocol == self::HTTP_PROTOCOL) {
            $dirs = ['Common', 'Config', 'Controller', 'Model', 'Module', 'Router', 'Storage', 'Protocol', 'Middleware','Scripts'];
        }

        $daemonFile = START_DIR_ROOT . '/daemon.php';
        if (!file_exists($daemonFile)) {
            @copy(SRC_DIR_ROOT.'/Stubs/daemon.stub.php', $daemonFile);
        }

        $cronFile = START_DIR_ROOT . '/cron.php';
        if (!file_exists($cronFile)) {
            @copy(SRC_DIR_ROOT.'/Stubs/cron.stub.php', $cronFile);
        }

        $scriptFile = START_DIR_ROOT . '/script.php';
        if (!file_exists($scriptFile)) {
            @copy(SRC_DIR_ROOT.'/Stubs/script.stub.php', $scriptFile);
        }

        $swagFile = START_DIR_ROOT . '/swag.php';
        if (!file_exists($swagFile)) {
            @copy(SRC_DIR_ROOT.'/Stubs/swag.stub.php', $swagFile);
        }

        @mkdir($appPathDir, self::$dirPermission, true);

        $envFile = $appPathDir.'/.env';
        if (!is_file($envFile)) {
            @file_put_contents($envFile, $this->getEnvFileContent());
        }

        $applicationYamlFile = $appPathDir . '/application.yaml';
        if (!is_file($applicationYamlFile)) {
            @file_put_contents($applicationYamlFile, $this->getApplicationYamlContent($appName));
        }

        foreach ($dirs as $dir) {
            @mkdir($appPathDir . '/' . $dir, self::$dirPermission, true);
            switch ($dir) {
                case 'Common':
                {
                    $dirPath = $appPathDir . '/' . $dir;
                    @mkdir($dirPath, self::$dirPermission, true);
                    // 默认创建枚举类和常量类文件夹
                    foreach (['Const','Enum'] as $itemDir) {
                        $itemDirPath = $appPathDir . '/' . $dir . '/' . $itemDir;
                        @mkdir($itemDirPath, self::$dirPermission, true);
                    }
                    break;
                }
                case 'Config':
                {
                    $definesFile = $appPathDir . '/' . $dir . '/constants.php';
                    if (!file_exists($definesFile)) {
                        file_put_contents($definesFile, $this->getDefines());
                    }

                    $componentDir = $appPathDir . '/' . $dir . '/component';
                    if (!is_dir($componentDir)) {
                        @mkdir($componentDir, self::$dirPermission, true);
                        @copy(SRC_DIR_ROOT.'/Stubs/db.stub.php', $componentDir.'/database.php');
                        @copy(SRC_DIR_ROOT.'/Stubs/log.stub.php', $componentDir.'/log.php');
                        @copy(SRC_DIR_ROOT.'/Stubs/cache.stub.php', $componentDir.'/cache.php');
                    }

                    $confFile = $appPathDir . '/' . $dir . '/app.php';
                    if (!file_exists($confFile)) {
                        @copy(SRC_DIR_ROOT.'/Stubs/app.conf.stub.php', $confFile);
                    }

                    $dcFile = $appPathDir . '/' . $dir . '/dc.php';
                    if (!file_exists($dcFile)) {
                        @copy(SRC_DIR_ROOT.'/Stubs/dc.stub.php', $dcFile);
                    }

                    if ($protocol == self::WEBSOCKET_PROTOCOL) {
                        $socketioFile = $appPathDir . '/' . $dir . '/socketio.php';
                        if (!file_exists($socketioFile)) {
                            @copy(SRC_DIR_ROOT . '/Stubs/socketio.conf.stub.php', $socketioFile);
                        }
                        $websocketFile = $appPathDir . '/' . $dir . '/websocket.php';
                        if (!file_exists($websocketFile)) {
                            $websocketContent = (string) file_get_contents(SRC_DIR_ROOT . '/Stubs/websocket.conf.stub.php');
                            $websocketContent = str_replace('__APP_NAMESPACE__', $appName, $websocketContent);
                            @file_put_contents($websocketFile, $websocketContent);
                        }
                    }

                    break;
                }
                case 'Controller':
                {
                    $controllerFile = $appPathDir . '/' . $dir . '/IndexController.php';
                    if (!file_exists($controllerFile)) {
                        file_put_contents($controllerFile, $this->getDefaultController($appName));
                    }
                    break;
                }
                case 'Model':
                {
                    $modelFile = $appPathDir . '/' . $dir . '/DemoModel.php';
                    if (!file_exists($modelFile)) {
                        file_put_contents($modelFile, $this->getDefaultModel($appName));
                    }
                    break;
                }
                case 'Module':
                {
                    $moduleName = 'Demo';
                    foreach ([$moduleName] as $module) {
                        $moduleDir = $appPathDir . '/' . $dir . '/' . $module;
                        if (!is_dir($moduleDir)) {
                            @mkdir($moduleDir, self::$dirPermission, true);
                        }
                    }
                    foreach (['Controller','Dto','Request','Response', 'Exception'] as $itemDir) {
                        $itemDirPath = $appPathDir . '/' . $dir . '/' . $module . '/' . $itemDir;
                        if (!is_dir($itemDirPath)) {
                            @mkdir($itemDirPath, self::$dirPermission, true);
                        }
                    }
                    break;
                }
                case 'Router':
                {
                    switch ($protocol) {
                        case self::HTTP_PROTOCOL:
                            $apiFile = $appPathDir . "/{$dir}/api.php";
                            if (!file_exists($apiFile)) {
                                $apiContent = (string) file_get_contents(SRC_DIR_ROOT . '/Stubs/api.stub.php');
                                $apiContent = str_replace('\\App\\', "\\{$appName}\\", $apiContent);
                                @file_put_contents($apiFile, $apiContent);
                            }
                            break;
                        case self::UDP_PROTOCOL:
                        case self::WEBSOCKET_PROTOCOL:
                            $apiFile = $appPathDir . "/{$dir}/service.php";
                            if (!file_exists($apiFile)) {
                                $apiContent = (string) file_get_contents(SRC_DIR_ROOT.'/Stubs/service.api.stub.php');
                                $apiContent = str_replace('__APP_NAMESPACE__', $appName, $apiContent);
                                @file_put_contents($apiFile, $apiContent);
                            }
                            break;
                        default:
                            break;
                    }
                    break;
                }
                case 'Protocol':
                {
                    $path       = $appPathDir . "/Protocol";
                    $confFile   = $path . "/conf.php";
                    if (!file_exists($confFile)) {
                        switch ($protocol) {
                            case self::HTTP_PROTOCOL:
                                @copy(SRC_DIR_ROOT . '/Http/conf.stub.php', $confFile);
                                break;
                            case self::RPC_PROTOCOL:
                                @copy(SRC_DIR_ROOT . '/Rpc/conf.stub.php', $confFile);
                                break;
                            case self::UDP_PROTOCOL:
                                @copy(SRC_DIR_ROOT . '/Udp/conf.stub.php', $confFile);
                                break;
                            case self::WEBSOCKET_PROTOCOL:
                                @copy(SRC_DIR_ROOT . '/Websocket/conf.stub.php', $confFile);
                                break;
                            case self::MQTT_PROTOCOL:
                                @copy(SRC_DIR_ROOT . '/Mqtt/conf.stub.php', $confFile);
                                break;
                        }
                    }
                    break;
                }
                case 'Middleware':
                {
                    switch ($protocol) {
                        case self::HTTP_PROTOCOL:
                            $groupDir = $appPathDir . '/' . $dir . '/Group';
                            if (!is_dir($groupDir)) {
                                @mkdir($groupDir, self::$dirPermission, true);
                            }

                            $routeDir = $appPathDir . '/' . $dir.'/Route';
                            if (!is_dir($routeDir)) {
                                @mkdir($routeDir, self::$dirPermission, true);
                            }

                            $middlewareFile = $routeDir . '/ValidLoginMiddleware.php';
                            if (!file_exists($middlewareFile)) {
                                $middlewareContent = (string) file_get_contents(SRC_DIR_ROOT . '/Stubs/ValidLoginMiddleware.stub.php');
                                $middlewareContent = str_replace('MY_APP_NAME', $appName, $middlewareContent);
                                @file_put_contents($middlewareFile, $middlewareContent);
                            }
                            break;
                        case self::UDP_PROTOCOL:
                        case self::WEBSOCKET_PROTOCOL:
                            $middlewareDir = $appPathDir . '/' . $dir;
                            if (!is_dir($middlewareDir)) {
                                @mkdir($middlewareDir, self::$dirPermission, true);
                            }
                        default:
                            break;
                    }
                    break;
                }
                case 'Service':
                {
                    switch ($protocol)
                    {
                        case self::UDP_PROTOCOL:
                        case self::WEBSOCKET_PROTOCOL:
                        case self::RPC_PROTOCOL:
                        case self::MQTT_PROTOCOL:
                            $serviceDir = $appPathDir . '/' . $dir;
                            if (!is_dir($serviceDir)) {
                                @mkdir($serviceDir, self::$dirPermission, true);
                            }
                            break;
                        default:
                            break;
                    }

                    // 初始化service模版
                    if ($protocol == self::UDP_PROTOCOL || $protocol == self::WEBSOCKET_PROTOCOL) {
                        $serviceFile = $appPathDir . '/' . $dir . '/DemoService.php';
                        if (!file_exists($serviceFile)) {
                            file_put_contents($serviceFile, $this->getDefaultService($appName, $protocol));
                        }
                        if ($protocol == self::WEBSOCKET_PROTOCOL) {
                            $chatServiceFile = $appPathDir . '/' . $dir . '/ChatService.php';
                            if (!file_exists($chatServiceFile)) {
                                $chatServiceContent = (string) file_get_contents(SRC_DIR_ROOT . '/Stubs/ChatService.stub.php');
                                $chatServiceContent = str_replace('__APP_NAMESPACE__', $appName, $chatServiceContent);
                                file_put_contents($chatServiceFile, $chatServiceContent);
                            }
                            $pushDir = $appPathDir . '/Push';
                            @mkdir($pushDir, self::$dirPermission, true);
                            $enricherFile = $pushDir . '/MessagePushEnricher.php';
                            if (!file_exists($enricherFile)) {
                                $enricherContent = (string) file_get_contents(SRC_DIR_ROOT . '/Stubs/MessagePushEnricher.stub.php');
                                $enricherContent = str_replace('__APP_NAMESPACE__', $appName, $enricherContent);
                                file_put_contents($enricherFile, $enricherContent);
                            }
                            $authDir = $appPathDir . '/Auth';
                            @mkdir($authDir, self::$dirPermission, true);
                            $authCallbackFile = $authDir . '/WebsocketAuthCallback.php';
                            if (!file_exists($authCallbackFile)) {
                                $authContent = (string) file_get_contents(SRC_DIR_ROOT . '/Stubs/WebsocketAuthCallback.stub.php');
                                $authContent = str_replace('__APP_NAMESPACE__', $appName, $authContent);
                                file_put_contents($authCallbackFile, $authContent);
                            }
                            $groupAuthFile = $authDir . '/WebsocketGroupJoinAuthorizer.php';
                            if (!file_exists($groupAuthFile)) {
                                $groupAuthContent = (string) file_get_contents(SRC_DIR_ROOT . '/Stubs/WebsocketGroupJoinAuthorizer.stub.php');
                                $groupAuthContent = str_replace('__APP_NAMESPACE__', $appName, $groupAuthContent);
                                file_put_contents($groupAuthFile, $groupAuthContent);
                            }
                        }
                    }
                    break;
                }
                case 'Scripts':
                {
                    $scriptPath = $appPathDir . '/' . $dir;
                    $kernelFile = SRC_DIR_ROOT.'/Script/Kernel.php';
                    $kernelFileContent = file_get_contents($kernelFile);
                    $kernelFileContent = str_replace('namespace Swoolefy\Script', "namespace {$appName}\\{$dir}", $kernelFileContent);
                    if (!file_exists($scriptPath.'/Kernel.php')) {
                        @file_put_contents($scriptPath.'/Kernel.php', $kernelFileContent);
                    }
                }
                default:
                    break;
            }
        }
        $this->copyServerFile($appName, $protocol);
        if ($protocol == self::WEBSOCKET_PROTOCOL) {
            @mkdir($appPathDir . '/Tests', self::$dirPermission, true);
            @copy(SRC_DIR_ROOT . '/Stubs/socketio.client.stub.html', $appPathDir . '/Storage/socketio-client.html');
            $testHtml = str_replace('__APP_NAMESPACE__', $appName, (string) file_get_contents(SRC_DIR_ROOT . '/Websocket/Tests/socketio-client.html'));
            @file_put_contents($appPathDir . '/Tests/socketio-client.html', $testHtml);
        }
        fmtPrintInfo("应用创建成功啦，应用名称为：【{$appName}】，你现在可以使用命令 php cli.php start {$appName} 来启动应用");
        return 0;
    }

    /**
     * @param $appName
     * @param $protocol
     * @return void
     */
    protected function copyServerFile($appName, $protocol)
    {
        $this->commonHandleFile();
        $protocolInfo = $this->protocolMap[$protocol] ?? [];
        if (empty($protocolInfo)) {
            $namespace = 'protocol\\http';
            $serverName = 'HttpServer';
        } else {
            $namespace = $protocolInfo['namespace'];
            $serverName = $protocolInfo['server_name'];
        }
        $eventServerFile = APP_PATH.'/'.$serverName.'.php';
        if (!file_exists($eventServerFile)) {
            $searchStr = $namespace;
            $replaceStr = "{$appName}";
            $fileContentString = file_get_contents(SRC_DIR_ROOT.'/Stubs/'.$serverName.'.stub.php');
            $count = 1;
            $fileContentString = str_replace($searchStr, $replaceStr, $fileContentString, $count);
            file_put_contents($eventServerFile, $fileContentString);
        }
    }

    protected function getDefaultModel($appName)
    {
        $content =
            <<<EOF
<?php
namespace {$appName}\Model;

use Swoolefy\Library\Db\Model;

class DemoModel extends Model {

}
EOF;
        return $content;
    }

    protected function getDefines()
    {
        $content =

            <<<EOF
<?php
defined('LOG_PATH') or define('LOG_PATH', APP_PATH.'/Storage/Logs');
defined('CONFIG_PATH') or define('CONFIG_PATH', APP_PATH.'/Config');
defined('CONFIG_COMPONENT_PATH') or define('CONFIG_COMPONENT_PATH', CONFIG_PATH.'/component');

EOF;
        return $content;
    }

    protected function getDefaultController($appName)
    {
        $content =
            <<<EOF
<?php
namespace {$appName}\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class IndexController extends BController {
    public function index() {
        Application::getApp()->swooleResponse->write('<h1>Hello, Welcome to Swoolefy Framework! <h1>');
    }
}
EOF;
        return $content;
    }

    protected function getDefaultService($appName, string $protocol = self::UDP_PROTOCOL)
    {
        if ($protocol == self::WEBSOCKET_PROTOCOL) {
            $content =
                <<<EOF
<?php
namespace {$appName}\Service;

use Swoolefy\Websocket\WebsocketService;
use Swoolefy\Websocket\WebsocketResponse;

class DemoService extends WebsocketService
{
    public function reportMsg(array \$params)
    {
        \$packet = \$this->getWebsocketMsg();
        \$this->pushRaw(\$packet->getFd(), WebsocketResponse::success(\$packet->getRequestId(), [
            'echo' => \$params['msg'] ?? '',
        ], \$packet->getEndpoint()));
    }

    public function ping(array \$params)
    {
        \$packet = \$this->getWebsocketMsg();
        \$this->pushRaw(\$packet->getFd(), WebsocketResponse::pong(\$packet->getRequestId()));
    }
}
EOF;
            return $content;
        }

        $content =
            <<<EOF
<?php
namespace {$appName}\Service;

use Swoolefy\Core\ResponseFormatter;

class DemoService extends \Swoolefy\Core\BService
{
    public function reportMsg(\$params)
    {
        \$packet = \$this->getUdpData();
        \$msg = \$params['msg'] ?? '';
        \$response = ResponseFormatter::formatDataDto(0, 'ok', [
            'echo' => \$msg,
            'from' => \$packet->getAddress() . ':' . \$packet->getPort(),
        ]);
        \$this->sendTo(\$response);
    }

    public function ping(\$params)
    {
        \$this->sendTo(ResponseFormatter::formatDataDto(0, 'pong'));
    }
}
EOF;
        return $content;
    }


    protected function getApplicationYamlContent(string $appName): string
    {
        $workerPort = APP_META_ARR[$appName]['worker_port'] ?? 9501;
        $stubFile = SRC_DIR_ROOT.'/Stubs/application.stub.yaml';
        $content = is_file($stubFile) ? (string) file_get_contents($stubFile) : '';

        return str_replace('{WORKER_PORT}', (string) $workerPort, $content);
    }

    protected function getEnvFileContent()
    {
        $content =
<<<EOF
#cron service debug配置,默认开启
CRON_DEBUG=true 

# 内部跨环境可访问的服务URL。服务注册时会写入 Nacos metadata 的 `inner_external_base_uri`
INNER_EXTERNAL_BASE_URI='http://192.168.1.102:9501'

#mysqL配置
DB_HOST_NAME=192.168.1.101
DB_HOST_DATABASE=bingcool
DB_USER_NAME=root
DB_PASSWORD=123456
DB_HOST_PORT=3306

#redis配置
REDIS_HOST=192.168.1.101
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=1


# OpenTelemetry
OTEL_PHP_AUTOLOAD_ENABLED=false
OTEL_RESOURCE_SERVICE_NAME="swoolefy-service"
OTEL_TRACING_NAME="swoolefy-http-request"
# 阿里云腾讯云的Authentication token
OTEL_EXPORTER_OTLP_AUTHENTICATION_TOKEN=""
OTEL_EXPORTER_OTLP_ENDPOINT="http://192.168.25.13:4318"
OTEL_EXPORT_TIMEOUT=30
OTEL_MAX_QUEUE_SIZE=512
OTEL_SAMPLER_TYPE=always_on
# 启用批量提交span
OTEL_SAMPLER_BATCH_SPAN_ENABLED=true
OTEL_SAMPLER_TRACE_ID_RATIO=0.2
# 启用GUZZLE_HTTP自动跟踪
OTEL_INSTRUMENTATION_GUZZLE_ENABLED=false
# 同一个协程中最多允许记录curl请求数，防止业务上的循环请求的记录，默认100
OTEL_INSTRUMENTATION_GUZZLE_MAX_TRACE_SPANS_NUM=50

EOF;

        return $content;
    }

}