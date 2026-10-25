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

namespace Swoolefy\Worker\Runtime;

use RuntimeException;
use Swoolefy\Worker\AbstractBaseWorker;
use Swoolefy\Worker\Dto\ControlRequest;
use Swoolefy\Worker\Dto\ControlResponse;
use Swoolefy\Worker\MainManager;
use Swoolefy\Worker\Process\ProcessRegistry;

/**
 * CLI / CtlApi → Worker Master 的 Unix Domain Socket 控制面。
 *
 * 路径：`{WORKER_PID_FILE_ROOT}/ctl.sock`，按 WORKER_SERVICE_NAME / --group 隔离。
 * 一条连接一次请求/响应：4 字节大端长度 + JSON，回帧后立即关闭。
 *
 * Master 侧禁止协程：监听与连接都用 stream + 非阻塞 + Event 回调，
 * 否则后续 fork 会报 `unable to create Swoole\Process with async-io threads`。
 */
final class CliControlServer
{
    /** 同时存活的客户端连接上限，超出直接回 busy 帧 */
    public const MAX_ACTIVE_CONNECTIONS = 64;

    /** 单连接 deadline（毫秒）：只清理「连上不发数据」的连接 */
    public const CONNECTION_DEADLINE_MS = 30000;

    /**
     * sun_path 在 macOS 可用 103 字节、Linux 107 字节；超限时两个平台都会
     * 静默截断而不是 bind 失败，因此留 3 字节余量在 bind 前显式拒绝。
     */
    public const MAX_SOCKET_PATH_BYTES = 100;

    /** @var resource|null 监听 fd */
    private $listenFd = null;

    /**
     * 连接状态，键为 stream 资源 id。
     *
     * @var array<int, array{sink:ReplySink, buffer:string, read:\Closure, write:\Closure, timer:int|null}>
     */
    private array $connections = [];

    /**
     * 同步处理一个请求期间的当前连接。Master 事件回调内无协程抢占，
     * 因此该状态在 dispatch 返回后必须立即清空。
     */
    private ?ReplySink $activeSink = null;

    private string $socketPath = '';

    /**
     * bind 成功后的 dev + ino，close() 只删自己创建的那个文件。
     *
     * @var array{0:int, 1:int}|null
     */
    private ?array $socketIdentity = null;

    private bool $listening = false;

    public function __construct(
        private MainManager $manager,
        private ?string $socketPathOverride = null,
        private ?string $pidFileOverride = null
    ) {
    }

    /**
     * 创建并监听控制面 socket。三条前置规则：
     * 1. 已有 socket 时先做连接探测：还有 listener 说明活实例占用，拒绝启动；
     *    只能连上就说明是陈旧文件，清理后重新 bind；
     * 2. 只清理/删除本实例 bind 的文件（close 用 dev + ino 校验）；
     * 3. 路径超长直接抛异常，绝不接受内核静默截断。
     *
     * @return bool|null false 表示控制面未启用
     */
    public function install(): ?bool
    {
        if (!$this->manager->isCliControlEnabled()) {
            return false;
        }

        $socketPath = $this->resolvedSocketPath();
        if ($socketPath === '') {
            throw new RuntimeException('WORKER_PID_FILE_ROOT 未定义，无法创建 Worker 控制面 socket');
        }

        if (strlen($socketPath) > self::MAX_SOCKET_PATH_BYTES) {
            throw new RuntimeException(sprintf(
                'Worker 控制面 socket 路径过长（%d 字节，上限 %d）: %s',
                strlen($socketPath),
                self::MAX_SOCKET_PATH_BYTES,
                $socketPath
            ));
        }

        $this->ensureRuntimeDir(dirname($socketPath));
        $this->removeStaleSocket($socketPath);

        $errno = 0;
        $errstr = '';
        set_error_handler(static fn (): bool => true);
        try {
            $server = stream_socket_server('unix://' . $socketPath, $errno, $errstr);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($server)) {
            throw new RuntimeException(sprintf(
                '创建 Worker 控制面 socket 失败(%d): %s',
                $errno,
                $errstr !== '' ? $errstr : $socketPath
            ));
        }

        if (!@chmod($socketPath, 0700)) {
            @fclose($server);
            @unlink($socketPath);
            throw new RuntimeException('设置 Worker 控制面 socket 权限 0700 失败: ' . $socketPath);
        }

        $stat = @stat($socketPath);
        if (!is_array($stat)) {
            $this->manager->logError('读取 Worker 控制面 socket 身份失败，本次停机将跳过 unlink: ' . $socketPath);
        }
        $this->socketIdentity = is_array($stat) ? [(int) $stat['dev'], (int) $stat['ino']] : null;

        stream_set_blocking($server, false);
        $this->listenFd = $server;
        $this->socketPath = $socketPath;
        $this->listening = true;

        \Swoole\Event::add($server, function (): void {
            $this->acceptConnections();
        });

        return true;
    }

    /**
     * 分发一条控制请求。单测可直接调用，用不接内核的 ReplySink 断言帧内容。
     */
    public function dispatch(ControlRequest $request, ReplySink $sink): void
    {
        $previous = $this->activeSink;
        $this->activeSink = $sink;
        $sink->setNonce($request->nonce);

        try {
            $this->dispatchRequest($request, $sink);
        } finally {
            $this->activeSink = $previous;
        }
    }

    /**
     * 当前请求的回包出口，供 ProcessCommandHandler::responseMsgByPipe() 使用。
     */
    public function currentSink(): ?ReplySink
    {
        return $this->activeSink;
    }

    /**
     * 关机第 4 步：摘监听 → 关全部连接 → unlink 本实例 bind 的 socket 文件。
     */
    public function close(): void
    {
        if (is_resource($this->listenFd)) {
            @\Swoole\Event::del($this->listenFd);
            @fclose($this->listenFd);
        }
        $this->listenFd = null;
        $this->listening = false;

        foreach (array_keys($this->connections) as $id) {
            $this->closeConnection($id);
        }
        $this->connections = [];
        $this->activeSink = null;

        if ($this->socketPath !== '' && $this->ownsSocketFile()) {
            @unlink($this->socketPath);
        }
        $this->socketIdentity = null;
    }

    /**
     * 状态上报里的 enable_cli_pipe 字段。
     */
    public function isListening(): bool
    {
        return $this->listening;
    }

    public function getSocketPath(): string
    {
        return $this->socketPath;
    }

    /**
     * @return resource|null
     */
    public function getListenFd()
    {
        return $this->listenFd;
    }

    private function dispatchRequest(ControlRequest $request, ReplySink $sink): void
    {
        switch ($request->action) {
            case WORKER_CLI_STATUS:
                $this->manager->getStatusReporter()->masterStatusToCli($sink);
                break;
            case WORKER_CLI_STOP:
                // shutdown 以 Event::exit() + process->exit(0) 结束，之后没有 tick 能回包。
                // data 回传本实例的最长存活时间，CLI 先等 MainManager 退出再停外层 Server，
                // 否则外层 max_wait_time 到点会把仍在清理的 MainManager 直接 SIGKILL。
                $sink->respondNow(
                    'Worker 服务正在关闭，请稍候...',
                    true,
                    (string) (
                        $this->manager->getSignalShutdown()->resolveShutdownWaitSeconds()
                        + SignalShutdown::OUTER_SHUTDOWN_WAIT_SECONDS
                    )
                );
                $this->manager->shutdownMainManager('cli-control');
                break;
            case WORKER_CLI_RESTART:
                $sink->respondNow('Worker 服务重启指令已接收');
                $this->manager->logInfo('[' . date('Y-m-d H:i:s') . '] 重启整个服务，所有进程将重启');
                $this->manager->getCommandHandler()->restartServerCommand();
                break;
            case WORKER_CLI_SEND_MSG:
                $this->dispatchProcessCommand($request);
                break;
            default:
                $sink->respondText("未知控制指令: {$request->action}", false);
                break;
        }
    }

    /**
     * SEND_MSG 内层动作：start / restart / stop 指定进程，或把 {action, msg} 信封
     * 转给该进程全部 worker，由 onPipeMsg 分发自定义指令。
     *
     * START 先回帧并关闭连接，再 fork：否则新 worker 会继承客户端 fd，
     * 常驻进程泄漏连接且客户端看不到 EOF。
     */
    private function dispatchProcessCommand(ControlRequest $request): void
    {
        $processName = $request->target;
        $commands = $this->manager->getCommandHandler();
        switch ($request->op) {
            case WORKER_CLI_START:
                if (!$this->registry()->hasList($processName)) {
                    $config = $commands->parseLoadConf($processName);
                    if (empty($config)) {
                        $this->manager->logError("找不到进程名【{$processName}】的配置项！");
                        $commands->responseMsgByPipe("找不到进程名【{$processName}】的配置项！", false);
                        return;
                    }
                    $commands->responseMsgByPipe("进程【{$processName}】已开始启动，请留意！");
                    $commands->startWorkerProcessCommand($config);
                } else {
                    $commands->responseMsgByPipe("进程【{$processName}】已存在，请使用restart命令重启！", false);
                }
                break;
            case WORKER_CLI_RESTART:
                if ($this->registry()->hasWorkerGroup($processName)) {
                    $commands->responseMsgByPipe("进程【{$processName}】已开始重启，请留意！");
                    $commands->restartWorkerProcessCommand($processName);
                } else {
                    $config = $commands->parseLoadConf($processName);
                    if (empty($config)) {
                        $commands->responseMsgByPipe("找不到进程名【{$processName}】的配置项！", false);
                        return;
                    }
                    $commands->responseMsgByPipe("进程【{$processName}】已开始启动，请留意！");
                    $commands->startWorkerProcessCommand($config);
                }
                break;
            case WORKER_CLI_STOP:
                $commands->responseMsgByPipe("进程【{$processName}】开始逐步停止，请留意！");
                $commands->stopWorkerProcessCommand($processName);
                break;
            default:
                $workers = $this->registry()->getWorkersByName($processName);
                if ($workers === null) {
                    $commands->responseMsgByPipe("找不到进程名【{$processName}】的配置项！", false);
                    return;
                }
                if ($request->op === '') {
                    $commands->responseMsgByPipe('缺少内层动作（--action）', false);
                    return;
                }
                $commands->responseMsgByPipe("子进程【{$processName}】已接收到指令，请留意！");
                // onPipeMsg 按 action 分发自定义指令，必须带信封而非只发裸 msg
                $payload = (string) json_encode(
                    ['action' => $request->op, 'msg' => $request->message],
                    JSON_UNESCAPED_UNICODE
                );
                ksort($workers);
                foreach ($workers as $process) {
                    /** @var AbstractBaseWorker $process */
                    $name = $process->getProcessName();
                    $workerId = $process->getProcessWorkerId();
                    $this->manager->writeByProcessName($name, $payload, $workerId);
                }
                break;
        }
    }

    private function acceptConnections(): void
    {
        if (!is_resource($this->listenFd)) {
            return;
        }

        // 一次 tick 最多 accept 64 条，避免极端情况下饿死其它事件
        for ($i = 0; $i < 64; $i++) {
            $stream = @stream_socket_accept($this->listenFd, 0);
            if ($stream === false) {
                return;
            }

            if (count($this->connections) >= self::MAX_ACTIVE_CONNECTIONS) {
                $this->rejectBusy($stream);
                continue;
            }

            $this->registerConnection($stream);
        }
    }

    /**
     * @param resource $stream
     */
    private function registerConnection($stream): void
    {
        stream_set_blocking($stream, false);
        $id = (int) $stream;

        $sink = new ReplySink($stream, function (ReplySink $closedSink) use ($id): void {
            $this->forgetConnection($id, $closedSink);
        });

        $read = function () use ($id): void {
            $this->onReadable($id);
        };
        $write = function () use ($id): void {
            $this->onWritable($id);
        };

        $this->connections[$id] = [
            'sink' => $sink,
            'buffer' => '',
            'read' => $read,
            'write' => $write,
            'timer' => null,
        ];

        \Swoole\Event::add($stream, $read, $write, SWOOLE_EVENT_READ);

        $timerId = \Swoole\Timer::after(self::CONNECTION_DEADLINE_MS, function () use ($id): void {
            $this->onDeadline($id);
        });
        // Timer::after 返回 false 表示定时器资源耗尽；此时该连接没有 deadline 兜底，
        // 属于有意降级：注册失败也不立刻断开，连接仍能正常收发，由对端关闭释放。
        if (is_int($timerId) && isset($this->connections[$id])) {
            $this->connections[$id]['timer'] = $timerId;
        }
    }

    private function onReadable(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }

        $stream = $connection['sink']->getStream();
        if (!is_resource($stream)) {
            $this->closeConnection($id);
            return;
        }

        $buffer = $connection['buffer'];
        try {
            $payload = ControlFrame::tryRead($stream, $buffer, ControlFrame::MAX_REQUEST_BYTES);
        } catch (\Throwable $throwable) {
            $connection['sink']->respondText('控制请求帧非法: ' . $throwable->getMessage(), false);
            // flush 可能因 EAGAIN 留下残帧，与正常路径一样靠可写事件排空
            if (isset($this->connections[$id])) {
                $this->syncEvents($id);
            }
            return;
        }

        if (isset($this->connections[$id])) {
            $this->connections[$id]['buffer'] = $buffer;
        }

        if ($payload === false) {
            $this->closeConnection($id);
            return;
        }

        if ($payload === null) {
            return;
        }

        $this->handlePayload($id, $payload);

        if (isset($this->connections[$id])) {
            $this->syncEvents($id);
        }
    }

    private function handlePayload(int $id, string $payload): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }
        $sink = $connection['sink'];

        try {
            $request = ControlRequest::fromJson($payload);
        } catch (\Throwable $throwable) {
            $sink->respondText('非法控制请求: ' . $throwable->getMessage(), false);
            return;
        }

        try {
            $this->dispatch($request, $sink);
            if (!$sink->hasResponded()) {
                $sink->respondText('控制指令未产生响应', false);
            }
        } catch (\Throwable $throwable) {
            $this->manager->handleWorkerException($throwable);
            if (!$sink->hasResponded()) {
                $sink->respondText('控制指令执行失败: ' . $throwable->getMessage(), false);
            }
        }
    }

    private function onWritable(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }

        $connection['sink']->flush();

        if (isset($this->connections[$id])) {
            $this->syncEvents($id);
        }
    }

    private function onDeadline(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }

        $connection['sink']->respondText('控制连接空闲超时', false);
        if (isset($this->connections[$id])) {
            $this->closeConnection($id);
        }
    }

    /**
     * 剩余出站数据时加写事件，排空后降回只读，避免可写事件空转。
     */
    private function syncEvents(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }
        $stream = $connection['sink']->getStream();
        if (!is_resource($stream)) {
            return;
        }

        $flags = SWOOLE_EVENT_READ;
        if ($connection['sink']->wantsWrite()) {
            $flags |= SWOOLE_EVENT_WRITE;
        }
        \Swoole\Event::set($stream, $connection['read'], $connection['write'], $flags);
    }

    private function closeConnection(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }
        unset($this->connections[$id]);

        if ($connection['timer'] !== null) {
            \Swoole\Timer::clear($connection['timer']);
        }
        if ($this->activeSink === $connection['sink']) {
            $this->activeSink = null;
        }
        $connection['sink']->close();
    }

    private function forgetConnection(int $id, ReplySink $sink): void
    {
        if (!isset($this->connections[$id]) || $this->connections[$id]['sink'] !== $sink) {
            return;
        }
        $connection = $this->connections[$id];
        unset($this->connections[$id]);
        if ($connection['timer'] !== null) {
            \Swoole\Timer::clear($connection['timer']);
        }
        if ($this->activeSink === $sink) {
            $this->activeSink = null;
        }
    }

    /**
     * 超出连接上限：同步回一帧 busy 后关闭，小帧不会卡住 accept。
     *
     * @param resource $stream
     */
    private function rejectBusy($stream): void
    {
        stream_set_blocking($stream, true);
        stream_set_timeout($stream, 1);
        try {
            ControlFrame::write(
                $stream,
                ControlResponse::failure('busy: 控制面连接数已达上限')->toJson(),
                ControlFrame::MAX_RESPONSE_BYTES
            );
        } catch (\Throwable) {
        }
        @fclose($stream);
    }

    private function ensureRuntimeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException('创建 Worker 控制面运行时目录失败: ' . $dir);
            }

            return;
        }

        $perms = @fileperms($dir);
        if ($perms !== false && ($perms & 0077) !== 0 && !@chmod($dir, 0750)) {
            $this->manager->logError('Worker 控制面运行时目录权限收紧失败: ' . $dir);
        }
    }

    private function removeStaleSocket(string $socketPath): void
    {
        if (!file_exists($socketPath)) {
            return;
        }

        $type = @filetype($socketPath);
        if ($type !== 'socket') {
            @unlink($socketPath);

            return;
        }

        // 不能只看 pid 文件：AbstractProcess::__start() 会在 MainManager::start()
        // 之前把本进程 pid 写进 WORKER_PID_FILE，上一次运行残留的 sock 会被误判成
        // 「本实例正在监听」。判据必须是 socket 是否真的还有 listener。
        if ($this->isSocketListening($socketPath)) {
            throw new RuntimeException(sprintf(
                'Worker 控制面 socket 已被运行中的实例占用: %s (pid=%d)',
                $socketPath,
                $this->readPidFile()
            ));
        }

        if (!@unlink($socketPath) && file_exists($socketPath)) {
            throw new RuntimeException('清理陈旧 Worker 控制面 socket 失败: ' . $socketPath);
        }
    }

    /**
     * 连接探测：listener 存在则 connect 成功；陈旧文件返回 ECONNREFUSED。
     */
    private function isSocketListening(string $socketPath): bool
    {
        $errno = 0;
        $errstr = '';

        // connect 失败是 warning；局部吞掉，避免被框架 error handler 转成异常
        set_error_handler(static fn (): bool => true);
        try {
            $stream = stream_socket_client('unix://' . $socketPath, $errno, $errstr, 0.2);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($stream)) {
            return false;
        }

        @fclose($stream);

        return true;
    }

    private function readPidFile(): int
    {
        $pidFile = $this->resolvedPidFile();
        if ($pidFile === '' || !is_file($pidFile)) {
            return 0;
        }

        $content = @file_get_contents($pidFile);

        return is_numeric(trim((string) $content)) ? (int) trim((string) $content) : 0;
    }

    /**
     * 路径仍指向本实例 bind 的 inode 才能删，避免误删后来者新建的 socket。
     */
    private function ownsSocketFile(): bool
    {
        if ($this->socketIdentity === null) {
            return false;
        }

        $stat = @stat($this->socketPath);

        return is_array($stat)
            && (int) $stat['dev'] === $this->socketIdentity[0]
            && (int) $stat['ino'] === $this->socketIdentity[1];
    }

    private function resolvedSocketPath(): string
    {
        return $this->socketPathOverride ?? $this->manager->getCliControlSocketFile();
    }

    private function resolvedPidFile(): string
    {
        return $this->pidFileOverride ?? (defined('WORKER_PID_FILE') ? WORKER_PID_FILE : '');
    }

    /**
     * Registry 只读。
     */
    private function registry(): ProcessRegistry
    {
        return $this->manager->getRegistry();
    }
}
