<?php
namespace Swoolefy\Cmd;

use Swoolefy\Exception\IOException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'create',
)]
class CreateCmd extends BaseCmd
{
    protected static $dirPermission = 0755;

    /**
     * 创建前等待秒数；测试可置 0 跳过 sleep。
     */
    protected int $createDelaySeconds = 1;

    protected function configure()
    {
        parent::configure();
        $this->setDescription('create application init skeleton')->setHelp('<info>use php cli.php create XXXXX</info>info>');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasInitError()) {
            return Command::FAILURE;
        }

        $appName = $this->resolveAppName();
        $appPathDir = $this->resolveAppPath();

        // 目标已存在：失败返回码，绝不 exit()
        if (is_dir($appPathDir)) {
            fmtPrintError("You had create {$appName} project dir");
            return Command::FAILURE;
        }

        $protocol = $this->resolveProtocol($appName);
        if ($protocol === null) {
            fmtPrintError("The app_name={$appName} is not in APP_NAME array in swoolefy file, please check it");
            return Command::FAILURE;
        }

        if (!is_string($protocol) || $protocol === '') {
            fmtPrintError("The app_name={$appName} protocol is invalid");
            return Command::INVALID;
        }

        fmtPrintInfo("开始创建【{$appName}】应用骨架，请稍等......");
        if ($this->createDelaySeconds > 0) {
            sleep($this->createDelaySeconds);
        }

        // 同目录 staging，保证 rename 原子落盘；失败只清临时目录，不碰用户原目标
        $stagingDir = dirname($appPathDir) . '/.swoolefy_create_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $stagingAlive = true;

        try {
            $this->ensureDirectory($stagingDir);
            $this->ensureRootServiceEntryFiles();
            $this->buildApplicationSkeleton($stagingDir, $appName, $protocol);

            if (is_dir($appPathDir) || file_exists($appPathDir)) {
                throw new IOException(
                    'Target application directory already exists',
                    0,
                    null,
                    $stagingDir,
                    $appPathDir
                );
            }

            if (!rename($stagingDir, $appPathDir)) {
                throw new IOException(
                    'Failed to publish application skeleton',
                    0,
                    null,
                    $stagingDir,
                    $appPathDir
                );
            }
            $stagingAlive = false;
        } catch (IOException $e) {
            fmtPrintError($e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            fmtPrintError($e->getMessage());
            return Command::FAILURE;
        } finally {
            if ($stagingAlive && is_dir($stagingDir)) {
                $this->removeDirectoryTree($stagingDir);
            }
        }

        fmtPrintInfo("应用创建成功啦，应用名称为：【{$appName}】，你现在可以使用命令 php cli.php start {$appName} 来启动应用");
        return Command::SUCCESS;
    }

    protected function resolveAppName(): string
    {
        return APP_NAME;
    }

    protected function resolveAppPath(): string
    {
        return APP_PATH;
    }

    /**
     * @return string|null null 表示应用未配置
     */
    protected function resolveProtocol(string $appName): ?string
    {
        if (!isset(APP_META_ARR[$appName])) {
            return null;
        }
        $protocol = APP_META_ARR[$appName]['protocol'] ?? null;
        return $protocol === null ? null : (string) $protocol;
    }

    /**
     * 在临时目录生成完整应用骨架（不直接写最终 APP_PATH）。
     *
     * @throws IOException
     */
    protected function buildApplicationSkeleton(string $appPathDir, string $appName, string $protocol): void
    {
        $dirs = ['Config', 'Service', 'Protocol', 'Router', 'Storage', 'Middleware', 'Scripts'];
        if ($protocol == self::HTTP_PROTOCOL) {
            $dirs = ['Common', 'Config', 'Controller', 'Model', 'Module', 'Router', 'Storage', 'Protocol', 'Middleware', 'Scripts'];
        }

        $this->writeFile($appPathDir . '/.env', $this->getEnvFileContent());
        $this->writeFile($appPathDir . '/application.yaml', $this->getApplicationYamlContent($appName));

        foreach ($dirs as $dir) {
            $this->ensureDirectory($appPathDir . '/' . $dir);
            switch ($dir) {
                case 'Common':
                {
                    foreach (['Const', 'Enum'] as $itemDir) {
                        $this->ensureDirectory($appPathDir . '/' . $dir . '/' . $itemDir);
                    }
                    break;
                }
                case 'Config':
                {
                    $this->writeFile($appPathDir . '/' . $dir . '/constants.php', $this->getDefines());

                    $componentDir = $appPathDir . '/' . $dir . '/component';
                    $this->ensureDirectory($componentDir);
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/db.stub.php', $componentDir . '/database.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/log.stub.php', $componentDir . '/log.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/cache.stub.php', $componentDir . '/cache.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/document_parser.component.stub.php', $componentDir . '/document_parser.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/file_storage.component.stub.php', $componentDir . '/file_storage.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/oauth.component.stub.php', $componentDir . '/oauth.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/auth.component.stub.php', $componentDir . '/auth.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/translator.component.stub.php', $componentDir . '/translator.php');

                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/app.conf.stub.php', $appPathDir . '/' . $dir . '/app.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/dc.stub.php', $appPathDir . '/' . $dir . '/dc.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/workflow.conf.stub.php', $appPathDir . '/' . $dir . '/workflow.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/neuron_ai.conf.stub.php', $appPathDir . '/' . $dir . '/neuron_ai.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/document_ocr.conf.stub.php', $appPathDir . '/' . $dir . '/document_ocr.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/job.conf.stub.php', $appPathDir . '/' . $dir . '/job.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/rate_limit.conf.stub.php', $appPathDir . '/' . $dir . '/rate_limit.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/health.conf.stub.php', $appPathDir . '/' . $dir . '/health.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/otel.conf.stub.php', $appPathDir . '/' . $dir . '/otel.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/apidoc.conf.stub.php', $appPathDir . '/' . $dir . '/apidoc.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/file_storage_system.conf.stub.php', $appPathDir . '/' . $dir . '/file_storage_system.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/oauth.conf.stub.php', $appPathDir . '/' . $dir . '/oauth.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/auth.conf.stub.php', $appPathDir . '/' . $dir . '/auth.php');
                    $this->copyFile(SRC_DIR_ROOT . '/Stubs/i18n.conf.stub.php', $appPathDir . '/' . $dir . '/i18n.php');

                    if ($protocol == self::WEBSOCKET_PROTOCOL) {
                        $this->copyFile(SRC_DIR_ROOT . '/Stubs/socketio.conf.stub.php', $appPathDir . '/' . $dir . '/socketio.php');
                        $websocketContent = $this->readFile(SRC_DIR_ROOT . '/Stubs/websocket.conf.stub.php');
                        $websocketContent = str_replace('__APP_NAMESPACE__', $appName, $websocketContent);
                        $this->writeFile($appPathDir . '/' . $dir . '/websocket.php', $websocketContent);
                    }
                    break;
                }
                case 'Controller':
                {
                    $this->writeFile(
                        $appPathDir . '/' . $dir . '/IndexController.php',
                        $this->getDefaultController($appName)
                    );
                    break;
                }
                case 'Model':
                {
                    $this->writeFile(
                        $appPathDir . '/' . $dir . '/DemoModel.php',
                        $this->getDefaultModel($appName)
                    );
                    break;
                }
                case 'Module':
                {
                    $moduleName = 'Demo';
                    $this->ensureDirectory($appPathDir . '/' . $dir . '/' . $moduleName);
                    foreach (['Controller', 'Dto', 'Request', 'Response', 'Exception'] as $itemDir) {
                        $this->ensureDirectory($appPathDir . '/' . $dir . '/' . $moduleName . '/' . $itemDir);
                    }
                    break;
                }
                case 'Router':
                {
                    switch ($protocol) {
                        case self::HTTP_PROTOCOL:
                            $apiContent = $this->readFile(SRC_DIR_ROOT . '/Stubs/api.stub.php');
                            $apiContent = str_replace('\\App\\', "\\{$appName}\\", $apiContent);
                            $this->writeFile($appPathDir . "/{$dir}/api.php", $apiContent);
                            $healthRouterDir = $appPathDir . "/{$dir}/Common";
                            $this->ensureDirectory($healthRouterDir);
                            $this->copyFile(SRC_DIR_ROOT . '/Stubs/health.router.stub.php', $healthRouterDir . '/Health.php');
                            break;
                        case self::UDP_PROTOCOL:
                        case self::WEBSOCKET_PROTOCOL:
                            $apiContent = $this->readFile(SRC_DIR_ROOT . '/Stubs/service.api.stub.php');
                            $apiContent = str_replace('__APP_NAMESPACE__', $appName, $apiContent);
                            $this->writeFile($appPathDir . "/{$dir}/service.php", $apiContent);
                            break;
                        default:
                            break;
                    }
                    break;
                }
                case 'Protocol':
                {
                    $confFile = $appPathDir . '/Protocol/conf.php';
                    switch ($protocol) {
                        case self::HTTP_PROTOCOL:
                            $this->copyFile(SRC_DIR_ROOT . '/Http/conf.stub.php', $confFile);
                            break;
                        case self::RPC_PROTOCOL:
                            $this->copyFile(SRC_DIR_ROOT . '/Rpc/conf.stub.php', $confFile);
                            break;
                        case self::UDP_PROTOCOL:
                            $this->copyFile(SRC_DIR_ROOT . '/Udp/conf.stub.php', $confFile);
                            break;
                        case self::WEBSOCKET_PROTOCOL:
                            $this->copyFile(SRC_DIR_ROOT . '/Websocket/conf.stub.php', $confFile);
                            break;
                        case self::MQTT_PROTOCOL:
                            $this->copyFile(SRC_DIR_ROOT . '/Mqtt/conf.stub.php', $confFile);
                            break;
                        default:
                            throw new IOException(
                                "Unsupported protocol={$protocol}",
                                0,
                                null,
                                '',
                                $confFile
                            );
                    }
                    // 各协议 conf 的 event_handler 必须指向应用 Event，而非框架默认 Handler
                    $confContent = $this->readFile($confFile);
                    $confContent = $this->personalizeProtocolConf($confContent, $appName, $protocol);
                    $this->writeFile($confFile, $confContent);
                    break;
                }
                case 'Middleware':
                {
                    switch ($protocol) {
                        case self::HTTP_PROTOCOL:
                            $this->ensureDirectory($appPathDir . '/' . $dir . '/Group');
                            $this->ensureDirectory($appPathDir . '/' . $dir . '/Route');
                            break;
                        case self::UDP_PROTOCOL:
                        case self::WEBSOCKET_PROTOCOL:
                            // 目录已在循环开头创建
                            break;
                        default:
                            break;
                    }
                    break;
                }
                case 'Service':
                {
                    switch ($protocol) {
                        case self::UDP_PROTOCOL:
                        case self::WEBSOCKET_PROTOCOL:
                        case self::RPC_PROTOCOL:
                        case self::MQTT_PROTOCOL:
                            // 目录已在循环开头创建
                            break;
                        default:
                            break;
                    }

                    if ($protocol == self::UDP_PROTOCOL || $protocol == self::WEBSOCKET_PROTOCOL) {
                        $this->writeFile(
                            $appPathDir . '/' . $dir . '/DemoService.php',
                            $this->getDefaultService($appName, $protocol)
                        );
                        if ($protocol == self::WEBSOCKET_PROTOCOL) {
                            $chatServiceContent = $this->readFile(SRC_DIR_ROOT . '/Stubs/ChatService.stub.php');
                            $chatServiceContent = str_replace('__APP_NAMESPACE__', $appName, $chatServiceContent);
                            $this->writeFile($appPathDir . '/' . $dir . '/ChatService.php', $chatServiceContent);

                            $pushDir = $appPathDir . '/Push';
                            $this->ensureDirectory($pushDir);
                            $enricherContent = $this->readFile(SRC_DIR_ROOT . '/Stubs/MessagePushEnricher.stub.php');
                            $enricherContent = str_replace('__APP_NAMESPACE__', $appName, $enricherContent);
                            $this->writeFile($pushDir . '/MessagePushEnricher.php', $enricherContent);

                            $authDir = $appPathDir . '/Auth';
                            $this->ensureDirectory($authDir);
                            $authContent = $this->readFile(SRC_DIR_ROOT . '/Stubs/WebsocketAuthCallback.stub.php');
                            $authContent = str_replace('__APP_NAMESPACE__', $appName, $authContent);
                            $this->writeFile($authDir . '/WebsocketAuthCallback.php', $authContent);
                            $groupAuthContent = $this->readFile(SRC_DIR_ROOT . '/Stubs/WebsocketGroupJoinAuthorizer.stub.php');
                            $groupAuthContent = str_replace('__APP_NAMESPACE__', $appName, $groupAuthContent);
                            $this->writeFile($authDir . '/WebsocketGroupJoinAuthorizer.php', $groupAuthContent);
                        }
                    }
                    break;
                }
                case 'Scripts':
                {
                    $kernelFileContent = $this->readFile(SRC_DIR_ROOT . '/Script/Kernel.php');
                    $kernelFileContent = str_replace('namespace Swoolefy\Script', "namespace {$appName}\\{$dir}", $kernelFileContent);
                    $this->writeFile($appPathDir . '/' . $dir . '/Kernel.php', $kernelFileContent);
                    break;
                }
                default:
                    break;
            }
        }

        $this->copyServerFile($appName, $protocol, $appPathDir);
        if ($protocol == self::HTTP_PROTOCOL) {
            $this->copyHttpI18nAndBootstrap($appName, $appPathDir);
        }
        if ($protocol == self::WEBSOCKET_PROTOCOL) {
            $this->ensureDirectory($appPathDir . '/Tests');
            $this->ensureDirectory($appPathDir . '/Storage');
            $this->copyFile(SRC_DIR_ROOT . '/Stubs/socketio.client.stub.html', $appPathDir . '/Storage/socketio-client.html');
            $testHtml = str_replace(
                '__APP_NAMESPACE__',
                $appName,
                $this->readFile(SRC_DIR_ROOT . '/Websocket/Tests/socketio-client.html')
            );
            $this->writeFile($appPathDir . '/Tests/socketio-client.html', $testHtml);
        }
    }

    /**
     * 仓库根 daemon/cron/script 入口：仅在不存在时复制，失败抛 IOException。
     *
     * @throws IOException
     */
    protected function ensureRootServiceEntryFiles(): void
    {
        $pairs = [
            START_DIR_ROOT . '/daemon.php' => SRC_DIR_ROOT . '/Stubs/daemon.stub.php',
            START_DIR_ROOT . '/cron.php' => SRC_DIR_ROOT . '/Stubs/cron.stub.php',
            START_DIR_ROOT . '/script.php' => SRC_DIR_ROOT . '/Stubs/script.stub.php',
        ];
        foreach ($pairs as $target => $source) {
            if (!file_exists($target)) {
                $this->copyFile($source, $target);
            }
        }
    }

    /**
     * @throws IOException
     */
    protected function ensureDirectory(string $dir, ?int $permission = null): void
    {
        if (is_dir($dir)) {
            return;
        }
        $permission = $permission ?? self::$dirPermission;
        $parent = dirname($dir);
        // 先确保父目录存在并可写，避免 mkdir 失败时产生 PHP Warning
        if ($parent !== $dir && !is_dir($parent)) {
            $this->ensureDirectory($parent, $permission);
        }
        if ($parent !== $dir && is_dir($parent) && !is_writable($parent)) {
            throw new IOException('Failed to create directory: parent not writable', 0, null, '', $dir);
        }
        if (!mkdir($dir, $permission) && !is_dir($dir)) {
            throw new IOException('Failed to create directory', 0, null, '', $dir);
        }
    }

    /**
     * @throws IOException
     */
    protected function copyFile(string $source, string $target): void
    {
        if (!is_file($source)) {
            throw new IOException('Source stub missing', 0, null, $source, $target);
        }
        $parent = dirname($target);
        if (!is_dir($parent)) {
            $this->ensureDirectory($parent);
        }
        if (!copy($source, $target)) {
            throw new IOException('Failed to copy file', 0, null, $source, $target);
        }
    }

    /**
     * @throws IOException
     */
    protected function writeFile(string $target, string $content): void
    {
        $parent = dirname($target);
        if (!is_dir($parent)) {
            $this->ensureDirectory($parent);
        }
        if (file_put_contents($target, $content) === false) {
            throw new IOException('Failed to write file', 0, null, '', $target);
        }
    }

    /**
     * @throws IOException
     */
    protected function readFile(string $source): string
    {
        if (!is_file($source)) {
            throw new IOException('Source file missing', 0, null, $source, '');
        }
        $content = file_get_contents($source);
        if ($content === false) {
            throw new IOException('Failed to read file', 0, null, $source, '');
        }
        return $content;
    }

    protected function removeDirectoryTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectoryTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * HTTP：Bootstrap（LocaleMiddleware）+ 默认语言包。
     *
     * @throws IOException
     */
    protected function copyHttpI18nAndBootstrap(string $appName, string $appPathDir): void
    {
        $bootstrapFile = $appPathDir . '/Bootstrap.php';
        if (!file_exists($bootstrapFile)) {
            $content = $this->readFile(SRC_DIR_ROOT . '/Stubs/Bootstrap.stub.php');
            $content = str_replace('MY_APP_NAME', $appName, $content);
            $this->writeFile($bootstrapFile, $content);
        }

        foreach (['zh_CN' => 'translations.zh_CN.messages.stub.php', 'en' => 'translations.en.messages.stub.php'] as $locale => $stub) {
            $dir = $appPathDir . '/Resource/Translations/' . $locale;
            $this->ensureDirectory($dir);
            $messagesFile = $dir . '/messages.php';
            if (!file_exists($messagesFile)) {
                $this->copyFile(SRC_DIR_ROOT . '/Stubs/' . $stub, $messagesFile);
            }
        }
    }

    /**
     * 将协议 conf 模板中的占位项替换为当前应用命名空间
     */
    protected function personalizeProtocolConf(string $confContent, string $appName, string $protocol): string
    {
        $confContent = str_replace(
            "'event_handler'            => \\Swoolefy\\Core\\EventHandler::class,",
            "'event_handler'            => \\{$appName}\\Event::class,",
            $confContent
        );
        if ($protocol === self::HTTP_PROTOCOL) {
            $confContent = str_replace(
                "'application_bootstrap'    => '',",
                "'application_bootstrap'    => \\{$appName}\\Bootstrap::class,",
                $confContent
            );
        }
        return $confContent;
    }

    /**
     * CreateCmd 路径下 Event/Autoloader 写入必须检查返回值。
     *
     * @throws IOException
     */
    protected function commonHandleFile(?string $appPathDir = null)
    {
        $appPathDir = $appPathDir ?? APP_PATH;
        $eventServerFile = $appPathDir . '/Event.php';
        if (!file_exists($eventServerFile)) {
            $fileContentString = $this->readFile(SRC_DIR_ROOT . '/Stubs/event_handle.stub.php');
            $count = 1;
            $fileContentString = str_replace('protocol\\event', APP_NAME, $fileContentString, $count);
            $this->writeFile($eventServerFile, $fileContentString);
        }

        $autoloaderFile = $appPathDir . '/Autoloader.php';
        if (!file_exists($autoloaderFile)) {
            $fileContentString = $this->readFile(dirname(SRC_DIR_ROOT) . '/Autoloader.php');
            $fileContentString = str_replace(
                ['__APP_NAMESPACE__', '<{APP_NAME}>'],
                [APP_NAME, APP_NAME],
                $fileContentString
            );
            $this->writeFile($autoloaderFile, $fileContentString);
        }
    }

    /**
     * @param string $appName
     * @param string $protocol
     * @param string|null $appPathDir
     * @return void
     * @throws IOException
     */
    protected function copyServerFile($appName, $protocol, ?string $appPathDir = null)
    {
        $appPathDir = $appPathDir ?? APP_PATH;
        $this->commonHandleFile($appPathDir);
        $protocolInfo = $this->protocolMap[$protocol] ?? [];
        if (empty($protocolInfo)) {
            $namespace = 'protocol\\http';
            $serverName = 'HttpServer';
        } else {
            $namespace = $protocolInfo['namespace'];
            $serverName = $protocolInfo['server_name'];
        }
        $eventServerFile = $appPathDir . '/' . $serverName . '.php';
        if (!file_exists($eventServerFile)) {
            $searchStr = $namespace;
            $replaceStr = "{$appName}";
            $fileContentString = $this->readFile(SRC_DIR_ROOT . '/Stubs/' . $serverName . '.stub.php');
            $count = 1;
            $fileContentString = str_replace($searchStr, $replaceStr, $fileContentString, $count);
            $this->writeFile($eventServerFile, $fileContentString);
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
# 是否开启异常捕获日志脱敏password,token等,生产环境建议开启
ENABLE_LOG_SANITIZE=false

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
# HTTP 最小采集：敏感字段脱敏(默认开)
OTEL_ATTRIBUTE_SANITIZE_ENABLED=true
# attribute 最大长度(字节)（空=不限制）
OTEL_ATTRIBUTE_MAX_LENGTH=512
# 默认开启采集body
OTEL_COLLECT_REQUEST_BODY=true
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
