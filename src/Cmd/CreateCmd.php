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
        $this->setDescription('create application init skeleton')->setHelp('use php cli.php create XXXXX');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dirs = ['Config', 'Service', 'Protocol', 'Router', 'Storage'];
        $appName = $input->getArgument('app_name');
        $appPathDir = APP_PATH;
        if (is_dir($appPathDir)) {
            $this->error("You had create {$appName} project dir");
            exit(0);
        }

        $protocol = APP_NAMES[$appName];
        if (!$protocol) {
            $this->error("The app_name={$appName} is not in APP_NAME array in swoolefy file, please check it");
            exit(0);
        }

        if ($protocol == 'http') {
            $dirs = [
                'Config', 'Controller', 'Model', 'Module', 'Router', 'Validation', 'Storage', 'Protocol'
            ];
        }

        $daemonFile = START_DIR_ROOT . '/daemon.php';
        if (!file_exists($daemonFile)) {
            @copy(START_DIR_ROOT . '/src/Stubs/DaemonStub.php', $daemonFile);
        }

        $cronFile = START_DIR_ROOT . '/cron.php';
        if (!file_exists($cronFile)) {
            @copy(START_DIR_ROOT . '/src/Stubs/CronStub.php', $cronFile);
        }

        $scriptFile = START_DIR_ROOT . '/script.php';
        if (!file_exists($scriptFile)) {
            @copy(START_DIR_ROOT . '/src/Stubs/ScriptStub.php', $scriptFile);
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
                        @copy(ROOT_PATH . '/src/Stubs/DbComStubs.php', $componentDir.'/database.php');
                        @copy(ROOT_PATH . '/src/Stubs/LogComStubs.php', $componentDir.'/log.php');
                        @copy(ROOT_PATH . '/src/Stubs/CacheComStubs.php', $componentDir.'/cache.php');
                    }

                    $configFile = $appPathDir . '/' . $dir . '/config.php';
                    if (!file_exists($configFile)) {
                        @copy(ROOT_PATH . '/src/Stubs/Config.php', $configFile);
                    }

                    $dcFile = $appPathDir . '/' . $dir . '/dc.php';
                    if (!file_exists($dcFile)) {
                        @copy(ROOT_PATH . '/src/Stubs/Dc.php', $dcFile);
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
                    @mkdir($appPathDir . '/' . $dir.'/Demo/Controller', 0777, true);
                    @mkdir($appPathDir . '/' . $dir.'/Demo/Validation', 0777, true);
                    @mkdir($appPathDir . '/' . $dir.'/Demo/Exception', 0777, true);
                    break;
                case 'Router':
                {
                    switch ($protocol) {
                        case 'http':
                            $apiFile = $appPathDir . "/{$dir}/Api.php";
                            @copy(ROOT_PATH . '/src/Stubs/Api.php', $apiFile);
                            break;
                        case 'udp':
                        case 'websocket':
                            $apiFile = $appPathDir . "/{$dir}/ServiceApi.php";
                            @copy(ROOT_PATH . '/src/Stubs/ServiceApi.php', $apiFile);
                            break;
                        default:
                            break;
                    }
                }
                case 'Protocol':
                {
                    $path = $appPathDir . "/Protocol";
                    $configFile = $path . "/conf.php";
                    if (!file_exists($configFile)) {
                        switch ($protocol) {
                            case 'http':
                                @copy(ROOT_PATH . '/src/Http/config.php', $configFile);
                                break;
                            case 'rpc':
                                @copy(ROOT_PATH . '/src/Rpc/config.php', $configFile);
                                break;
                            case 'udp':
                                @copy(ROOT_PATH . '/src/Udp/config.php', $configFile);
                                break;
                            case 'websocket':
                                @copy(ROOT_PATH . '/src/Websocket/config.php', $configFile);
                                break;
                            case 'mqtt':
                                @copy(ROOT_PATH . '/src/Mqtt/config.php', $configFile);
                                break;
                        }
                    }
                }
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
        Application::getApp()->response->write('<h1>Hello, Welcome to Swoolefy Framework! <h1>');
    }
}
EOF;
        return $content;
    }


    protected function getEnvFileContent()
    {
        $content =
            <<<EOF
#mysqL配置
DB_HOST_NAME=192.168.1.101
DB_HOST_DATABASE=bingcool
DB_USER_NAME=root
DB_PASSWORD=123456
DB_HOST_PORT=3306


#redis配置
REDIS_HOST=192.168.1.101
REDIS_PORT=6379
REDIS_DB=1
EOF;

        return $content;
    }

}