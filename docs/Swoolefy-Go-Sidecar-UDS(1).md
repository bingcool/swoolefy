# Swoolefy Core/Sidecar + UDS 技术方案

> 状态：可实施。第一阶段只做进程托管和一次一连接的 UDS 调用，RocketMQ Producer 作为第一个外部进程，不进 PHP Worker。  
> 依据：当前仓库源码。本文是当前方案。

---

## 1. 范围

Sidecar 在 `EventCtrl::init()` 里统一注册。`attach()` 不判断 `SystemEnv::isWorkerService()`，HTTP、daemon、cron、script 使用同一条路径。应用不继承 `EventHandler` 去手写 `new Process` + `exec`。

进程拉起、socket 路径、帧读写、超时和「请求已发出但结果未知」放在 `src/Core/Sidecar`。RocketMQ 是第一个使用者。

边界：

- 不把 Go/Python 塞进 `AbstractProcess` 再 `exec()`。`AbstractProcess::__start()` 还要跑 pipe、`SIGTERM` 回调和 `onShutDown()`，映像被替换后这些 PHP 逻辑都不存在。
- 不注册进 `MainManager` / `ProcessSupervisor`。那条链只拉起 `AbstractBaseWorker`。
- 不在多个协程之间共享一个同步 `Swoole\Client`。
- 不使用固定的 `/tmp/rocketmq-sidecar.sock`，不用 `0666`，不在启动时无条件删除 socket 文件。
- `rocketmq.producer.send` 在请求帧已经写完但没收到响应时，不自动再发一次。
- 消费不放在第一阶段的发送闭环里。模型见第 7 节：Go 作为消费组里的唯一客户端，单独一个 PHP 进程拉取并确认。

`Server::addProcess()` 成功时返回自定义进程的 worker id（整数），失败返回 `false`。判断必须是 `=== false`。worker id 为 `0` 时也是成功。

---

## 2. 源码里的启动顺序

协议 Server 都是先把 Swoole Server 放进 `BaseServer::$server`，再 `parent::__construct()`：

| 类 | 赋值 |
| --- | --- |
| `src/Http/HttpServer.php` | `self::$server = new \Swoole\Http\Server(...)` 之后 `parent::__construct()` |
| `src/Websocket/WebsocketServer.php` | 同样，类型是 `\Swoole\WebSocket\Server` |
| `src/Tcp/TcpServer.php`、`src/Mqtt/MqttServer.php`、`src/Udp/UdpServer.php` | 同样，类型是 `\Swoole\Server` |

`BaseServer::__construct()` 里调用 `eventHandler()->init()`。`EventCtrl::init()` 在 `static::onInit()` 之后，对非 WorkerService 再 `ProcessManager::addProcess()`。`HttpServer::start()` 还没执行。因此 `init()` 结束前调用 `addProcess()`，满足 Swoole「必须在 `start()` 之前注册」的要求。

`Swfy::setSwooleServer()` 要到 `BaseServer::workerStartInit()` 才执行。`init()` 期间 `Swfy::$server` 还是空的。`ServiceTrait::getServer()` 会退回 `BaseServer::getServer()`，所以 `AbstractProcess` 在 `init()` 里 `Swfy::getServer()->addProcess()` 今天能工作。Sidecar 不要读 `Swfy::$server` 这个属性，只使用 `BaseServer::getServer()`。

`ProcessManager::addProcess()` 只 `new` 一个 `AbstractProcess` 子类。构造函数里固定 `enableCoroutine = true`（非 WorkerService），并把自己的 PHP 回调交给 `Swoole\Process`。这条路径不能拿来 `exec` 外部二进制。

daemon、cron、script 的业务进程仍由 `EventCtrl::boostrapWorkerInit()` 和 `MainManager` 拉起。Sidecar 不插入这条链，但这些服务同样在 `init()` 末尾调用 `attach()`，用 `BaseServer::getServer()->addProcess()` 拉起外部二进制。配置里启用了 Sidecar 就注册，不因服务类型跳过或失败。

自定义进程不跟随 `reload()` 重启。升级外部二进制必须重启 Server。Swoole 在 Server 停止时向自定义进程发 `SIGTERM`；进程自己退出后，Manager 会再拉起。外部程序因此不能因为下游短暂不可用就退出，否则会变成重启风暴。

---

## 3. 模块边界

新增 `src/Core/Sidecar/`。它只管理本机外部进程和到该进程的 UDS 调用。它不知道 RocketMQ，也不内嵌 Go/Python 代码。

```text
src/Core/Sidecar/
  SidecarSpec.php            一份外部进程声明
  SidecarRegistry.php        启动前收集声明
  SidecarSupervisor.php      在 EventCtrl::init() 末尾注册到当前 Swoole Server
  SocketEndpoint.php         按实例隔离路径，并检查残留文件
  FrameCodec.php             4 字节大端长度 + 完整读写
  Dto/SidecarRequestDto.php  请求信封
  Dto/SidecarResponseDto.php 响应信封
  SidecarClient.php          Worker 协程内一请求一连接
  SidecarException.php
  OutcomeUnknownException.php
```

Sidecar 模块里的失败统一抛 `SidecarException`，不再抛 `Swoolefy\Exception\SystemException`。启动校验、路径检查、配置编码、未注册、连接失败和非 `OK` 的业务 code 都用它。`OutcomeUnknownException` 继承 `SidecarException`，表示请求字节已经写出、结果不能重试。

```php
namespace Swoolefy\Core\Sidecar;

class SidecarException extends \Swoolefy\Exception\SystemException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly string $sidecarCode = '',
    ) {
        parent::__construct($message, $code, $previous);
    }
}

class OutcomeUnknownException extends SidecarException
{
}
```

`sidecarCode` 保存信封里的 `code`，例如 `UNAVAILABLE`。配置和启动失败留空。它继承 `SystemException` 只为了沿用现有异常输出，调用方按 `SidecarException` 捕获。

第一个外部进程仍然是独立的 Go 工程（先 `health.check` 与 `rocketmq.producer.send`，消费见第 7 节）。Python 或其他二进制以后只增加一条 `SidecarSpec` 和自己的方法名，不改 Supervisor。

```text
Swoole Server（HTTP / WebSocket / TCP / UDP / MQTT）
  Master / Manager
    ├─ 现有 AbstractProcess（collector、reload、Nacos）
    └─ Sidecar 自定义进程：Process 回调里 exec 外部二进制
         UDS 监听
         帧解码、并发上限、请求超时
         业务 SDK（仅存在于该二进制内）

PHP Worker 协程
  SidecarClient::call(name, method, payloadDto, timeoutMs)
    payloadDto->toArray() 放进请求信封
    信封 toArray() 后再 json_encode
    连接该 Sidecar 的 UDS，发一帧，收一帧，fromArray 成响应 DTO，关闭
```

---

## 4. 进程注册

### 4.1 谁来注册

`EventCtrl::init()` 在 HTTP 的内置进程注册和 daemon / cron / script 的 `boostrapWorkerInit()` 都结束之后、`static::printStartInfo()` 之前调用。这里不包在 `if (!SystemEnv::isWorkerService())` 里：

```php
\Swoolefy\Core\Sidecar\SidecarSupervisor::attach();
```

`attach()` 自己调用 `BaseServer::getServer()`，不接收外部传入的 Server，避免调用点再去读 `Swfy::$server`。

应用如果要在代码里追加 Sidecar，只在自己的 `onInit()` 里 `SidecarRegistry::add($spec)`。`onInit()` 跑完框架再 `attach()`。应用不必继承一个专门的 `SidecarEventHandler`，也不改 `event_handler` 才能用这项能力。

`attach()` 的规则：

- 没有启用任何 Sidecar：直接返回。HTTP、daemon、cron、script 都是这条规则。
- `BaseServer::getServer()` 不是 `\Swoole\Server`：失败。
- 二进制不存在或不可执行、socket 路径不合法、`addProcess()` 返回 `false`：失败并中止启动。

### 4.2 一条 Spec

`SidecarSpec` 在 `fromRow()` 返回时，路径和数值都已经校验过。后面的 `exec` 不再做第二次猜测。

```php
namespace Swoolefy\Core\Sidecar;

final class SidecarSpec
{
    /**
     * @param list<string> $args
     * @param array<string, string> $environment 已是 SIDECAR_* => 非空字符串
     */
    public function __construct(
        public readonly string $name,
        public readonly string $binary,
        public readonly array $args,
        public readonly string $socketPath,
        public readonly int $maxFrameBytes,
        public readonly int $defaultTimeoutMs,
        public readonly int $maxTimeoutMs,
        public readonly int $maxInflight,
        public readonly int $shutdownGraceMs,
        public readonly string $plugins,
        public readonly array $environment,
    ) {}
}
```

同名注册失败。`sidecar_name` 只允许 `[a-z0-9][a-z0-9_-]{0,31}`，避免把路径拼进目录时被 `..` 或分隔符穿透。

PHP 配置键与 Go 环境变量一一对应：键去掉前缀 `sidecar_`，其余转成大写，再加前缀 `SIDECAR_`。

| PHP 配置键 | Go 环境变量 | 说明 |
| --- | --- | --- |
| `sidecar_name` | `SIDECAR_NAME` | 进程名，写入日志，并参与默认 socket 文件名 |
| `sidecar_enable` | `SIDECAR_ENABLE` | 是否拉起。启用时 PHP 写成 `1` |
| `sidecar_exec_binary` | `SIDECAR_EXEC_BINARY` | 外部二进制的绝对路径，由 PHP `exec` |
| `sidecar_args` | `SIDECAR_ARGS` | `exec` 参数的 JSON 数组。`exec` 仍使用原来的字符串列表 |
| `sidecar_socket_path` | `SIDECAR_SOCKET_PATH` | UDS 绝对路径。配置为空时由 PHP 解析后再写入 |
| `sidecar_max_frame_bytes` | `SIDECAR_MAX_FRAME_BYTES` | 单帧最大字节数，默认 `16777216` |
| `sidecar_default_timeout_ms` | `SIDECAR_DEFAULT_TIMEOUT_MS` | 调用未传超时时间时使用的默认值，默认 `3000` |
| `sidecar_max_timeout_ms` | `SIDECAR_MAX_TIMEOUT_MS` | 单次调用超时上限，默认 `30000`。Go 把信封里的 `timeoutMs` 截断到这个值 |
| `sidecar_max_inflight` | `SIDECAR_MAX_INFLIGHT` | 同时处理的连接数上限，默认 `128`。超过返回 `OVERLOADED` |
| `sidecar_shutdown_grace_ms` | `SIDECAR_SHUTDOWN_GRACE_MS` | 收到 `SIGTERM` 后等待在途请求结束的毫秒数，默认 `5000` |
| `sidecar_plugins` | `SIDECAR_PLUGINS` | 逗号分隔的插件名，例如 `rocketmq` |
| `sidecar_rocketmq_proxy_endpoint` | `SIDECAR_ROCKETMQ_PROXY_ENDPOINT` | RocketMQ Proxy 的 gRPC 地址，格式 `host:port`。值来自 `APP_PATH/.env` 的同名大写变量 |
| `sidecar_rocketmq_access_key` | `SIDECAR_ROCKETMQ_ACCESS_KEY` | Proxy 访问密钥。值来自 `APP_PATH/.env` 的同名大写变量。空表示不校验。不写入日志和 `health.check` |
| `sidecar_rocketmq_secret_key` | `SIDECAR_ROCKETMQ_SECRET_KEY` | Proxy 密钥。值来自 `APP_PATH/.env` 的同名大写变量。空表示不校验。不写入日志和 `health.check` |
| `sidecar_rocketmq_allowed_topics` | `SIDECAR_ROCKETMQ_ALLOWED_TOPICS` | 允许发送的 Topic，逗号分隔。值为 `*` 时允许所有 Topic。空列表拒绝一切发送 |

`sidecar_rocketmq_proxy_endpoint`、`sidecar_rocketmq_access_key`、`sidecar_rocketmq_secret_key` 不在协议配置里写死。它们通过 `env()` 读取 `APP_PATH/.env`，`.env` 里使用大写变量名，与 Go 环境变量同名。地址格式是 `host:port`，例如 `127.0.0.1:8081`。不再使用 `ROCKETMQ_PROXY_ENDPOINT` 或 `SWOOLEFY_SIDECAR_*`。

应用配置 `Config/sidecar.php`（create 时从 `src/Stubs/sidecar.conf.stub.php` 复制）。协议 `conf.php` 通过 `SidecarConfig::fromApp()` 并入，与 `event_handler` 同级。文件不存在时这三段为空，不拉起进程：

```php
'sidecars' => [
    [
        'sidecar_name' => 'rocketmq',
        'sidecar_enable' => false,
        'sidecar_exec_binary' => '/opt/swoolefy/bin/rocketmq-sidecar',
        'sidecar_args' => [],
        'sidecar_socket_path' => '',
        'sidecar_max_frame_bytes' => 16777216,
        'sidecar_default_timeout_ms' => 3000,
        'sidecar_max_timeout_ms' => 30000,
        'sidecar_max_inflight' => 128,
        'sidecar_shutdown_grace_ms' => 5000,
        'sidecar_plugins' => 'rocketmq',
        'sidecar_rocketmq_proxy_endpoint' => env('SIDECAR_ROCKETMQ_PROXY_ENDPOINT', ''),
        'sidecar_rocketmq_access_key' => env('SIDECAR_ROCKETMQ_ACCESS_KEY', ''),
        'sidecar_rocketmq_secret_key' => env('SIDECAR_ROCKETMQ_SECRET_KEY', ''),
        'sidecar_rocketmq_allowed_topics' => '',
    ],
],
```

`sidecar_enable` 不为真的项不注册。`attach()` 把上表每一项写成对应的 `SIDECAR_*`。值为空字符串时不 `putenv`。

`.env` 由 `SystemEnv::getEnv()` 读进 Dotenv 仓库，不会自动进入子进程。这三个值先由 `env()` 填进配置，再由 `attach()` 写给 Go。`APP_PATH/.env`：

```dotenv
SIDECAR_ROCKETMQ_PROXY_ENDPOINT=127.0.0.1:8081
SIDECAR_ROCKETMQ_ACCESS_KEY=
SIDECAR_ROCKETMQ_SECRET_KEY=
```

`sidecar_runtime_dir` 与 `sidecars` 同级，不放进每一条 sidecar，也不导出给 Go。缺省为 `APP_PATH . '/runtime'`。解析完成后 Go 只读 `SIDECAR_SOCKET_PATH`。

```php
return [
    'event_handler' => \Swoolefy\Core\EventHandler::class,
    'port' => 9501,
    ...\Swoolefy\Core\Sidecar\SidecarConfig::fromApp(),
];
```

`SidecarSupervisor::attach()` 从 `BaseServer::getConf()` 读取并入后的 `sidecar_runtime_dir` 与 `sidecars`。应用在 `onInit()` 里 `SidecarRegistry::add()` 的 Spec 与配置里的名字冲突时，启动失败。

### 4.2.1 校验

`sidecar_enable` 用 `filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)`。`null` 直接失败，不把字符串 `"false"` 当成启用。不为真的项跳过，不检查它的二进制。

启用项按下面的范围检查，越界抛 `SidecarException`，带上 `sidecar_name` 和键名：

| 键 | 规则 |
| --- | --- |
| `sidecar_name` | 正则 `^[a-z0-9][a-z0-9_-]{0,31}$`，注册表内唯一 |
| `sidecar_exec_binary` | 绝对路径，`is_file` 且 `is_executable` |
| `sidecar_args` | `list<string>`。空数组允许。元素不能含 `\0` |
| `sidecar_socket_path` | 空字符串走默认路径；否则必须是绝对路径，不能含 `\0` |
| `sidecar_max_frame_bytes` | 整数，`1024` 到 `16777216` |
| `sidecar_default_timeout_ms` | 整数，`1` 到 `sidecar_max_timeout_ms` |
| `sidecar_max_timeout_ms` | 整数，`1` 到 `300000` |
| `sidecar_max_inflight` | 整数，`1` 到 `1024` |
| `sidecar_shutdown_grace_ms` | 整数，`0` 到 `30000` |
| `sidecar_plugins` | 非空，正则 `^[a-z0-9]+(,[a-z0-9]+)*$`，拆开后不能有重复项 |
| `sidecar_rocketmq_proxy_endpoint` | `env('SIDECAR_ROCKETMQ_PROXY_ENDPOINT', '')`。`sidecar_plugins` 含 `rocketmq` 时必填，形如 `host:port`，不能带 `http://` 或 `/` |
| `sidecar_rocketmq_allowed_topics` | 可空。整段 trim 后等于 `*` 表示允许所有 Topic。其他非空值按逗号拆开，每段 trim，不能有空段，也不能再出现 `*` |
| `sidecar_rocketmq_access_key` / `sidecar_rocketmq_secret_key` | `env('SIDECAR_ROCKETMQ_ACCESS_KEY', '')` 与 `env('SIDECAR_ROCKETMQ_SECRET_KEY', '')`。字符串。空字符串表示 Proxy 不校验 |

未知的 `sidecar_*` 键启动失败，避免拼写错误被静默丢掉。缺键时使用第 4.2 节示例里的默认值，密钥和地址没有默认值。

### 4.2.2 写成环境变量

```php
private const SKIP_ENV = [];

/** @return array<string, string> */
public function environment(): array
{
    return $this->environment;
}

public static function encodeRow(array $row, string $resolvedSocketPath): array
{
    $row['sidecar_socket_path'] = $resolvedSocketPath;
    $env = [];
    foreach ($row as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'sidecar_')) {
            throw new SidecarException('sidecar config key must start with sidecar_');
        }
        if (in_array($key, self::SKIP_ENV, true)) {
            continue;
        }
        $encoded = self::encodeEnvValue($key, $value);
        if ($encoded === '') {
            continue;
        }
        $env['SIDECAR_' . strtoupper(substr($key, strlen('sidecar_')))] = $encoded;
    }
    return $env;
}

private static function encodeEnvValue(string $key, mixed $value): string
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_string($value)) {
        return $value;
    }
    if ($key === 'sidecar_args' && is_array($value)) {
        return json_encode(array_values($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    throw new SidecarException("unsupported sidecar value type: {$key}");
}
```

`sidecar_args` 的 JSON 只放进 `SIDECAR_ARGS`。`Process::exec` 使用原来的 `list<string>`，不把 JSON 再拆回去。`sidecar_enable=true` 写成 `SIDECAR_ENABLE=1`。空字符串不 `putenv`，所以部署环境里已有的 `SIDECAR_ROCKETMQ_SECRET_KEY` 会保留。

### 4.3 exec 进程

```text
$dir = socket 文件所在目录，attach() 时在父进程创建，0700
确认 socket 路径不是符号链接、不是普通文件、不是仍可连接的活跃监听
仅当 Lstat 表明是无人监听的 socket 残留时才 unlink

$process = new \Swoole\Process(function (Process $worker) use ($spec): void {
    foreach ($spec->environment() as $name => $value) {
        // sidecar_socket_path → SIDECAR_SOCKET_PATH，空值不覆盖已有环境变量
        if ($value === '') {
            continue;
        }
        putenv($name . '=' . $value);
    }
    if ($worker->exec($spec->binary, $spec->args) === false) {
        fwrite(STDERR, "sidecar exec failed: {$spec->name}\n");
        exit(127);
    }
}, false, 0, false);

$id = $server->addProcess($process);
if ($id === false) {
    throw new SidecarException(...);
}
```

`enable_coroutine` 必须为 `false`。这个进程马上被外部映像替换，不需要 Swoole 协程，也不创建给 PHP 用的 pipe（`pipe_type = 0`）。

父进程把解析后的绝对 socket 路径存进 `SidecarRegistry`，Worker 里的 Client 读同一份。不要求业务同时配置「Go 监听路径」和「PHP 连接路径」两个值。

### 4.4 Socket 路径

未配置 `sidecar_socket_path` 时：

```text
{runtime_dir}/sidecars/{app}-{port}-{name}.sock
```

`runtime_dir` 取配置 `sidecar_runtime_dir`，缺省为 `APP_PATH . '/runtime'`，不用所有实例共享的 `/tmp/*.sock`。目录 `0700`，socket 由外部进程创建后设为 `0600`。单机多实例靠 app 名和监听端口区分。

启动时删除文件的条件同时满足才可以 unlink：

- `lstat` 成功，且不是符号链接；
- 文件类型是 socket；
- 对该路径做一次非阻塞连接，确认没有进程在监听。

路径已存在但是普通文件、目录或符号链接：启动失败。连接成功说明有人在听：启动失败，不能删掉对方的 socket。

`attach()` 发生在 `start()` 之前，跑在 Master 进程里，没有协程。探测必须用阻塞的 `stream_socket_client`，不能用 `Swoole\Coroutine\Client`。

```php
final class SocketEndpoint
{
    public static function resolve(string $name, string $configuredPath, string $runtimeDir): string
    {
        if ($configuredPath !== '') {
            return $configuredPath;
        }
        $app = BaseServer::getAppName();
        $port = (int) (BaseServer::getConf()['port'] ?? 0);
        if ($app === '' || $port <= 0) {
            throw new SidecarException('cannot build sidecar socket path without app name and port');
        }
        return rtrim($runtimeDir, '/') . '/sidecars/' . $app . '-' . $port . '-' . $name . '.sock';
    }

    public static function prepare(string $path): void
    {
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new SidecarException('sidecar socket path must be an absolute path');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new SidecarException('cannot create sidecar socket directory');
        }
        chmod($dir, 0700);

        // 框架 error handler 不认 @。文件不存在是首次启动，不是错误。
        $stat = self::withoutWarning(static fn () => lstat($path));
        if (!is_array($stat)) {
            return;
        }
        $type = $stat['mode'] & 0170000;
        $isSocket = $type === 0140000;
        if ($type === 0120000 || !$isSocket) {
            throw new SidecarException('sidecar socket path exists and is not a stale socket: ' . $path);
        }
        $errno = 0;
        $errstr = '';
        $fp = self::withoutWarning(static function () use ($path, &$errno, &$errstr) {
            return stream_socket_client('unix://' . $path, $errno, $errstr, 0.2);
        });
        if (is_resource($fp)) {
            fclose($fp);
            throw new SidecarException('sidecar socket is already listening: ' . $path);
        }
        if (self::withoutWarning(static fn () => unlink($path)) !== true) {
            throw new SidecarException('cannot unlink stale sidecar socket: ' . $path);
        }
    }
}
```

`0140000` 是 socket，`0120000` 是符号链接。先 `lstat`，不要用 `file_exists` 或 `is_link`，后者会跟随链接。

---

## 5. 调用协议

线上只有 JSON。不引入 `google/protobuf`，不生成 `.proto`，也不在 `payload` 里夹带 protobuf 字节。`composer.json` 不加这项依赖。Go / Python 使用与 DTO 属性同名的 JSON 字段。

PHP 侧信封和业务体都是 `Swoolefy\Core\Dto\AbstractDto` 子类。内存里传递 DTO，写 socket 之前才变成 JSON：

```text
payloadDto->toArray()
  → 填进 SidecarRequestDto->payload
  → json_encode(SidecarRequestDto->toArray())
  → 长度前缀帧

收到的 JSON
  → json_decode 成数组
  → SidecarResponseDto::fromArray()
  → 业务方再 fromArray 到自己的结果 DTO
```

用 `toArray()`，不用 `json_encode($dto)`，也不用 `toDeepArray()`。`ArrayDto::toArray()` 是浅转换，嵌套 DTO 不会自己变成数组。所以业务 payload 必须先 `toArray()`，再赋给信封的 `payload`。信封上的 `payload` 类型是 `array`。

### 5.1 帧

```text
[uint32 big-endian body length][JSON object bytes]
```

长度在 `[1, maxFrameBytes]` 内才分配 body。`0`、超限、半帧、JSON 无法解码、解码结果不是对象：关掉当前连接。PHP 和外部进程都循环写满、读满，不能假设一次 `send` / `Write` 完成。

`json_encode` / `json_decode` 使用 `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`。`json_decode` 的深度参数用 `512`。禁止 `JSON_PARTIAL_OUTPUT_ON_ERROR`。

```php
final class FrameCodec
{
    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function encode(string $json, int $maxFrameBytes): string
    {
        $length = strlen($json);
        if ($length < 1 || $length > $maxFrameBytes) {
            throw new SidecarException('frame length out of range');
        }
        return pack('N', $length) . $json;
    }

    public static function writeAll(\Swoole\Coroutine\Client $client, string $bytes, float $deadline, bool &$wrote): void
    {
        $offset = 0;
        $total = strlen($bytes);
        while ($offset < $total) {
            $remain = $deadline - microtime(true);
            if ($remain <= 0) {
                if ($wrote) {
                    throw new OutcomeUnknownException('deadline exceeded');
                }
                throw new SidecarException('deadline exceeded before any byte');
            }
            $client->set(['timeout' => $remain]);
            $n = $client->send(substr($bytes, $offset));
            if (!is_int($n) || $n <= 0) {
                if ($wrote) {
                    throw new OutcomeUnknownException('short write');
                }
                throw new SidecarException('send failed before any byte');
            }
            $wrote = true;
            $offset += $n;
        }
    }

    public static function readFrame(\Swoole\Coroutine\Client $client, int $maxFrameBytes, float $deadline): string
    {
        $header = self::readExact($client, 4, $deadline);
        $unpacked = unpack('Nlen', $header);
        $length = (int) ($unpacked['len'] ?? 0);
        if ($length < 1 || $length > $maxFrameBytes) {
            throw new OutcomeUnknownException('response frame length out of range');
        }
        return self::readExact($client, $length, $deadline);
    }

    private static function readExact(\Swoole\Coroutine\Client $client, int $want, float $deadline): string
    {
        $buf = '';
        while (strlen($buf) < $want) {
            self::applyTimeout($client, $deadline);
            $chunk = $client->recv($want - strlen($buf));
            if (!is_string($chunk) || $chunk === '') {
                throw new OutcomeUnknownException('short read');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private static function applyTimeout(\Swoole\Coroutine\Client $client, float $deadline): void
    {
        $remain = $deadline - microtime(true);
        if ($remain <= 0) {
            throw new OutcomeUnknownException('deadline exceeded');
        }
        $client->set(['timeout' => $remain]);
    }
}
```

读长度之前已经写过请求，所以读失败一律是 `OutcomeUnknownException`。不把「UDS 没有单包限制」或固定的 QPS 提升写成承诺。

### 5.2 信封 DTO

Sidecar 的全部 DTO（信封和 RocketMQ 业务体）遵守同一约定：

- 属性使用驼峰，例如 `requestId`、`timeoutMs`，不用 `request_id`。
- 属性为 `protected`。业务代码不直接读写属性。
- 每个属性都有 setter 和 getter：`setRequestId()` / `getRequestId()`。
- `toArray()` 的键就是驼峰属性名，JSON 与之相同。Go / Python 按驼峰字段解析。
- `fromArray()` 也按驼峰键填回属性。

```php
namespace Swoolefy\Core\Sidecar\Dto;

use Swoolefy\Core\Dto\AbstractDto;

final class SidecarRequestDto extends AbstractDto
{
    protected string $requestId = '';

    protected string $method = '';

    /** @var array<string, mixed> */
    protected array $payload = [];

    protected int $timeoutMs = 0;

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setTimeoutMs(int $timeoutMs): void
    {
        $this->timeoutMs = $timeoutMs;
    }

    public function getTimeoutMs(): int
    {
        return $this->timeoutMs;
    }
}
```

`SidecarResponseDto` 同样是 `protected` 驼峰属性加 setter/getter：`requestId`、`code`、`message`、`payload`。

写出的 JSON 与 `toArray()` 的键一致：

```json
{
  "requestId": "hex",
  "method": "health.check",
  "payload": {},
  "timeoutMs": 3000
}
```

```json
{
  "requestId": "hex",
  "code": "OK",
  "message": "",
  "payload": {}
}
```

`requestId` 使用 `random_bytes(16)` 的 hex。响应的 `getRequestId()` 必须与请求相同，否则 Client 丢弃并报错。

`code` 只用这些字符串：`OK`、`INVALID_ARGUMENT`、`METHOD_NOT_FOUND`、`UNAVAILABLE`、`OVERLOADED`、`TIMEOUT`、`INTERNAL`、`OUTCOME_UNKNOWN`。不要再包一层数字 status 和字符串 error，避免两层结果不一致。

`timeoutMs` 由 Client 通过 `setTimeoutMs()` 写入 `SidecarRequestDto`。外部进程把它截断到自己的上限（Spec 的 `maxTimeoutMs`，默认 30 秒），并用这个截止时间取消本次处理。

外部进程限制同时处理的请求数（`SIDECAR_MAX_INFLIGHT`，默认 128）。超过则返回 `OVERLOADED`，不为每个 Accept 无上限起 goroutine / 线程。第一阶段 PHP 一条连接只发一个请求，外部进程不必做多路复用。

### 5.3 Client

`SidecarClient::call(string $name, string $method, AbstractDto $payload, int $timeoutMs): SidecarResponseDto` 只在协程里调用。`Coroutine::getCid() <= 0` 时抛 `SidecarException`。

```php
public function call(string $name, string $method, AbstractDto $payload, int $timeoutMs): SidecarResponseDto
{
    $spec = SidecarRegistry::get($name);
    if ($timeoutMs <= 0) {
        $timeoutMs = $spec->defaultTimeoutMs;
    }
    if ($timeoutMs > $spec->maxTimeoutMs) {
        $timeoutMs = $spec->maxTimeoutMs;
    }
    $deadline = microtime(true) + ($timeoutMs / 1000);

    $request = new SidecarRequestDto();
    $request->setRequestId(bin2hex(random_bytes(16)));
    $request->setMethod($method);
    $request->setPayload($payload->toArray());
    $request->setTimeoutMs($timeoutMs);

    $json = json_encode($request->toArray(), FrameCodec::JSON_FLAGS);
    $frame = FrameCodec::encode($json, $spec->maxFrameBytes);

    $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_UNIX_STREAM);
    $wrote = false;
    try {
        $remain = $deadline - microtime(true);
        if ($remain <= 0 || !$client->connect($spec->socketPath, 0, $remain)) {
            throw new SidecarException('connect failed: ' . $name);
        }
        FrameCodec::writeAll($client, $frame, $deadline, $wrote);
        $raw = FrameCodec::readFrame($client, $spec->maxFrameBytes, $deadline);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new OutcomeUnknownException('response is not a json object');
        }
        $response = SidecarResponseDto::fromArray($data);
        if ($response->getRequestId() !== $request->getRequestId()) {
            throw new OutcomeUnknownException('requestId mismatch');
        }
        return $response;
    } catch (OutcomeUnknownException $e) {
        throw $e;
    } catch (\Throwable $e) {
        if ($wrote) {
            throw new OutcomeUnknownException($e->getMessage(), 0, $e);
        }
        if ($e instanceof SidecarException) {
            throw $e;
        }
        throw new SidecarException($e->getMessage(), 0, $e);
    } finally {
        $client->close();
    }
}
```

`call()` 对格式正确的响应原样返回，包括 `code` 不是 `OK` 的情况。传输失败才抛异常。业务包装层调用 `assertOk()`：

| `code` | `assertOk()` |
| --- | --- |
| `OK` | 返回 |
| `OUTCOME_UNKNOWN` | `OutcomeUnknownException` |
| 其余合法 code | `SidecarException`，异常上保留 `code` 和 `message` |
| 空 code 或不在第 5.2 节列表里 | `SidecarException` |

连接还没建立、或一个字节都没写出：`SidecarException`，调用方可以自己重试。第一个字节写出之后的超时、断连、`requestId` 不一致都是 `OutcomeUnknownException`，Client 不自动再发。`requestId` 只用于对上这一次响应和日志，不是幂等键。

总 deadline 盖住连接、发送和接收。每次 `send` / `recv` 前用剩余时间 `set(['timeout' => $remain])`，单位是秒。不能只给第一次 `recv` 设超时。

Registry 未找到名字时抛 `SidecarException`，不尝试连接。不把 Client 留在单例、静态属性或协程上下文里。

---

## 6. 第一个外部进程：RocketMQ Producer

Go 工程独立于 `src/Core/Sidecar`。公共运行时见 [Swoolefy-Go-Sidecar-Framework.md](Swoolefy-Go-Sidecar-Framework.md)。RocketMQ 只是该框架的第一个插件，进程内用 RocketMQ 5.x Go SDK 连接 Proxy 的 gRPC 地址，不把 Broker 直连地址当成 Proxy。

### 6.1 方法

业务方法带业务前缀 `rocketmq.`。`health.check` 属于框架，不加前缀。业务体同样是 DTO。`call()` 收到的 `SidecarResponseDto->payload` 再用对应结果类的 `fromArray()` 还原。

| method | 请求 DTO | 成功时 payload 还原为 |
| --- | --- | --- |
| `health.check` | `HealthCheckRequestDto`（无字段） | `HealthCheckResponseDto` |
| `rocketmq.producer.send` | `SendMessageRequestDto` | `SendMessageResponseDto` |

```php
final class SendMessageRequestDto extends AbstractDto
{
    protected string $topic = '';

    protected string $bodyBase64 = '';

    protected string $tag = '';

    protected string $key = '';

    protected int $delayMs = 0;

    // 每个属性都有 setTopic/getTopic 这种 setter、getter，此处不逐个展开
}

final class SendMessageResponseDto extends AbstractDto
{
    protected string $messageId = '';
}
```

`HealthCheckResponseDto` 放在 `src/Support/Rocketmq/Dto/`，不放进 `Core/Sidecar`。属性是 `protected`，并各有 setter、getter。

| 属性 | 类型 | 出现条件 |
| --- | --- | --- |
| `version` | string | 总是 |
| `uptimeMs` | int | 总是，进程启动后的毫秒 |
| `listenerOk` | bool | 总是，监听 socket 仍在 |
| `inflight` | int | 总是，当前在途连接数 |
| `producerReady` | bool | 注册了 `rocketmq.producer.send` 才出现 |
| `consumerReady` | bool | 注册了 `rocketmq.consumer.receive` 才出现 |
| `sendOk` | int | 同上，Producer 发送得到 SDK 成功 |
| `sendFail` | int | 同上，SDK 明确失败 |
| `sendTimeout` | int | 同上，发送 ctx 超时 |

未出现的字段不要写成 `false` 或 `0`。`fromArray()` 只填 JSON 里有的键，缺键保持属性未初始化；getter 在未初始化时抛 `SidecarException`，调用方先 `isset` 风格地判断，或让 DTO 提供 `hasProducerReady(): bool`。不要把「字段不存在」读成「未就绪」。

`health.check` 只说明这个进程的监听和本地客户端是否 `Start` 成功，不表示 RocketMQ 集群健康。

`delayMs` 是相对毫秒。`0` 表示立即投递。PHP 传相对毫秒，不传绝对时间戳。Go 用 `time.Now().Add(time.Duration(delayMs) * time.Millisecond)`，不能把 Unix 毫秒再 `Add` 一次。

第一阶段不提供 `properties`。SDK 映射没写完就不出现在 DTO 里。

### 6.2 Go 进程约束

- 监听地址只读 `SIDECAR_SOCKET_PATH`。启动时按第 4.4 节的规则处理残留文件，权限 `0600`。
- AccessKey / SecretKey 只来自 `SIDECAR_ROCKETMQ_ACCESS_KEY` / `SIDECAR_ROCKETMQ_SECRET_KEY`，不进日志、健康响应和 payload。
- Proxy 暂时不可用：进程保持监听，`producerReady=false`，`rocketmq.producer.send` 返回 `UNAVAILABLE`，并用有上限的退避重连。不要退出触发 Swoole 立刻再 `exec`。
- `SIDECAR_ROCKETMQ_ALLOWED_TOPICS` 为 `*` 时允许任何非空 Topic。否则 Topic 必须与列表中的一项完全相同，空列表或未命中都返回 `INVALID_ARGUMENT`。
- `Stop()`：停止 Accept，取消在途请求，在 `SIDECAR_SHUTDOWN_GRACE_MS` 内等处理结束，关闭 Producer，再删除自己创建且仍然是 socket 的那个路径。
- SDK 版本写进 `go.mod` / `go.sum`，以集成测试通过的那一版为准。不在方案里把某个仍标记为 Pre-release 的 tag 写成生产版本。

PHP 侧的发送入口放在 `src/Support/Rocketmq/RocketmqProducer.php`，不放进 `Core/Sidecar`。

```php
namespace Swoolefy\Support\Rocketmq;

final class RocketmqProducer
{
    public function __construct(private readonly \Swoolefy\Core\Sidecar\SidecarClient $client) {}

    public function send(Dto\SendMessageRequestDto $request, int $timeoutMs = 0): Dto\SendMessageResponseDto
    {
        $response = $this->client->call('rocketmq', 'rocketmq.producer.send', $request, $timeoutMs);
        $this->client->assertOk($response);
        return Dto\SendMessageResponseDto::fromArray($response->getPayload());
    }
}
```

`bodyBase64` 由调用方用 `base64_encode` 填好。`send()` 不捕获 `OutcomeUnknownException`，调用方看到它就按「可能已经发出」处理，不能再调一次 `send()`。

---

## 7. RocketMQ 消费

同一个 Swoole 实例里，同一个消费组和 Topic 只注册一个 PHP 消费进程。这个进程循环拉取、处理、确认。Go 不做消费循环，也不保存消息。一次 IPC 对应一次 RocketMQ 调用。没有中间缓冲区。

HTTP Worker 不参与这个循环。

消息很多、单个进程按顺序处理不过来时，不在这个实例里再注册第二个消费进程。本进程把同一批消息用协程并发处理，并调大 `maxNum`。仍然不够时，增加使用同一消费组的 Swoole 实例，由 RocketMQ 把队列分给各个实例。

Topic、消费组、Tag 不写进 `'sidecars'`，也不导出成 `SIDECAR_*`。它们是消费进程的业务参数，每次 `rocketmq.consumer.receive` 和 `rocketmq.consumer.ack` 放进 payload 传给 Go。

```text
每个消费组 + Topic 一个 PHP AbstractProcess
  while (running) {
    rocketmq.consumer.receive(topic, consumerGroup, consumerTag, maxNum, invisibleMs)
    无消息 → 下一轮
    有消息 → 最多 concurrency 个协程同时处理
             成功 → rocketmq.consumer.ack(consumerGroup, topic, receiptHandle, messageId)
             失败 → 不 ack
    这一批全部结束后才进入下一轮 receive
  }
        │  UDS（第 4.4 节的实例路径，不使用固定的 /tmp/*.sock）
        ▼
Go sidecar（无消费 for、无消息缓存）
  按 consumerGroup 取得或创建 SimpleConsumer
  按 topic + consumerTag 订阅一次
  receive：client.Receive(ctx, maxNum, invisible)
  ack：    用同一消费组的客户端确认，不查找本地消息表
        │  gRPC
        ▼
RocketMQ 5.x Proxy
```

### 7.1 条数和时长由 PHP 传入

Go 不写死拉取条数，也不写死 `20 * time.Second`。PHP 每次 `rocketmq.consumer.receive` 带上这两个数，Go 原样用于这一次调用：

```go
// maxNum、invisible 来自本次请求，例如 PHP 传入 2 和 20000 毫秒
mvs, err := client.Receive(ctx, maxNum, invisible)
```

`Receive` 的第三个参数是不可见时间：这段时间内消息不会再被别人取走，应大于 PHP 随后的业务处理时间。它不是另外一个固定的长轮询配置。没有消息时，这次调用阻塞到 `ctx` 结束；`ctx` 的截止时间用信封里的 `timeoutMs`，同样由 PHP 传入。`timeoutMs` 应大于本次希望等待的时间，否则 PHP 会先断开。

无消息时 Go 返回 `code=OK` 且 `messages=[]`，不要当成错误。PHP 看到空列表就进入下一轮。

Go 只拒绝明显非法的值：`maxNum <= 0`、`invisibleMs <= 0`，或超过 Spec 上的上限（默认最多 32 条、不可见时间最多 5 分钟）。上限之内不改写成 1 或 30 秒。

### 7.2 PHP 循环

用 `ProcessManager::addProcess()` 注册 `AbstractProcess`。一个配置项一个进程。同一 Swoole 实例里，同一个消费组和 Topic 只能有一项。循环放在 `run()` 里。`AbstractProcess::__start()` 会在协程里调用 `run()`，所以这里可以 `SidecarClient::call()`。不要在每个 HTTP Worker 里各放一个循环。这个进程不 `exec` 成 Go，也不挂进 `MainManager`。

消费配置与 `'sidecars'` 同级，rocketmq Sidecar 已启用时才注册。Sidecar 未启用但本段非空：抛 `SidecarException`。HTTP、daemon、cron、script 都注册。`addProcess()` 使用默认的 `enableCoroutine = true`。

```php
'rocketmq_consumers' => [
    [
        'sidecar_rocketmq_consume_topics' => 'order',
        'sidecar_rocketmq_consumer_group' => 'order-consumer',
        'sidecar_rocketmq_consumer_tag' => '*',
        'sidecar_rocketmq_consumer_concurrency' => 8,
    ],
],
```

| 键 | 规则 |
| --- | --- |
| `sidecar_rocketmq_consume_topics` | 单个 Topic，非空，正则 `^[A-Za-z0-9_-]{1,64}$`。不按逗号拆，一项里不能写多个 Topic |
| `sidecar_rocketmq_consumer_group` | 非空，同一正则。同一 Swoole 实例里 `消费组 + Topic` 不能重复 |
| `sidecar_rocketmq_consumer_tag` | 订阅过滤。`*` 或空字符串表示该 Topic 的全部 Tag。其他值原样交给 SDK 的 Tag 表达式，例如 `paid` 或 `paid \|\| cancel` |
| `sidecar_rocketmq_consumer_concurrency` | 这个进程里同时处理消息的协程数，整数 `1` 到 `32`，缺省 `1`。只在 PHP 进程内使用，不传给 Go |

```php
foreach ($consumers as $row) {
    $topic = $row['sidecar_rocketmq_consume_topics'];
    $group = $row['sidecar_rocketmq_consumer_group'];
    ProcessManager::getInstance()->addProcess(
        'swoolefy_rocketmq_consumer_' . $group . '_' . $topic,
        \Swoolefy\Support\Rocketmq\RocketmqConsumerProcess::class,
        true,
        [],
        $row
    );
}
```

`AbstractProcess` 构造函数走 `Swfy::getServer()`。`init()` 期间 `Swfy::$server` 为空，`ServiceTrait::getServer()` 会退回 `BaseServer::getServer()`。daemon、cron、script 同样如此。

进程退出后由 Swoole Manager 重新拉起。未确认的消息靠不可见时间到期后重投，PHP 不在本地重放。

```php
namespace Swoolefy\Support\Rocketmq;

final class RocketmqConsumerProcess extends \Swoolefy\Core\Process\AbstractProcess
{
    private bool $running = true;

    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
    }

    public function run()
    {
        $cfg = $this->getExtendData();
        $topic = (string) $cfg['sidecar_rocketmq_consume_topics'];
        $group = (string) $cfg['sidecar_rocketmq_consumer_group'];
        $tag = (string) ($cfg['sidecar_rocketmq_consumer_tag'] ?? '*');
        $concurrency = (int) ($cfg['sidecar_rocketmq_consumer_concurrency'] ?? 1);
        if ($concurrency < 1) {
            $concurrency = 1;
        }
        if ($concurrency > 32) {
            $concurrency = 32;
        }
        $client = new \Swoolefy\Core\Sidecar\SidecarClient();
        $handler = new RocketmqMessageHandler();
        while ($this->running) {
            $req = new Dto\ReceiveMessageRequestDto();
            $req->setTopic($topic);
            $req->setConsumerGroup($group);
            $req->setConsumerTag($tag);
            $req->setMaxNum(2);
            $req->setInvisibleMs(20000);
            try {
                $response = $client->call('rocketmq', 'rocketmq.consumer.receive', $req, 25000);
                $client->assertOk($response);
            } catch (\Swoolefy\Core\Sidecar\SidecarException $e) {
                \Swoole\Coroutine::sleep(1);
                continue;
            }
            $body = Dto\ReceiveMessageResponseDto::fromArray($response->getPayload());
            $messages = $body->getMessages();
            if ($messages === []) {
                continue;
            }
            $slots = new \Swoole\Coroutine\Channel($concurrency);
            for ($i = 0; $i < $concurrency; $i++) {
                $slots->push(1);
            }
            $wg = new \Swoole\Coroutine\WaitGroup();
            foreach ($messages as $item) {
                if (!$this->running) {
                    break;
                }
                $slots->pop();
                $wg->add();
                go(function () use ($slots, $wg, $item, $client, $handler, $group): void {
                    try {
                        $message = Dto\ReceivedMessageDto::fromArray($item);
                        $handler->handle($message);
                        $ack = new Dto\AckMessageRequestDto();
                        $ack->setConsumerGroup($group);
                        $ack->setReceiptHandle($message->getReceiptHandle());
                        $ack->setMessageId($message->getMessageId());
                        $ack->setTopic($message->getTopic());
                        $client->assertOk($client->call('rocketmq', 'rocketmq.consumer.ack', $ack, 3000));
                    } catch (\Throwable) {
                        // 业务失败或 ack 失败都不确认。不可见时间结束后由 RocketMQ 再投
                    } finally {
                        $slots->push(1);
                        $wg->done();
                    }
                });
            }
            $wg->wait();
        }
    }
}
```

`RocketmqMessageHandler::handle(ReceivedMessageDto $message): void` 由应用注册。框架只提供空接口，默认实现抛 `SidecarException`，避免启用了消费却没有处理函数时把消息直接 ack 掉。处理函数自己按 `messageId` 做幂等。

`maxNum=2`、`invisibleMs=20000`、receive 的 `timeoutMs=25000`、ack 的 `timeoutMs=3000` 是这一版的默认值。消息量大时把 `maxNum` 提高到不超过 32，并把 `sidecar_rocketmq_consumer_concurrency` 提高到不超过 32。同一批里的消息并发处理，这一批的耗时接近最慢的那条，而不是每条相加。`invisibleMs` 要盖住最慢的那条处理时间。处理完这一批才进入下一次 `receive`，不把没处理完的消息放进队列。

单个实例把 `maxNum` 和并发都顶满后仍然积压：再加 Swoole 实例，使用同一个 `sidecar_rocketmq_consumer_group`。不要在本实例为同一消费组和 Topic 注册第二个 PHP 进程。

`SIGTERM` 时 `AbstractProcess` 会调用 `gracefulShutdownDrain()`。当前 `receive` 返回后不再调度新消息，已启动的协程结束后循环退出，不在退出过程中补 ack。

连接失败、`UNAVAILABLE`、`OVERLOADED`、`TIMEOUT` 都落在 `SidecarException`，退避 1 秒。空列表不 sleep。`OutcomeUnknownException` 同样不 ack。

### 7.3 Go 只做一次调用

Go 不在启动时订阅。`rocketmq.consumer.receive` 的 payload 带上 `topic`、`consumerGroup`、`consumerTag`。同一个 `consumerGroup` 共用一个 SimpleConsumer；这个客户端按 `topic` 和 `consumerTag` 做一次 `Subscribe`。同一个消费组的不同 Topic 可以并存。`consumerTag` 为 `*` 或空时使用 SDK 的订阅全部。已经用另一个 Tag 订阅过同一 Topic 时返回 `INVALID_ARGUMENT`，不在有在途拉取时改过滤条件。

多个 Swoole 实例使用同一个消费组时，队列由 RocketMQ 分给各个 Go 进程。同一个实例不为同一消费组和 Topic 再开一个 PHP 进程。

`rocketmq.consumer.receive` 处理函数：

```text
门闩键 = consumerGroup + topic
该键已有一个 Receive 还没返回 → 立刻 OVERLOADED，不排队
其他 Topic 的 Receive 不受这把门锁影响
按 consumerGroup 取得或创建 SimpleConsumer，必要时 Start
按 topic、consumerTag 确保已订阅
maxNum = 请求里的 maxNum
invisible = 请求里的 invisibleMs
ctx 截止时间 = 请求里的 timeoutMs
mvs, err = client.Receive(ctx, maxNum, invisible)
把 mvs 编码进响应
函数返回后不保留 mvs
```

这把锁只防止同一个消费组和 Topic 被两个循环同时拉取，不是消息缓冲。没有等待队列。同一批消息的并发发生在 PHP 进程里，不需要第二次 `receive`。

`rocketmq.consumer.ack` 用请求里的 `consumerGroup` 找到这个 SimpleConsumer，再用 `receiptHandle`、`messageId`、`topic` 向 Proxy 确认。Go 不按回执保存 body，也不保存 `MessageView` 映射。确认 RPC 如果只能吃 `Receive` 返回的原对象、不能用回执字段重建，这一版 SDK 不能按本方案接入，应换确认 API，而不是在 Go 里缓存消息。

请求被取消、连接断开、或 `Receive` 返回时 PHP 已经不在等：丢掉这次结果，不 `Ack`，不写入内存。不可见时间结束后消息会再次可被拉取。

业务失败时 PHP 不调用 `ack`，Go 也不自动确认。

某个消费组 `Start` 失败时，这个组的 `rocketmq.consumer.receive` 返回 `UNAVAILABLE` 并退避重试。其他消费组继续工作。进程保持监听，不要退出，否则 Swoole 会反复 `exec`。

`health.check` 在注册了消费方法后带上 `consumerReady`。它只表示消费插件已装上、Proxy 配置可用，不表示某个消费组已经订阅，也不表示位点或集群健康。

### 7.4 DTO

放在 `src/Support/Rocketmq/Dto/`。写 socket 前先 `toArray()`。

| method | 请求 DTO | 成功时 payload |
| --- | --- | --- |
| `rocketmq.consumer.receive` | `ReceiveMessageRequestDto` | `ReceiveMessageResponseDto` |
| `rocketmq.consumer.ack` | `AckMessageRequestDto` | `AckMessageResponseDto`（无字段） |

```php
final class ReceiveMessageRequestDto extends AbstractDto
{
    protected string $topic = '';

    protected string $consumerGroup = '';

    protected string $consumerTag = '*';

    protected int $maxNum = 2;

    protected int $invisibleMs = 20000;

    // setTopic/getTopic、setConsumerGroup/getConsumerGroup、setConsumerTag/getConsumerTag
    // setMaxNum/getMaxNum、setInvisibleMs/getInvisibleMs
}

final class ReceivedMessageDto extends AbstractDto
{
    protected string $messageId = '';

    protected string $topic = '';

    protected string $bodyBase64 = '';

    protected string $tag = '';

    protected string $key = '';

    protected string $receiptHandle = '';

    protected int $deliveryAttempt = 0;

    // 每个属性都有对应的 setter、getter
}

final class ReceiveMessageResponseDto extends AbstractDto
{
    /** @var list<array<string, mixed>> */
    protected array $messages = [];
}

final class AckMessageRequestDto extends AbstractDto
{
    protected string $consumerGroup = '';

    protected string $receiptHandle = '';

    protected string $messageId = '';

    protected string $topic = '';
}
```

Go 使用请求里的 `topic`、`consumerGroup`、`consumerTag`、`maxNum` 和 `invisibleMs`，不从环境变量读取订阅，也不把条数或时长改成固定的 1 条或 30 秒。返回的 `messages` 长度是 0 到 `maxNum`。还原时对每一项 `ReceivedMessageDto::fromArray()`。读取字段用 getter。消息上的 `tag` 是这条消息自己的 Tag，和请求里的 `consumerTag` 不是同一个字段。

### 7.5 重投

以下情况消息会再次被 `Receive` 拿到：PHP 在 `ack` 前退出、业务失败故意不确认、Go 在确认 RPC 前重启、长轮询结果因连接断开被丢掉。业务按 `messageId` 去重。`deliveryAttempt` 只记日志。

这和 `rocketmq.producer.send` 不同。发送在结果未知时不能自动再发。消费的重试就是不确认，等不可见时间结束。

---

## 8. 日志

Core 和外部进程都记录：`sidecar`、`method`、`requestId`、`code`、`durationMs`。不记录 payload 正文、密钥和认证头。超大帧只记长度和错误类别。

---

## 9. 实施顺序

1. `Core/Sidecar` 的 Spec、Registry、路径检查、帧编解码、Client、异常。不启动真实二进制也能用假 UDS 服务测 Client。
2. `SidecarSupervisor` 接到 `EventCtrl::init()`。用一个只实现 `health.check` 的测试二进制（或测试里替换 exec 的脚本）验证 `addProcess` 的返回值判断和路径隔离。
3. Go RocketMQ Sidecar 实现 `health.check` 与 `rocketmq.producer.send`，加上第 6 节的失败和延迟语义。
4. 真实 Proxy 上做发送、ACL、超时和断连。通过之前，文档不称为已上线能力。
5. 按第 7 节加上 SimpleConsumer、`rocketmq.consumer.receive` / `rocketmq.consumer.ack`，以及单个 PHP `AbstractProcess` 消费循环。发送闭环未通过前不接消费。

明确不做：Worker 内连接池、多路复用、让业务 Worker 直接 `rocketmq.consumer.receive`、Go 侧消费缓存或消费 for 循环、把 Sidecar 挂进 `MainManager`、protobuf / `google/protobuf`。HTTP、daemon、cron、script 都通过 `attach()` 注册。

连接池只在压测证明握手成本是瓶颈之后再加，并且每条连接仍然同时只有一个请求。

---

## 10. 测试

框架单测与协程测试：

- `SidecarRequestDto` / 业务 DTO 先 `toArray()` 再 `json_encode`，响应 `fromArray()` 还原；嵌套 DTO 未先 `toArray()` 时不得直接塞进信封。
- 长度分片到达、短写、长度为 0、超长、损坏 JSON、`requestId` 不一致。
- 两个协程同时 `call()`，各自只拿到自己的响应。
- 未连接成功可以区分；帧已写出之后超时抛 `OutcomeUnknownException`，且测试里的发送计数不再增加。
- `addProcess()` 返回 `0` 时 Supervisor 视为成功；只有 `false` 失败。用替身 Server，不拉起真实 Swoole 端口也能测这个判断。
- socket 路径是普通文件或符号链接时 `attach()` 抛错且不删除该路径。
- HTTP、daemon、cron、script 在注册表非空时都调用 `addProcess()`，不因 `SystemEnv::isWorkerService()` 抛错或跳过。
- 未调用 `call()` 的请求路径不会去连 socket。

Go 测试：

- `delayMs=0` 与 `delayMs>0` 的投递时间是相对延迟，不是 Unix 时间戳再加一次。
- 超过 in-flight 上限返回 `OVERLOADED`。
- Proxy 不可用时进程不退出，`health.check` 里 `producerReady` 为 false。
- `rocketmq.consumer.receive` 只调用一次 `Receive(ctx, maxNum, invisible)`，两个参数与 PHP 请求一致，没有拉取循环，返回后不保留消息。无消息时响应是 `OK` 加空列表。
- 同一个消费组和 Topic 上一次 `Receive` 尚未返回时，第二次 `rocketmq.consumer.receive` 得到 `OVERLOADED`，且不会进入等待队列。另一个 Topic 的进程可以同时拉取。
- 同一批消息按 `sidecar_rocketmq_consumer_concurrency` 并发处理，这一批结束前不会发起下一次 `receive`。
- 请求里的 `topic`、`consumerGroup`、`consumerTag` 与该 PHP 消费进程的配置一致。Go 不读取 `SIDECAR_ROCKETMQ_CONSUME_TOPICS` 或 `SIDECAR_ROCKETMQ_CONSUMER_GROUP`。
- PHP 未 `ack`、或结果因断开被丢弃时，Go 不向 RocketMQ 确认。不可见时间结束后可以再次拉取。
- PHP 传入 `maxNum=2`、`invisibleMs=20000` 时，Go 侧对应 `Receive(ctx, 2, 20*time.Second)`，不能改成别的条数或时长。JSON 键是驼峰。
- `SIGTERM` 后在关闭预算内退出，不留下仍可连接的旧 socket。

集成：

- `EventCtrl::init()` 结束时 Server 尚未 `start()`，`addProcess` 已调用。
- 两个不同 `port` 的实例 socket 路径不同，停一个不影响另一个。
- `reload()` 之后 Sidecar 二进制版本不变；完整重启后变为新二进制。

---

## 11. 源码位置

- `src/Http/HttpServer.php`、`src/Websocket/WebsocketServer.php`、`src/Tcp/TcpServer.php`、`src/Udp/UdpServer.php`、`src/Mqtt/MqttServer.php`：先创建 Server，再进入 `BaseServer::__construct()`。
- `src/Core/BaseServer.php`：`getServer()`；`workerStartInit()` 才 `Swfy::setSwooleServer()`。
- `src/Core/ServiceTrait.php`：`getServer()` 在 `Swfy::$server` 为空时退回 `BaseServer::getServer()`。
- `src/Core/EventCtrl.php`：`init()` 里 `onInit()` 之后注册内置进程。Sidecar 的 `attach()` 加在这里。
- `src/Core/Process/ProcessManager.php`、`src/Core/Process/AbstractProcess.php`：只管理 PHP 进程类。Go Sidecar 不走这里。消费循环是 PHP `AbstractProcess`，由 `ProcessManager` 注册。
- `src/Worker/MainManager.php`、`src/Worker/Process/ProcessSupervisor.php`：daemon / cron / script 的 PHP 业务子进程。Sidecar 不挂进这里，这些服务仍由 `attach()` 用 `addProcess()` 拉起外部二进制。
