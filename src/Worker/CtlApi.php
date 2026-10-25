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

namespace Swoolefy\Worker;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoolefy\Cmd\Infrastructure\ControlClient;
use Swoolefy\Core\CommandRunner;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Exception\WorkerException;
use Swoolefy\Http\ResponseCode;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Worker\Dto\ControlRequest;

/**
 * Daemon / Cron 的 HTTP 控制面。
 *
 * 进程存活以 WORKER_STATUS_FILE 中的 pid 为准；start/stop 经 UDS 控制面交给 Master。
 * 响应统一复用框架 ResponseOutput::returnJson()，保持 {code,msg,data} 结构。
 */
class CtlApi extends ResponseOutput
{
    const API_LIST = '/process-list';
    const API_START = '/process-start';
    const API_STOP = '/process-stop';
    const API_STATUS = '/process-status';
    const MASTER_RESTART = '/master-server-restart';

    /**
     * master.children_process 快照：process_name => [worker_id => runtime]
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $processRuntimeList = [];

    /**
     * 状态文件最近一次上报时间
     */
    protected string $reportTime = '';

    public function __construct(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse)
    {
        parent::__construct($swooleRequest, $swooleResponse);
        $this->reloadRuntimeStatus();
    }

    public function handle(): void
    {
        try {
            $params = $this->swooleRequest->get;
            $params = is_array($params) ? $params : [];
            $processName = $params['process_name'] ?? '';
            $processName = is_string($processName) ? trim($processName) : '';
            $path = $this->requestPath();

            switch ($path) {
                case self::API_LIST:
                    $this->processList(
                        $processName,
                        max(1, (int)($params['page'] ?? 1)),
                        max(1, (int)($params['page_size'] ?? 20))
                    );
                    break;
                case self::API_START:
                    $this->start($processName);
                    break;
                case self::API_STOP:
                    $this->stop($processName);
                    break;
                case self::API_STATUS:
                    $this->status($processName);
                    break;
                case self::MASTER_RESTART:
                    $this->restartServer();
                    break;
                default:
                    $this->fail('找不到对应的uri', ['uri' => $path, 'process_name' => $processName]);
            }
        } catch (\Throwable $throwable) {
            $this->fail($throwable->getMessage(), $this->exceptionContext($throwable));
        }
    }

    /**
     * 进程列表。running 以状态文件中的 pid 是否存活为准，配置按当前实例 --group 过滤。
     */
    public function processList(string $searchProcessName = '', int $page = 1, int $pageSize = 20): void
    {
        $confList = MainManager::includeWorkerConf();
        foreach ($confList as $index => $confItem) {
            $processName = (string)($confItem['process_name'] ?? '');
            $runtimeItems = $this->processRuntimeList[$processName] ?? [];
            $runtimeItems = is_array($runtimeItems) ? $runtimeItems : [];
            $firstRuntime = $runtimeItems === [] ? [] : $runtimeItems[array_key_first($runtimeItems)];

            $confList[$index]['process_list'] = $runtimeItems;
            $confList[$index]['running'] = $this->isProcessRunning($processName) ? 1 : 0;
            $confList[$index]['start_time'] = (string)($firstRuntime['start_time'] ?? '');
            $confList[$index]['report_time'] = $this->reportTime;
        }

        if ($searchProcessName !== '') {
            $confList = array_values(array_filter(
                $confList,
                static fn (array $confItem): bool => str_contains(
                    (string)($confItem['process_name'] ?? ''),
                    $searchProcessName
                )
            ));
        }

        $total = count($confList);
        $pageList = array_slice($confList, ($page - 1) * $pageSize, $pageSize);

        $this->success([
            'total' => $total,
            'list' => $pageList,
            'groups' => Helper::parseGroupNames(
                is_string(Helper::getCliParams('group')) ? Helper::getCliParams('group') : ''
            ),
            'serviceName' => defined('WORKER_SERVICE_NAME') ? WORKER_SERVICE_NAME : '',
        ]);
    }

    /**
     * 启动当前实例配置内的进程；已在运行则走 restart。
     */
    public function start(string $processName): void
    {
        $this->controlProcess($processName, WORKER_CLI_RESTART);
    }

    /**
     * 停止当前实例配置内的进程。
     */
    public function stop(string $processName): void
    {
        $this->controlProcess($processName, WORKER_CLI_STOP);
    }

    /**
     * 进程状态：status=1 表示至少一个 worker pid 存活。
     *
     * 状态文件由 Master 每 REPORT_STATUS_TICK_TIME 秒刷新，刚 start/stop 时可能仍是上一轮快照。
     * 未命中时最多等待一个上报周期，期间重读文件，避免返回过期状态。
     */
    public function status(string $processName): void
    {
        if ($processName === '') {
            $this->fail('缺少 process_name 参数');
            return;
        }

        if (!$this->isProcessRunning($processName)) {
            $this->awaitStatusReport($processName);
        }

        $this->success([
            'status' => $this->isProcessRunning($processName) ? 1 : 0,
            'time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 重启整个 Worker 服务，新实例带回 --group/--only 保持与当前实例一致。
     */
    public function restartServer(): void
    {
        $logFile = START_DIR_ROOT . '/restart.log';
        if (is_file($logFile) && filesize($logFile) > 2 * 1024 * 1024) {
            file_put_contents($logFile, '');
        }

        $runner = CommandRunner::getInstance('restart-' . time());
        $runner->isNextHandle(false);

        $execParts = [WORKER_START_SCRIPT_FILE, 'restart', APP_NAME, '--force=1'];
        $extra = Helper::workerRestartCliSuffix();
        if ($extra !== '') {
            $execParts[] = $extra;
        }
        [$command] = $runner->exec(SystemEnv::PhpBinFile(), implode(' ', $execParts), [], true, '/dev/null', false);

        file_put_contents($logFile, json_encode([
            'date' => date('Y-m-d H:i:s'),
            'app_name' => APP_NAME,
            'server_name' => SystemEnv::isCronService() ? 'cron' : 'daemon',
            'command' => $command,
            'message' => 'restarting...',
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

        $this->success(['action' => 'restart', 'time' => date('Y-m-d H:i:s')]);
    }

    /**
     * start/stop 共用：校验进程属于当前分组实例 → 走控制面 → 记录 confctl 运行态。
     */
    private function controlProcess(string $processName, string $action): void
    {
        if ($this->findProcessConf($processName) === []) {
            $this->fail('进程不属于当前分组实例', [
                'process_name' => $processName,
                'serviceName' => defined('WORKER_SERVICE_NAME') ? WORKER_SERVICE_NAME : '',
            ]);
            return;
        }

        $this->sendPipeCommand($processName, $action);
        $this->updateRuntimeConf($processName, $action);

        $this->success(['action' => $action, 'time' => date('Y-m-d H:i:s')]);
    }

    /**
     * 当前实例（含 --group 过滤）可见的进程配置。
     *
     * @return array<string, mixed>
     */
    private function findProcessConf(string $processName): array
    {
        if ($processName === '') {
            return [];
        }

        $confMap = array_column(MainManager::includeWorkerConf(), null, 'process_name');

        return $confMap[$processName] ?? [];
    }

    /**
     * 更新 confctl 运行态：经 ConfCtlStore 独占锁读改写，避免并发丢键与半截写。
     */
    private function updateRuntimeConf(string $processName, string $action): void
    {
        $store = new ConfCtlStore(ConfCtlStore::defaultPath());
        $store->update(function (array $fileProcessConfList) use ($processName, $action): array {
            if ($action === WORKER_CLI_STOP) {
                $fileProcessConfList[$processName] = [
                    'start_time' => '',
                    'stop_time' => date('Y-m-d H:i:s'),
                    'running' => 0,
                ];
            } elseif ($action === WORKER_CLI_RESTART || $action === WORKER_CLI_START) {
                $fileProcessConfList[$processName] = [
                    'start_time' => date('Y-m-d H:i:s'),
                    'stop_time' => '',
                    'running' => 1,
                ];
            }

            return $fileProcessConfList;
        });
    }

    /**
     * 经 UDS 控制面把指令交给 Master；master 回包写入当前请求连接。
     *
     * 运行在 HTTP/协程上下文，ControlClient 的同步 IO 会被 Swoole hook 成协程调用，
     * 超时参数继续生效。
     */
    private function sendPipeCommand(string $processName, string $action): void
    {
        $socketPath = defined('WORKER_PID_FILE_ROOT')
            ? rtrim(WORKER_PID_FILE_ROOT, '/') . '/ctl.sock'
            : '';
        if ($socketPath === '') {
            throw new WorkerException('Worker 控制面 socket 路径为空');
        }

        $request = new ControlRequest(
            WORKER_CLI_SEND_MSG,
            $processName,
            $action,
            '',
            sprintf('http-%d-%d', getmypid(), (int) (microtime(true) * 1000))
        );

        $response = (new ControlClient())->request($socketPath, $request, 5.0);
        if (!$response->ok) {
            throw new WorkerException('Worker 控制面请求失败: ' . $response->message);
        }
    }

    /**
     * 状态文件缺失或损坏时按空快照处理，不能让控制面 500。
     */
    private function reloadRuntimeStatus(): void
    {
        $this->processRuntimeList = [];
        $this->reportTime = '';

        if (!defined('WORKER_STATUS_FILE')) {
            return;
        }

        set_error_handler(static fn (): bool => true);
        try {
            $content = file_get_contents(WORKER_STATUS_FILE);
        } finally {
            restore_error_handler();
        }

        $status = is_string($content) ? json_decode($content, true) : null;
        $master = is_array($status) && is_array($status['master'] ?? null) ? $status['master'] : [];
        $children = $master['children_process'] ?? null;

        $this->processRuntimeList = is_array($children) ? $children : [];
        $this->reportTime = is_string($master['report_time'] ?? null) ? $master['report_time'] : '';
    }

    /**
     * 该进程名下是否至少有一个存活 pid。
     */
    private function isProcessRunning(string $processName): bool
    {
        $runtimeItems = $this->processRuntimeList[$processName] ?? [];
        foreach (is_array($runtimeItems) ? $runtimeItems : [] as $runtimeItem) {
            if (!is_array($runtimeItem)) {
                continue;
            }
            $pid = (int)($runtimeItem['pid'] ?? 0);
            if ($pid > 0 && \Swoole\Process::kill($pid, 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 等待状态文件刷新；协程内睡协程，避免阻塞 Worker。
     */
    private function awaitStatusReport(string $processName): void
    {
        $deadline = microtime(true) + MainManager::REPORT_STATUS_TICK_TIME + 1;
        while (microtime(true) < $deadline) {
            if (\Swoole\Coroutine::getCid() >= 0) {
                \Swoole\Coroutine::sleep(1);
            } else {
                sleep(1);
            }
            $this->reloadRuntimeStatus();
            if ($this->isProcessRunning($processName)) {
                return;
            }
        }
    }

    /**
     * 取请求 path。Swoole 的 request_uri 带 query string，不能直接和路由常量比较。
     */
    private function requestPath(): string
    {
        $server = $this->swooleRequest->server ?? [];
        $pathInfo = $server['PATH_INFO'] ?? $server['path_info'] ?? '';
        if (!is_string($pathInfo) || $pathInfo === '') {
            $requestUri = (string)($server['REQUEST_URI'] ?? $server['request_uri'] ?? '');
            $pathInfo = (string)parse_url($requestUri, PHP_URL_PATH);
        }

        $path = trim($pathInfo, '/');

        return $path === '' ? '/' : '/' . $path;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function success(array $data): void
    {
        $this->returnJson($data, ResponseCode::CodeOk, ResponseCode::CodeOkText);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fail(string $msg, array $data = []): void
    {
        // 异常可能发生在响应已写出之后，此时不能再写
        if (!$this->swooleResponse->isWritable()) {
            return;
        }

        $this->returnJson($data, ResponseCode::CodeFAIL, $msg);
    }

    /**
     * prd 不返回文件路径与调用栈，避免控制面泄露内部结构。
     *
     * @return array<string, mixed>
     */
    private function exceptionContext(\Throwable $throwable): array
    {
        $context = ['exception' => $throwable::class];
        if (!SystemEnv::isPrdEnv()) {
            $context['file'] = $throwable->getFile();
            $context['line'] = $throwable->getLine();
            $context['trace'] = $throwable->getTraceAsString();
        }

        return $context;
    }
}
