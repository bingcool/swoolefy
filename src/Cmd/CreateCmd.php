<?php
namespace Swoolefy\Cmd;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateCmd extends BaseCmd
{
    protected static $defaultName = 'create';

    protected function configure()
    {
        parent::configure();
        $this->setDescription('create application init skeleton')->setHelp('<info>use php cli.php create XXXXX</info>info>');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dirs = ['Config', 'Service', 'Protocol', 'Router', 'Storage', 'Middleware', 'Scripts'];
        $appName = APP_NAME;
        $appPathDir = APP_PATH;
        if (is_dir($appPathDir)) {
            fmtPrintError("You had create {$appName} project dir");
            exit(0);
        }

        $protocol = APP_META_ARR[$appName]['protocol'];
        if (!$protocol) {
            fmtPrintError("The app_name={$appName} is not in APP_NAME array in swoolefy file, please check it");
            exit(0);
        }

        if ($protocol == 'http') {
            $dirs = ['Config', 'Controller', 'Model', 'Module', 'Router', 'Validation', 'Storage', 'Protocol', 'Middleware','Scripts'];
        }

        $daemonFile = START_DIR_ROOT . '/daemon.php';
        if (!file_exists($daemonFile)) {
            @copy(START_DIR_ROOT . '/src/Stubs/daemon.stub.php', $daemonFile);
        }

        $cronFile = START_DIR_ROOT . '/cron.php';
        if (!file_exists($cronFile)) {
            @copy(START_DIR_ROOT . '/src/Stubs/cron.stub.php', $cronFile);
        }

        $scriptFile = START_DIR_ROOT . '/script.php';
        if (!file_exists($scriptFile)) {
            @copy(START_DIR_ROOT . '/src/Stubs/script.stub.php', $scriptFile);
        }

        $swagFile = START_DIR_ROOT . '/swag.php';
        if (!file_exists($swagFile)) {
            @copy(START_DIR_ROOT . '/src/Stubs/swag.stub.php', $swagFile);
        }

        @mkdir($appPathDir, 0777, true);

        $envFile = $appPathDir.'/.env';
        if (!is_file($envFile)) {
            @file_put_contents($envFile, $this->getEnvFileContent());
        }

        foreach ($dirs as $dir) {
            @mkdir($appPathDir . '/' . $dir, 0777, true);
            switch ($dir) {
                case 'Config':
                {
                    $definesFile = $appPathDir . '/' . $dir . '/constants.php';
                    if (!file_exists($definesFile)) {
                        file_put_contents($definesFile, $this->getDefines());
                    }

                    $componentDir = $appPathDir . '/' . $dir . '/component';
                    if (!is_dir($componentDir)) {
                        @mkdir($componentDir, 0777, true);
                        @copy(ROOT_PATH . '/src/Stubs/db.stub.php', $componentDir.'/database.php');
                        @copy(ROOT_PATH . '/src/Stubs/log.stub.php', $componentDir.'/log.php');
                        @copy(ROOT_PATH . '/src/Stubs/cache.stub.php', $componentDir.'/cache.php');
                    }

                    $confFile = $appPathDir . '/' . $dir . '/app.php';
                    if (!file_exists($confFile)) {
                        @copy(ROOT_PATH . '/src/Stubs/app.conf.stub.php', $confFile);
                    }

                    $dcFile = $appPathDir . '/' . $dir . '/dc.php';
                    if (!file_exists($dcFile)) {
                        @copy(ROOT_PATH . '/src/Stubs/dc.stub.php', $dcFile);
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
                    $controllerDir = $appPathDir . '/' . $dir . '/Demo/Controller';
                    if (!is_dir($controllerDir)) {
                        @mkdir($controllerDir, 0777, true);
                    }

                    $validationDir = $appPathDir . '/' . $dir . '/Demo/Validation';
                    if (!is_dir($validationDir)) {
                        @mkdir($validationDir, 0777, true);
                    }

                    $exceptionDir = $appPathDir . '/' . $dir . '/Demo/Exception';
                    if (!is_dir($exceptionDir)) {
                        @mkdir($exceptionDir, 0777, true);
                    }
                    break;
                }
                case 'Router':
                {
                    switch ($protocol) {
                        case 'http':
                            $apiFile = $appPathDir . "/{$dir}/api.php";
                            if (!file_exists($apiFile)) {
                                @copy(ROOT_PATH . '/src/Stubs/api.stub.php', $apiFile);
                            }
                            break;
                        case 'udp':
                        case 'websocket':
                            $apiFile = $appPathDir . "/{$dir}/service.php";
                            if (!file_exists($apiFile)) {
                                @copy(ROOT_PATH . '/src/Stubs/service.api.stub.php', $apiFile);
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
                            case 'http':
                                @copy(ROOT_PATH . '/src/Http/conf.stub.php', $confFile);
                                break;
                            case 'rpc':
                                @copy(ROOT_PATH . '/src/Rpc/conf.stub.php', $confFile);
                                break;
                            case 'udp':
                                @copy(ROOT_PATH . '/src/Udp/conf.stub.php', $confFile);
                                break;
                            case 'websocket':
                                @copy(ROOT_PATH . '/src/Websocket/conf.stub.php', $confFile);
                                break;
                            case 'mqtt':
                                @copy(ROOT_PATH . '/src/Mqtt/conf.stub.php', $confFile);
                                break;
                        }
                    }
                    break;
                }
                case 'Middleware':
                {
                    switch ($protocol) {
                        case "http":
                            $groupDir = $appPathDir . '/' . $dir . '/Group';
                            if (!is_dir($groupDir)) {
                                @mkdir($groupDir, 0777, true);
                            }

                            $routeDir = $appPathDir . '/' . $dir.'/Route';
                            if (!is_dir($routeDir)) {
                                @mkdir($routeDir, 0777, true);
                            }
                            break;
                        case "udp":
                        case "websocket":
                            $middlewareDir = $appPathDir . '/' . $dir;
                            if (!is_dir($middlewareDir)) {
                                @mkdir($middlewareDir, 0777, true);
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
                        case 'udp':
                        case 'websocket':
                        case 'rpc':
                        case 'mqtt':
                            $serviceDir = $appPathDir . '/' . $dir;
                            if (!is_dir($serviceDir)) {
                                @mkdir($serviceDir, 0777, true);
                            }
                            break;
                        default:
                            break;
                    }
                }
                case 'Scripts':
                {
                    $scriptPath = $appPathDir . '/' . $dir;
                    $kernelFile = ROOT_PATH.'/src/Script/Kernel.php';
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
        return 0;
    }

    protected function getDefaultModel($appName)
    {
        $content =
            <<<EOF
<?php
namespace {$appName}\Model;

use Common\Library\Db\Model;

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


    protected function getEnvFileContent()
    {
        $content =
<<<EOF
#cron service debug配置,默认开启
CRON_DEBUG=true

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

EOF;

        return $content;
    }

}