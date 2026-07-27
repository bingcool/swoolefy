# src/Cmd 模块技术改造方案

## 一、现状概述

`src/Cmd` 模块是 swoolefy 框架的 CLI 命令层，基于 Symfony Console 构建，负责服务的启动、停止、重启、热重载、状态查看、监控守护、消息发送以及应用脚手架生成。

### 当前文件清单

| 文件 | 行数 | 职责 |
|---|---|---|
| `BaseCmd.php` | 430 | 命令基类：环境初始化、常量定义、PID管理、状态展示、日志写入 |
| `StartCmd.php` | 112 | 启动服务（按协议分发） |
| `StopCmd.php` | 505 | 停止服务（优雅停机 + 强制杀死） |
| `RestartCmd.php` | 309 | 重启服务（stop → 等端口释放 → start） |
| `ReloadCmd.php` | 53 | 热重载 Worker 进程（SIGUSR1） |
| `MonitorCmd.php` | 57 | 守护监控（异常退出自动重启） |
| `SendCmd.php` | 109 | 通过 FIFO 管道向 Worker 发送消息 |
| `StatusCmd.php` | 97 | 查看服务/Worker 状态 |
| `CreateCmd.php` | 558 | 应用脚手架生成器 |
| `Support/ProcessTreeTerminator.php` | 121 | 进程树清理工具 |

---

## 二、问题清单与改造方案

### 2.1 【P0-Bug】RestartCmd::serverStop() 重复 kill managerName

**位置：** `RestartCmd.php` 第 257-258 行

```php
// 当前代码（有 Bug）
ProcessTreeTerminator::killByProcessTitle($managerName);
ProcessTreeTerminator::killByProcessTitle($managerName); // ← 应为 $masterName
```

**问题：** 第二个调用应为 `$masterName`，当前写法会导致 master 进程漏杀。

**修复方案：**

```php
// 修复后
ProcessTreeTerminator::killByProcessTitle($masterName);
ProcessTreeTerminator::killByProcessTitle($managerName);
```

---

### 2.2 【P0-Bug】BaseCmd::serverStatus() 中 $startTime 未定义

**位置：** `BaseCmd.php` 第 365 行

```php
// 当前代码
if (!empty($startTime)) { // $startTime 从未赋值，永远为 undefined
```

**修复方案：** 从进程启动时间获取，或直接移除无效分支，统一使用 `'--'`：

```php
$table->setRows([
    ['master process', $pid, '--', 'running', '--'],
    ['manager process', $managerProcessId, $pid, 'running', '--'],
]);
```

如后续需要展示启动时间，可通过读取 `/proc/{pid}/stat` 或 `ps -o lstart -p {pid}` 获取。

---

### 2.3 【P1-架构】StopCmd 与 RestartCmd 停止逻辑重复且不一致

**问题详述：**

两套停止策略并存，行为不一致：

| 维度 | StopCmd | RestartCmd |
|---|---|---|
| 停止方式 | `SIGTERM → 轮询等待 → SIGKILL` | `ProcessTreeTerminator::terminateHttpServerTree()` |
| 超时计算 | `resolveStopTimeouts()` 动态读取配置 | 硬编码 `maxWaitSeconds=25` |
| Worker 停止 | `sendPipeMessage()` 封装方法 | 直接 `fopen/fwrite` 管道操作 |
| PID 校验 | `validatePidFile()` + `readMasterPid()` | 直接 `file_get_contents` + `intval` |
| 优雅停机 | 支持 WebSocket/MQTT drain_timeout | 不支持 |

**改造方案：** 抽取共享的 `ServerLifecycleManager` 服务类。

#### 新增文件：`src/Cmd/Application/ServerLifecycleManager.php`

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Application;

use Swoolefy\Core\Exec;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Worker\Dto\PipeMsgDtoWorker;

/**
 * 统一的服务生命周期管理：停止、进程校验、PID 操作。
 * StopCmd / RestartCmd / MonitorCmd 共用。
 */
final class ServerLifecycleManager
{
    private const SLEEP_INTERVAL_SECOND = 1;
    private const DEFAULT_KILL_TIMEOUT = 10;
    private const DEFAULT_STOP_TIMEOUT = 20;

    /**
     * 停止普通 Swoole Server（HTTP/WebSocket/RPC/UDP/MQTT）。
     */
    public function stopServer(string $appName): StopResult
    {
        $pidFile = $this->readPidFilePath($appName);
        if (!$pidFile || !is_file($pidFile)) {
            return StopResult::pidFileNotFound($pidFile ?? '');
        }

        $masterPid = $this->readPidFromFile($pidFile);
        if ($masterPid <= 0) {
            return StopResult::invalidPid($pidFile);
        }

        if (!ProcessTreeTerminator::isAlive($masterPid)) {
            @unlink($pidFile);
            return StopResult::alreadyStopped();
        }

        $timeouts = $this->resolveStopTimeouts($appName);

        // 发送 SIGTERM
        \Swoole\Process::kill($masterPid, SIGTERM);

        // 轮询等待优雅退出
        $startTime = time();
        while (true) {
            sleep(self::SLEEP_INTERVAL_SECOND);

            if (!ProcessTreeTerminator::isAlive($masterPid)) {
                @unlink($pidFile);
                return StopResult::success($masterPid);
            }

            if ((time() - $startTime) > $timeouts['kill']) {
                $this->forceKillAll($masterPid, $appName);
                sleep(self::SLEEP_INTERVAL_SECOND);
            }

            if ((time() - $startTime) > $timeouts['stop']) {
                $this->forceKillAll($masterPid, $appName);
                return StopResult::timeout($masterPid);
            }
        }
    }

    /**
     * 停止 WorkerService（Cron/Daemon/Script）。
     */
    public function stopWorkerService(string $appName): StopResult
    {
        $pidFile = $this->readPidFilePath($appName);
        if (!$pidFile || !is_file($pidFile)) {
            return StopResult::pidFileNotFound($pidFile ?? '');
        }

        $masterPid = $this->readPidFromFile($pidFile);
        if ($masterPid <= 0 || !ProcessTreeTerminator::isAlive($masterPid)) {
            return StopResult::alreadyStopped();
        }

        // 通过管道通知 Worker 停止
        $this->sendWorkerPipeMessage(WORKER_CLI_STOP);
        sleep(3);

        // 再停止 Server 主进程
        return $this->stopServer($appName);
    }

    /**
     * 根据 APP_PATH 读取 Protocol/conf.php 中的 pid_file 路径。
     */
    public function readPidFilePath(string $appName): string
    {
        $path = APP_PATH . '/Protocol/conf.php';
        if (!is_file($path)) {
            $path = ROOT_PATH . '/' . $appName . '/Protocol/conf.php';
        }
        if (!is_file($path)) {
            return '';
        }
        $config = (array) include $path;
        return (string) ($config['setting']['pid_file'] ?? '');
    }

    public function readPidFromFile(string $pidFile): int
    {
        if (!is_file($pidFile)) {
            return 0;
        }
        $content = file_get_contents($pidFile);
        return is_numeric($content) ? (int) $content : 0;
    }

    /**
     * 向 WorkerService 发送管道消息。
     */
    public function sendWorkerPipeMessage(string $action, string $targetHandler = '', string $message = ''): bool
    {
        if (!defined('CLI_TO_WORKER_PIPE') || !defined('WORKER_PID_FILE')) {
            return false;
        }

        $workerPid = $this->readPidFromFile(WORKER_PID_FILE);
        if ($workerPid <= 0 || !ProcessTreeTerminator::isAlive($workerPid)) {
            return false;
        }

        $pipeMsgDto = new PipeMsgDtoWorker();
        $pipeMsgDto->action = $action;
        $pipeMsgDto->targetHandler = $targetHandler;
        $pipeMsgDto->message = $message;
        $pipeMsg = serialize($pipeMsgDto);

        $pipe = @fopen(CLI_TO_WORKER_PIPE, 'w+');
        if ($pipe === false) {
            return false;
        }

        try {
            if (flock($pipe, LOCK_EX)) {
                fwrite($pipe, $pipeMsg);
                flock($pipe, LOCK_UN);
            }
            return true;
        } finally {
            fclose($pipe);
        }
    }

    /**
     * 动态解析 stop 超时配置（支持 WebSocket/MQTT graceful_shutdown）。
     *
     * @return array{kill: int, stop: int}
     */
    private function resolveStopTimeouts(string $appName): array
    {
        $kill = self::DEFAULT_KILL_TIMEOUT;
        $stop = self::DEFAULT_STOP_TIMEOUT;
        $maxWait = 10;
        $gracefulEnabled = false;
        $drainTimeout = 30;

        try {
            $protocolFile = APP_PATH . '/Protocol/conf.php';
            $protocol = is_file($protocolFile) ? (array) include $protocolFile : [];
            $maxWait = max(1, (int) ($protocol['setting']['max_wait_time'] ?? $maxWait));

            // WebSocket graceful_shutdown 配置
            $websocketFile = APP_PATH . '/Config/websocket.php';
            if (is_file($websocketFile)) {
                $websocket = (array) include $websocketFile;
                $gs = $websocket['graceful_shutdown'] ?? [];
                if (is_array($gs) && !empty($gs['enable'])) {
                    $gracefulEnabled = true;
                    $drainTimeout = max(1, (int) ($gs['drain_timeout'] ?? 30));
                }
            }

            // MQTT graceful_shutdown 配置（在 Protocol/conf.php 中）
            if (!$gracefulEnabled) {
                $gs = $protocol['graceful_shutdown'] ?? [];
                if (is_array($gs) && !empty($gs['enable'])) {
                    $gracefulEnabled = true;
                    $drainTimeout = max(1, (int) ($gs['drain_timeout'] ?? 30));
                }
            }
        } catch (\Throwable) {
            // 配置读取失败时使用默认值
        }

        if ($gracefulEnabled) {
            $kill = $drainTimeout + max(1, (int) ceil($maxWait / 2));
            $stop = $drainTimeout + $maxWait + 5;
        }

        return [
            'kill' => max(self::DEFAULT_KILL_TIMEOUT, $kill),
            'stop' => max(self::DEFAULT_STOP_TIMEOUT, $stop),
        ];
    }

    private function forceKillAll(int $masterPid, string $appName): void
    {
        $config = [];
        $path = APP_PATH . '/Protocol/conf.php';
        if (is_file($path)) {
            $config = (array) include $path;
        }
        $masterName = (string) ($config['master_process_name'] ?? 'php-swoolefy-http-master');
        $managerName = (string) ($config['manager_process_name'] ?? 'php-swoolefy-http-manager');

        ProcessTreeTerminator::killProcessTree($masterPid);
        ProcessTreeTerminator::killByProcessTitle($masterName);
        ProcessTreeTerminator::killByProcessTitle($managerName);
    }
}
```

#### 新增文件：`src/Cmd/DTO/StopStatus.php`

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\DTO;

/**
 * 停止操作状态枚举。
 * 
 * 使用 backed enum 替代字符串常量，避免拼写错误（如 'sucess'）。
 */
enum StopStatus: string
{
    case SUCCESS = 'success';
    case ALREADY_STOPPED = 'already_stopped';
    case TIMEOUT = 'timeout';
    case PID_NOT_FOUND = 'pid_not_found';
    case INVALID_PID = 'invalid_pid';

    public function label(): string
    {
        return match ($this) {
            self::SUCCESS => '停止成功',
            self::ALREADY_STOPPED => '服务已停止',
            self::TIMEOUT => '停止超时',
            self::PID_NOT_FOUND => 'PID文件不存在',
            self::INVALID_PID => 'PID无效',
        };
    }
}
```

#### 新增文件：`src/Cmd/DTO/StopResult.php`

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\DTO;

/**
 * 停止操作的不可变结果对象。
 * 
 * 使用 StopStatus enum 替代字符串，类型安全且避免拼写错误。
 */
final class StopResult
{
    public function __construct(
        public readonly StopStatus $status,
        public readonly int $pid = 0,
        public readonly string $message = '',
    ) {}

    public static function success(int $pid): self
    {
        return new self(StopStatus::SUCCESS, $pid, "Server (pid={$pid}) stopped successfully");
    }

    public static function alreadyStopped(): self
    {
        return new self(StopStatus::ALREADY_STOPPED, 0, 'Server had already stopped');
    }

    public static function timeout(int $pid): self
    {
        return new self(StopStatus::TIMEOUT, $pid, "Stop timeout, force killed remaining processes (pid={$pid})");
    }

    public static function pidFileNotFound(string $pidFile): self
    {
        return new self(StopStatus::PID_NOT_FOUND, 0, "PID file not found: {$pidFile}");
    }

    public static function invalidPid(string $pidFile): self
    {
        return new self(StopStatus::INVALID_PID, 0, "Invalid PID in file: {$pidFile}");
    }

    public function isSuccessful(): bool
    {
        return $this->status === StopStatus::SUCCESS || $this->status === StopStatus::ALREADY_STOPPED;
    }
}
```

**使用示例：**

```php
// 之前（字符串比较，容易拼写错误）
if ($result->status === 'sucess') { ... }

// 之后（enum 比较，类型安全）
if ($result->status === StopStatus::SUCCESS) { ... }

// 或者使用便捷方法
if ($result->isSuccessful()) { ... }

// 获取状态标签（用于日志输出）
fmtPrintInfo($result->status->label()); // 输出：停止成功
```

**改造后 StopCmd 核心逻辑简化为：**

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $appName = $input->getArgument(self::APP_NAME);
    if (!$this->confirmStop($appName, $input->getOption(self::FORCE))) {
        return 0;
    }

    $manager = new ServerLifecycleManager();
    $result = SystemEnv::isWorkerService()
        ? $manager->stopWorkerService($appName)
        : $manager->stopServer($appName);

    // 根据 $result 输出对应日志/提示
    return $result->isSuccessful() ? 0 : 1;
}
```

**改造后 RestartCmd 核心逻辑简化为：**

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $this->ignoreTerminationSignalsFromParentServer();
    $appName = $input->getArgument(self::APP_NAME);

    if (!$this->confirmRestart($appName, $input->getOption(self::FORCE))) {
        return 0;
    }

    $manager = new ServerLifecycleManager();
    $masterPid = $manager->readPidFromFile($manager->readPidFilePath($appName));

    // 停止
    $result = SystemEnv::isWorkerService()
        ? $manager->stopWorkerService($appName)
        : $manager->stopServer($appName);

    // 等待原进程退出
    $this->waitForShutdown($masterPid);

    // 等待端口释放 + 启动
    $this->waitPortReleasedForRestart('127.0.0.1', (int) WORKER_PORT, $appName);
    $this->invokeStartCommand($appName);

    // 验证启动成功
    return $this->verifyStartup($masterPid, $manager->readPidFilePath($appName));
}
```

---

### 2.4 【P1-架构】FIFO 管道通信代码散落多处

**问题详述：** 管道消息的构造、写入、响应监听在以下位置各自实现：

| 位置 | 操作 |
|---|---|
| `StopCmd::sendPipeMessage()` | 构造 DTO → fopen → flock → fwrite |
| `RestartCmd::workerStop()` | 构造 DTO → fopen → flock → fwrite（重复代码） |
| `SendCmd::execute()` | 构造 DTO → 创建响应 FIFO → Swoole Event 监听 → fopen → fwrite |
| `StatusCmd::workerStatus()` | 构造 DTO → 创建响应 FIFO → Swoole Event 监听 → fopen → fwrite |

**改造方案：** 将管道操作统一收敛到 `ServerLifecycleManager::sendWorkerPipeMessage()`（已在 2.3 中定义），同时将响应监听抽取为 `FifoPipeClient`。

#### 新增文件：`src/Cmd/Infrastructure/FifoPipeClient.php`

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

/**
 * FIFO 命名管道的客户端封装：创建响应管道、监听异步响应、超时退出。
 */
final class FifoPipeClient
{
    /**
     * 创建响应管道，监听 Worker 回复，超时后自动退出。
     *
     * @param string $responsePipe  响应 FIFO 路径
     * @param int $timeoutMs        超时毫秒数
     * @param callable $onMessage   收到消息回调 fn(string $msg): void
     */
    public static function listenResponse(string $responsePipe, int $timeoutMs, callable $onMessage): void
    {
        if (file_exists($responsePipe)) {
            unlink($responsePipe);
        }
        posix_mkfifo($responsePipe, 0777);

        $ctlPipe = fopen($responsePipe, 'w+');
        stream_set_blocking($ctlPipe, false);

        \Swoole\Timer::after($timeoutMs, function () {
            \Swoole\Event::exit();
        });

        \Swoole\Event::add($ctlPipe, function () use ($ctlPipe, $onMessage) {
            $msg = fread($ctlPipe, 8192);
            $onMessage($msg ?: '');
            \Swoole\Event::exit();
        });

        \Swoole\Event::wait();
        fclose($ctlPipe);
        @unlink($responsePipe);
    }
}
```

**改造后 SendCmd 核心逻辑：**

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    // ... 参数校验 ...

    $manager = new ServerLifecycleManager();
    $pipeMsgDto = new PipeMsgDtoWorker();
    $pipeMsgDto->action = WORKER_CLI_SEND_MSG;
    $pipeMsgDto->targetHandler = $processName;
    $pipeMsgDto->message = json_encode(['action' => $action, 'msg' => $msg]);

    FifoPipeClient::listenResponse(WORKER_TO_CLI_PIPE, 5000, function (string $msg) {
        fmtPrintInfo($msg ?: '已向master进程发起跑脚本指令');
    });

    $manager->sendWorkerPipeMessage(WORKER_CLI_SEND_MSG, $processName, json_encode([
        'action' => $action, 'msg' => $msg,
    ]));

    return 0;
}
```

---

### 2.5 【P2-重构】BaseCmd 职责过多（God Object）

**当前职责混合：**

| 职责 | 方法 | 行数 |
|---|---|---|
| 环境初始化 | `parseConstant()`, `parseOptions()` | ~50 |
| 运行检查 | `checkRunning()` | ~30 |
| PID 文件管理 | `getPidFile()`, `makeDirLogAndPid()` | ~60 |
| 状态展示 | `serverStatus()` | ~50 |
| 日志写入 | `writeLog()` | ~25 |
| 配置管理 | `resetConf()`, `loadGlobalConf()` | ~20 |
| 文件生成 | `commonHandleFile()` | ~25 |
| 选项解析 | `beforeInputOptions()` | ~15 |

**改造方案：** 按单一职责拆分。

#### 2.5.1 抽取 `src/Cmd/Infrastructure/PidFileManager.php`

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

/**
 * PID 文件管理：读取、校验、目录创建。
 * 
 * 设计原则：只管理自己的 PID 文件，不扫描目录。
 * 生产环境中多应用共享 runtime/pid/ 目录时，扫描目录可能误删其他应用的 PID 文件
 * （例如 app2 正在启动瞬间 isAlive() 返回 false 导致误删）。
 */
final class PidFileManager
{
    /**
     * 从 Protocol/conf.php 读取 pid_file 路径，自动创建目录。
     */
    public static function resolve(string $appName): string
    {
        $path = APP_PATH . '/Protocol/conf.php';
        if (!is_file($path)) {
            return '';
        }

        $config = (array) include $path;
        $pidFile = $config['setting']['pid_file'] ?? '';
        if (!$pidFile) {
            return '';
        }

        $dir = pathinfo($pidFile, PATHINFO_DIRNAME);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $pidFile;
    }

    public static function read(string $pidFile): int
    {
        if (!is_file($pidFile)) {
            return 0;
        }
        $content = file_get_contents($pidFile);
        return is_numeric($content) ? (int) $content : 0;
    }

    public static function remove(string $pidFile): void
    {
        if (is_file($pidFile)) {
            @unlink($pidFile);
        }
    }

    /**
     * 仅当指定 PID 文件对应的进程已死亡时，才删除该文件。
     * 只操作传入的单个文件，不扫描目录，避免误删其他应用的 PID 文件。
     */
    public static function removeIfDead(string $pidFile): void
    {
        if (!is_file($pidFile)) {
            return;
        }

        $pid = self::read($pidFile);
        if ($pid <= 0 || !ProcessTreeTerminator::isAlive($pid)) {
            @unlink($pidFile);
        }
    }
}
```

#### 2.5.2 抽取 `src/Cmd/Infrastructure/CmdLogWriter.php`

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

/**
 * CLI 命令层日志写入，支持文件大小轮转。
 */
final class CmdLogWriter
{
    private const DEFAULT_MAX_SIZE = 5 * 1024 * 1024; // 5MB

    public static function write(string $msg): void
    {
        if (!defined('WORKER_CTL_LOG_FILE')) {
            return;
        }

        $logFile = WORKER_CTL_LOG_FILE;
        $maxSize = defined('MAX_LOG_FILE_SIZE') ? MAX_LOG_FILE_SIZE : self::DEFAULT_MAX_SIZE;

        // 日志轮转
        if (is_file($logFile) && filesize($logFile) > $maxSize) {
            unlink($logFile);
        }

        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $logFd = fopen($logFile, 'a+');
        $date = date('Y-m-d H:i:s');
        fwrite($logFd, "【{$date}】{$msg}" . PHP_EOL);
        fclose($logFd);
    }
}
```

#### 2.5.3 抽取 `src/Cmd/Infrastructure/ServerStatusRenderer.php`

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

use Swoolefy\Core\Exec;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * 服务进程状态表格渲染。
 */
final class ServerStatusRenderer
{
    public static function render(string $appName, string $pidFile): void
    {
        if (!is_file($pidFile)) {
            fmtPrintError("PID file={$pidFile} does not exist, server may not be running");
            return;
        }

        $pid = (int) file_get_contents($pidFile);
        if (!ProcessTreeTerminator::isAlive($pid)) {
            fmtPrintError("Server may have shutdown, use 'ps -ef | grep php-swoolefy' to check");
            return;
        }

        SystemEnv::formatPrintStartLog();

        $managerPid = -1;
        $workerPids = [];
        $children = ProcessTreeTerminator::childPids($pid);
        if (!empty($children)) {
            $managerPid = $children[0];
            $workerPids = ProcessTreeTerminator::childPids($managerPid);
        }

        $output = new ConsoleOutput();
        $table = new Table($output);
        $table->setHeaders(['进程名称', '进程ID', '父进程ID', '进程状态', '启动时间']);

        $rows = [
            ['master process', $pid, '--', 'running', '--'],
            ['manager process', $managerPid, $pid, 'running', '--'],
        ];
        foreach ($workerPids as $idx => $workerPid) {
            $rows[] = ["worker process-{$idx}", $workerPid, $managerPid, 'running', '--'];
        }
        $table->setRows($rows);

        $tableStyle = new TableStyle();
        $tableStyle->setCellRowFormat('<info>%s</info>');
        $table->setStyle($tableStyle)->render();
    }
}
```

#### 2.5.4 BaseCmd 瘦身后的结构

```php
class BaseCmd extends Command
{
    const APP_NAME = 'app_name';
    const DAEMON = 'daemon';
    const FORCE = 'force';
    const START_MODEL = 'start_model';

    // 协议常量
    const HTTP_PROTOCOL = 'http';
    const UDP_PROTOCOL = 'udp';
    const WEBSOCKET_PROTOCOL = 'websocket';
    const MQTT_PROTOCOL = 'mqtt';
    const RPC_PROTOCOL = 'rpc';

    protected SymfonyStyle $consoleStyleIo;

    protected $protocolMap = [ /* ... 保持不变 ... */ ];

    protected function configure()
    {
        // 保持现有逻辑，但使用 beforeInputOptions() 注册动态选项
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->consoleStyleIo = new SymfonyStyle($input, $output);
        $this->initCheck($input, $output);
        $this->parseConstant($input, $output);
        $this->parseOptions($input, $output);
    }

    // parseConstant / parseOptions / initCheck / beforeInputOptions 保留在 BaseCmd
    // checkRunning 委托给 PidFileManager + ServerLifecycleManager
    // getPidFile / makeDirLogAndPid → PidFileManager
    // serverStatus → ServerStatusRenderer
    // writeLog → CmdLogWriter
}
```

---

### 2.6 【P2-重构】StartCmd 的 switch-case 可简化

**问题：** `StartCmd` 中 5 个协议方法（`startHttpServer/startWebsocket/startRpc/startUdp/startMqtt`）实现完全相同：

```php
protected function startHttpServer(string $appName, string $protocol) {
    $serverName = $this->protocolMap[$protocol]['server_name'];
    $this->startServer($appName, $serverName);
}
// startWebsocket、startRpc、startUdp、startMqtt 完全一样
```

**改造方案：** 移除 5 个方法，直接在 switch/match 中调用 `startServer()`：

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    global $beforeFunc;
    if (isset($beforeFunc) && is_callable($beforeFunc)) {
        call_user_func($beforeFunc);
    }

    $serverName = $input->getArgument(self::APP_NAME);
    foreach (APP_META_ARR as $appName => $appItem) {
        if ($appName !== $serverName) {
            continue;
        }

        $protocol = $appItem['protocol'];
        if (!isset($this->protocolMap[$protocol])) {
            fmtPrintError("Unsupported protocol: {$protocol}");
            return 1;
        }

        try {
            $this->startServer($appName, $this->protocolMap[$protocol]['server_name']);
        } catch (\Throwable $e) {
            fmtPrintError($e->getMessage() . ', trace=' . $e->getTraceAsString());
            return 1;
        }
    }
    return 0;
}
```

---

### 2.7 【P2-重构】RestartCmd::execute() 巨型方法拆分

**当前：** 160+ 行，包含确认交互、停止、轮询、端口等待、内部调 start、验证启动。

**改造方案：**

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $this->ignoreTerminationSignalsFromParentServer();

    $appName = $input->getArgument(self::APP_NAME);
    $force = $input->getOption(self::FORCE);

    if (!$this->confirmRestart($appName, $force)) {
        $this->printCancelMessage($appName);
        return 0;
    }

    $manager = new ServerLifecycleManager();
    $pidFile = $manager->readPidFilePath($appName);
    $masterPid = $manager->readPidFromFile($pidFile);

    // 1. 停止服务
    $result = SystemEnv::isWorkerService()
        ? $manager->stopWorkerService($appName)
        : $manager->stopServer($appName);

    $this->writeLog("重启服务：" . (SystemEnv::isWorkerService() ? WORKER_SERVICE_NAME : $appName));

    // 2. 等待原进程退出
    $this->waitForShutdown($masterPid);

    // 3. 等待端口释放
    $this->waitPortReleasedForRestart('127.0.0.1', (int) WORKER_PORT, $appName);

    // 4. 清理 restart PID 文件
    $this->cleanRestartPidFile();

    // 5. 启动新服务
    $this->invokeStartCommand($appName);

    // 6. 验证启动成功
    return $this->verifyStartup($masterPid, $pidFile);
}
```

每个子方法职责清晰，单个方法不超过 30 行。

---

### 2.8 【P3-改善】消除 exit(0) 硬退出

**问题位置统计：**

| 文件 | exit(0) 出现次数 |
|---|---|
| `BaseCmd::parseConstant()` | 2 |
| `BaseCmd::checkRunning()` | 2 |
| `BaseCmd::makeDirLogAndPid()` | 1 |
| `RestartCmd::execute()` | 3 |
| `SendCmd::execute()` | 3 |
| `StatusCmd::workerStatus()` | 4 |
| `MonitorCmd::execute()` | 1 |
| `StartCmd::execute()` | 1 |

**改造方案：**

1. `parseConstant()` / `checkRunning()` / `makeDirLogAndPid()` 中的校验失败 → 抛出自定义 `CmdInitException`
2. 在 `BaseCmd::initialize()` 中统一 catch `CmdInitException` 并设置 `$this->initError`
3. `execute()` 开头检查 `$this->initError`，通过 `return Command::FAILURE` 返回
4. 各 `execute()` 中的 `exit(0)` → 改为 `return 0` 或 `return 1`

#### 新增异常类：`src/Cmd/Infrastructure/CmdInitException.php`

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

class CmdInitException extends \RuntimeException
{
}
```

---

### 2.9 【P3-改善】initCheck() 版本检查过时

**当前代码：**

```php
if (version_compare(phpversion(), '7.3.0', '<')) { ... }
if (version_compare(swoole_version(), '4.8.5', '<')) { ... }
```

**问题：** 项目使用 `#[AsCommand]` Attribute（需 PHP 8.1+），以及 Swoole 5.x 特性，检查无意义。

**改造方案：**

```php
protected function initCheck(InputInterface $input, OutputInterface $output)
{
    if (version_compare(phpversion(), '8.1.0', '<')) {
        fmtPrintError("PHP version must >= 8.1.0, current: " . phpversion());
    }

    if (version_compare(swoole_version(), '5.1.0', '<')) {
        fmtPrintError("Swoole version must >= 5.1.0, current: " . swoole_version());
    }

    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
}
```

同时移除 `apc_clear_cache()` 调用（APC 在 PHP 8.x 已不再使用）。

---

### 2.10 【P3-改善】MonitorCmd 内部调用 restart 的方式可优化

**当前：** 通过 `ArrayInput` + `$this->getApplication()->run()` 内部调用 restart 命令。

**问题：** 这种方式会导致 Symfony Console 的初始化流程重复执行，且 `exit(0)` 导致无法正常返回。

**改造方案：** 保持现有 `ArrayInput` 调用方式（因为需要完整走 restart 流程），但将 `exit(0)` 改为 `return 0`。

---

### 2.11 【P1-架构】缺少命令上下文对象，全局常量污染严重

**问题详述：**

当前所有 Command 依赖大量全局常量获取应用信息：

| 全局常量 | 用途 |
|---|---|
| `APP_PATH` | 应用目录 |
| `ROOT_PATH` | 根目录 |
| `WORKER_PORT` | 服务端口 |
| `WORKER_SERVICE_NAME` | Worker 服务名称 |
| `WORKER_PID_FILE` | Worker PID 文件路径 |
| `CLI_TO_WORKER_PIPE` | FIFO 管道路径 |
| `WORKER_TO_CLI_PIPE` | 响应管道路径 |

这些常量在 `BaseCmd::parseConstant()` 中定义，每个 Command 都直接读取，导致：
- 无法进行单元测试（全局常量无法 mock）
- 代码可读性差（不知道哪个常量在哪里定义）
- `BaseCmd` 越来越胖（每次新增配置项都要加 parse 逻辑）

**改造方案：** 引入 `CmdContext` DTO，在 `BaseCmd::initialize()` 中构建，所有 Command 通过 `$this->context()` 获取。

#### 新增文件：`src/Cmd/DTO/CmdContext.php`

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\DTO;

/**
 * 命令上下文：封装单次命令执行所需的全部应用信息。
 * 
 * 替代散落各处的全局常量（APP_PATH、WORKER_PORT、WORKER_PID_FILE 等），
 * 所有 Command 通过 $this->context() 统一获取。
 */
final class CmdContext
{
    public function __construct(
        /** 应用名称 */
        public readonly string $appName,
        /** 协议类型：http/websocket/rpc/udp/mqtt */
        public readonly string $protocol,
        /** 应用根目录 */
        public readonly string $appPath,
        /** Protocol/conf.php 完整配置 */
        public readonly array $config,
        /** PID 文件路径 */
        public readonly string $pidFile,
        /** 当前 PID 文件中的进程 ID（可能为 0） */
        public readonly int $pid,
        /** 服务端口 */
        public readonly int $port,
        /** 服务类名 */
        public readonly string $serverClass,
        /** 是否为 WorkerService（Cron/Daemon/Script） */
        public readonly bool $isWorkerService,
        /** Worker PID 文件路径（仅 WorkerService） */
        public readonly string $workerPidFile = '',
        /** CLI→Worker FIFO 管道路径 */
        public readonly string $cliToWorkerPipe = '',
        /** Worker→CLI 响应管道路径 */
        public readonly string $workerToCliPipe = '',
        /** 日志文件路径 */
        public readonly string $logFile = '',
    ) {}

    /**
     * 从 Protocol/conf.php 配置构建上下文。
     */
    public static function fromConfig(string $appName, array $appMeta): self
    {
        $appPath = ROOT_PATH . '/' . $appName;
        $protocol = $appMeta['protocol'] ?? '';
        $isWorkerService = defined('WORKER_SERVICE_NAME');

        // 读取 Protocol/conf.php
        $configFile = $appPath . '/Protocol/conf.php';
        $config = is_file($configFile) ? (array) include $configFile : [];

        $pidFile = (string) ($config['setting']['pid_file'] ?? '');
        $pid = 0;
        if ($pidFile && is_file($pidFile)) {
            $content = file_get_contents($pidFile);
            $pid = is_numeric($content) ? (int) $content : 0;
        }

        // 确定 serverClass
        $protocolMap = [
            'http' => 'HttpServer',
            'rpc' => 'RpcServer',
            'udp' => 'UdpEventServer',
            'websocket' => 'WebsocketEventServer',
            'mqtt' => 'MqttServer',
        ];
        $serverClass = $appName . '\\' . ($protocolMap[$protocol] ?? '');

        return new self(
            appName: $appName,
            protocol: $protocol,
            appPath: $appPath,
            config: $config,
            pidFile: $pidFile,
            pid: $pid,
            port: (int) ($config['port'] ?? (defined('WORKER_PORT') ? WORKER_PORT : 0)),
            serverClass: $serverClass,
            isWorkerService: $isWorkerService,
            workerPidFile: defined('WORKER_PID_FILE') ? WORKER_PID_FILE : '',
            cliToWorkerPipe: defined('CLI_TO_WORKER_PIPE') ? CLI_TO_WORKER_PIPE : '',
            workerToCliPipe: defined('WORKER_TO_CLI_PIPE') ? WORKER_TO_CLI_PIPE : '',
            logFile: defined('WORKER_CTL_LOG_FILE') ? WORKER_CTL_LOG_FILE : '',
        );
    }

    /**
     * 获取进程名称配置（用于进程树清理）。
     *
     * @return array{master: string, manager: string}
     */
    public function processNames(): array
    {
        return [
            'master' => (string) ($this->config['master_process_name'] ?? 'php-swoolefy-http-master'),
            'manager' => (string) ($this->config['manager_process_name'] ?? 'php-swoolefy-http-manager'),
        ];
    }
}
```

**改造后 BaseCmd 增加 context 构建：**

```php
class BaseCmd extends Command
{
    private ?CmdContext $cmdContext = null;
    private ?\Throwable $initError = null;

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->consoleStyleIo = new SymfonyStyle($input, $output);

        try {
            $this->initCheck($input, $output);
            $this->parseConstant($input, $output);
            $this->parseOptions($input, $output);
            $this->buildContext($input);
        } catch (CmdInitException $e) {
            $this->initError = $e;
            fmtPrintError($e->getMessage());
        }
    }

    /**
     * 构建命令上下文，所有子类通过此方法获取应用信息。
     */
    private function buildContext(InputInterface $input): void
    {
        $appName = $input->getArgument(self::APP_NAME);
        $appMeta = APP_META_ARR[$appName] ?? null;
        if (!$appMeta) {
            throw new CmdInitException("App '{$appName}' not found in APP_META_ARR");
        }
        $this->cmdContext = CmdContext::fromConfig($appName, $appMeta);
    }

    /**
     * 获取命令上下文。
     */
    protected function context(): CmdContext
    {
        if ($this->cmdContext === null) {
            throw new \LogicException('CmdContext not initialized');
        }
        return $this->cmdContext;
    }

    protected function hasInitError(): bool
    {
        return $this->initError !== null;
    }
}
```

**改造后各 Command 使用方式：**

```php
// StartCmd
protected function execute(InputInterface $input, OutputInterface $output): int
{
    if ($this->hasInitError()) return 1;

    $ctx = $this->context();
    // 直接用 $ctx->appName, $ctx->serverClass, $ctx->port
    // 不再需要 APP_PATH, WORKER_PORT 等全局常量
}

// StopCmd
protected function execute(InputInterface $input, OutputInterface $output): int
{
    if ($this->hasInitError()) return 1;

    $ctx = $this->context();
    // $ctx->pidFile, $ctx->pid, $ctx->processNames()
}

// RestartCmd
protected function execute(InputInterface $input, OutputInterface $output): int
{
    if ($this->hasInitError()) return 1;

    $ctx = $this->context();
    // $ctx->port, $ctx->pidFile, $ctx->pid
}
```

**ServerLifecycleManager 改为接收 CmdContext：**

```php
// 之前
public function stopServer(string $appName): StopResult

// 之后
public function stopServer(CmdContext $ctx): StopResult
{
    // 直接使用 $ctx->pidFile, $ctx->pid, $ctx->processNames()
    // 不再内部重复读取配置文件
}
```

---

## 三、改造后的目录结构

采用四层架构：Command（命令层）、Application（业务层）、Infrastructure（基础设施层）、DTO（数据传输对象）。

类似 Laravel Artisan / Symfony Console Application Service / Spring Boot CLI 的分层设计。

```
src/Cmd/
├── BaseCmd.php                         # 命令基类：~150 行，configure/initialize/context 构建
│
├── Command/                            # 命令层：仅负责参数解析、交互确认、结果输出
│   ├── StartCmd.php                    # ~60 行，移除 5 个重复方法
│   ├── StopCmd.php                     # ~60 行，核心逻辑委托 Application 层
│   ├── RestartCmd.php                  # ~120 行，拆分为 6 个子方法
│   ├── ReloadCmd.php                   # 保持不变
│   ├── MonitorCmd.php                  # 简化：移除 exit(0)
│   ├── SendCmd.php                     # ~50 行，复用 FifoPipeClient
│   ├── StatusCmd.php                   # ~50 行，复用 FifoPipeClient + ServerStatusRenderer
│   └── CreateCmd.php                   # 保持不变（脚手架生成逻辑相对独立）
│
├── Application/                        # 业务层：服务生命周期管理
│   ├── ServerLifecycleManager.php      # 统一的服务停止/重启逻辑
│   ├── ServerStarter.php               # 服务启动逻辑（从 StartCmd 抽出）
│   └── ServerStopper.php               # 服务停止策略（优雅停机 + 强制杀死）
│
├── Infrastructure/                     # 基础设施层：与操作系统交互的底层工具
│   ├── ProcessManager.php              # 进程管理（原 ProcessTreeTerminator 重命名）
│   ├── PidFileManager.php              # PID 文件读写、校验、目录创建
│   ├── FifoPipeClient.php              # FIFO 命名管道客户端封装
│   ├── CmdLogger.php                   # CLI 命令层日志写入（支持文件大小轮转）
│   ├── ServerStatusRenderer.php        # 服务进程状态表格渲染
│   └── CmdInitException.php            # 命令初始化异常
│
└── DTO/                                # 数据传输对象
    ├── CmdContext.php                  # 命令上下文：封装 appName/protocol/config/pidFile/pid
    ├── StopStatus.php                  # 停止状态枚举（PHP 8.1 backed enum）
    └── StopResult.php                  # 停止操作的不可变结果对象
```

### 各层职责边界

| 层级 | 职责 | 依赖方向 |
|---|---|---|
| **Command** | 解析 CLI 参数、用户交互、输出结果 | → Application, DTO |
| **Application** | 服务生命周期业务逻辑 | → Infrastructure, DTO |
| **Infrastructure** | 进程/PID/管道/日志/渲染等底层操作 | → DTO（仅类型引用） |
| **DTO** | 纯数据载体，无业务逻辑 | 无依赖 |

---

## 四、改造实施计划

### Phase 1：Bug 修复（低风险，立即可做）

| 序号 | 任务 | 文件 | 预估工时 |
|---|---|---|---|
| 1.1 | 修复 `RestartCmd::serverStop()` 第258行重复 kill managerName | `RestartCmd.php` | 5 min |
| 1.2 | 修复 `BaseCmd::serverStatus()` 中 `$startTime` 未定义 | `BaseCmd.php` | 10 min |
| 1.3 | 更新 `initCheck()` 版本检查为 PHP 8.1+ / Swoole 5.1+ | `BaseCmd.php` | 5 min |

### Phase 2：创建目录结构 + DTO 层（低风险）

| 序号 | 任务 | 文件 | 预估工时 |
|---|---|---|---|
| 2.1 | 创建 `Command/`、`Application/`、`Infrastructure/`、`DTO/` 目录 | — | 5 min |
| 2.2 | 新建 `DTO/CmdContext.php` | `DTO/` | 30 min |
| 2.3 | 新建 `DTO/StopStatus.php`（enum） | `DTO/` | 10 min |
| 2.4 | 新建 `DTO/StopResult.php`（使用 StopStatus） | `DTO/` | 10 min |
| 2.5 | 新建 `Infrastructure/CmdInitException.php` | `Infrastructure/` | 5 min |

### Phase 3：Infrastructure 层（中风险）

| 序号 | 任务 | 文件 | 预估工时 |
|---|---|---|---|
| 3.1 | 移动 `ProcessTreeTerminator.php` → `Infrastructure/ProcessManager.php` | `Infrastructure/` | 15 min |
| 3.2 | 新建 `Infrastructure/PidFileManager.php` | `Infrastructure/` | 30 min |
| 3.3 | 新建 `Infrastructure/CmdLogger.php` | `Infrastructure/` | 15 min |
| 3.4 | 新建 `Infrastructure/ServerStatusRenderer.php` | `Infrastructure/` | 20 min |
| 3.5 | 新建 `Infrastructure/FifoPipeClient.php` | `Infrastructure/` | 20 min |

### Phase 4：Application 层（中风险，依赖 Phase 3）

| 序号 | 任务 | 文件 | 预估工时 |
|---|---|---|---|
| 4.1 | 新建 `Application/ServerLifecycleManager.php`（接收 CmdContext） | `Application/` | 60 min |
| 4.2 | 新建 `Application/ServerStarter.php`（从 StartCmd 抽出） | `Application/` | 30 min |
| 4.3 | 新建 `Application/ServerStopper.php`（从 StopCmd 抽出） | `Application/` | 30 min |

### Phase 5：Command 层重构（中风险，依赖 Phase 4）

| 序号 | 任务 | 文件 | 预估工时 |
|---|---|---|---|
| 5.1 | 移动命令文件到 `Command/` 目录，更新 namespace | 多个文件 | 20 min |
| 5.2 | 重构 `BaseCmd.php`，增加 `context()` 构建逻辑 | `BaseCmd.php` | 40 min |
| 5.3 | 重构 `StopCmd.php`，使用 `ServerLifecycleManager` + `CmdContext` | `Command/StopCmd.php` | 30 min |
| 5.4 | 重构 `RestartCmd.php`，拆分子方法 + 使用 `CmdContext` | `Command/RestartCmd.php` | 45 min |
| 5.5 | 简化 `StartCmd.php`，使用 `ServerStarter` + `CmdContext` | `Command/StartCmd.php` | 20 min |
| 5.6 | 重构 `SendCmd.php`，复用 `FifoPipeClient` + `CmdContext` | `Command/SendCmd.php` | 20 min |
| 5.7 | 重构 `StatusCmd.php`，复用 `ServerStatusRenderer` + `CmdContext` | `Command/StatusCmd.php` | 20 min |
| 5.8 | 简化 `MonitorCmd.php`，移除 exit | `Command/MonitorCmd.php` | 10 min |

### Phase 6：消除 exit(0) + 清理（低风险，收尾）

| 序号 | 任务 | 文件 | 预估工时 |
|---|---|---|---|
| 6.1 | `BaseCmd` 中 exit → 抛 `CmdInitException` | `BaseCmd.php` | 20 min |
| 6.2 | 各 `Cmd` 中 exit → return 值 | 多个文件 | 30 min |
| 6.3 | 清理旧 `Support/` 目录 | — | 5 min |

**总预估工时：** 约 8-9 小时

---

## 五、改造原则

1. **向后兼容**：所有命令的 CLI 接口（参数、选项、输出格式）保持不变
2. **渐进式改造**：Phase 1 可独立合入，Phase 2-5 建议作为一个 PR 整体合入
3. **可测试性**：Infrastructure 层均为 `final` 类 + 静态方法；Application 层可注入依赖；CmdContext 可 mock
4. **不引入新依赖**：仅使用现有 Symfony Console + Swoole 组件
5. **分层依赖规则**：Command → Application → Infrastructure → DTO，禁止反向依赖
6. **上下文对象优先**：所有应用信息通过 `CmdContext` 传递，避免直接读取全局常量
