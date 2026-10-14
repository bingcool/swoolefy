# Swoolefy Go Sidecar 框架技术方案

> 与 [Swoolefy-Go-Sidecar-UDS.md](Swoolefy-Go-Sidecar-UDS.md) 对齐。PHP 的 `Core/Sidecar` 只负责拉起进程和收发 JSON 帧。本文是 Go 进程内部的结构。  
> 先做公共框架，再接入 RocketMQ。以后的 AI 或其他 Go 业务实现同一个插件接口，不改 UDS、帧和信封。

---

## 1. 边界

一个 Go 进程对应 PHP 的一条 `SidecarSpec`、一个 socket 文件。PHP 用 `SidecarClient::call(name, method, payloadDto, timeoutMs)` 进来。Go 按 `method` 分给插件。

框架负责这些事：

- 读 `SIDECAR_SOCKET_PATH`，按 PHP 方案检查残留文件，监听后把 socket 设为 `0600`
- 4 字节大端长度 + 一个 JSON 对象。只处理一连接一请求，写完响应就关闭
- 驼峰 JSON，与 PHP `toArray()` 的键一致
- 并发上限、`timeoutMs` 截断、统一错误码、`SIGTERM` 下的退出顺序
- 插件的注册、启动、停止，以及 `health.check` 里各插件是否就绪

框架不负责这些事：

- 不解析 RocketMQ、AI 的业务字段之外的协议。业务 JSON 留在 `payload`，由插件自己解码
- 不在框架里缓存消息，也不替消费插件写拉取循环
- 不因为下游失败而 `os.Exit`。下游不可用时保持监听，对应方法返回 `UNAVAILABLE`
- 不使用 protobuf

RocketMQ 是第一个插件。AI 以后是第二个插件，或另一个只注册 AI 插件的进程。两种部署都走同一套 `Plugin`。

---

## 2. 目录

独立 Go module，不要放进 `src/Core/Sidecar`。

```text
swoolefy-sidecar/
  cmd/sidecar/main.go          组装框架，按配置注册插件，然后 Run
  internal/framework/
    app.go                     启动、信号、退出顺序
    config/config.go           框架级配置，不含业务密钥
    ipc/frame.go               长度前缀、读满、写满、最大帧
    ipc/envelope.go            请求/响应信封
    ipc/server.go              Accept、在途限制、一连接一请求
    ipc/router.go              method → Handler
    plugin/plugin.go           插件接口
    health/health.go           health.check，汇总各插件 Ready
    log/log.go                 结构化日志，不打印 payload 和密钥
  internal/rocketmq/           第一个业务插件
    plugin.go
    producer.go
    consumer.go
    config.go
  internal/ai/                 以后再加，现在不建空包充数
  go.mod
  go.sum
```

`cmd/sidecar/main.go` 只做三件事：读配置、`Register` 已启用的插件、`app.Run`。业务代码不出现在 `main`。

---

## 3. 插件

```go
type Handler func(ctx context.Context, payload json.RawMessage) Result

type Result struct {
    Code    string
    Message string
    Payload any
}

func OK(payload any) Result {
    if payload == nil {
        payload = map[string]any{}
    }
    return Result{Code: "OK", Message: "", Payload: payload}
}

func Fail(code, message string) Result {
    return Result{Code: code, Message: message, Payload: map[string]any{}}
}

type Plugin interface {
    Name() string
    Register(router *Router) error
    Start(ctx context.Context) error
    Stop(ctx context.Context) error
    Ready() bool
}
```

`Payload` 必须编码成 JSON 对象。空结果用 `map[string]any{}`，不能是 `nil`，否则 PHP `fromArray()` 拿不到数组。响应结构体上的 `payload` 字段不要加 `omitempty`。

```go
type Router struct {
    handlers map[string]Handler
}

func (r *Router) Handle(method string, h Handler) error {
    if _, exists := r.handlers[method]; exists {
        return fmt.Errorf("duplicate method %s", method)
    }
    r.handlers[method] = h
    return nil
}

func (r *Router) Dispatch(method string) (Handler, bool) {
    h, ok := r.handlers[method]
    return h, ok
}
```

`health.check` 由框架在插件 `Register` 之前挂上。插件 `Register` 返回错误时，进程还没 `Listen`，直接退出。这是方法名冲突，不是下游抖动。

`Register` 只登记方法，不连接外部系统。`Start` 才建立 RocketMQ 客户端或以后的模型连接。`Start` 失败时进程仍进入监听，该插件 `Ready()` 为 false，它的方法返回 `UNAVAILABLE`，并按插件自己的退避再次 `Start`。不要把 `Start` 失败变成进程退出。

方法名在一个进程内全局唯一，启动时重复注册直接失败。业务方法带业务前缀：`rocketmq.producer.send`、`rocketmq.consumer.receive`、`rocketmq.consumer.ack`。`health.check` 属于框架，不加业务前缀。

| method | 插件 |
| --- | --- |
| `health.check` | 框架，不交给业务插件 |
| `rocketmq.producer.send` | rocketmq |
| `rocketmq.consumer.receive` | rocketmq |
| `rocketmq.consumer.ack` | rocketmq |
| `rocketmq.consumer.changeInvisible` | rocketmq |

以后的 AI 使用自己的前缀，例如 `ai.complete`。两个插件要进同一个进程时，方法名不能相同。更常见的部署是两个 PHP `SidecarSpec`、两个 Go 进程，各注册自己的插件。框架不为此改路由模型。

`payload` 在框架层保持 `json.RawMessage`。插件用带 `json:"camelCase"` 的结构体解码，字段名与 PHP DTO 的驼峰属性一致，例如 `delayMs`、`maxNum`、`invisibleMs`、`receiptHandle`。

---

## 4. 信封与帧

请求：

```json
{
  "requestId": "hex",
  "method": "rocketmq.producer.send",
  "payload": {},
  "timeoutMs": 3000
}
```

响应：

```json
{
  "requestId": "hex",
  "code": "OK",
  "message": "",
  "payload": {}
}
```

```go
type Request struct {
    RequestID string          `json:"requestId"`
    Method    string          `json:"method"`
    Payload   json.RawMessage `json:"payload"`
    TimeoutMs uint32          `json:"timeoutMs"`
}

type Response struct {
    RequestID string `json:"requestId"`
    Code      string `json:"code"`
    Message   string `json:"message"`
    Payload   any    `json:"payload"`
}
```

`code` 只使用：`OK`、`INVALID_ARGUMENT`、`METHOD_NOT_FOUND`、`UNAVAILABLE`、`OVERLOADED`、`TIMEOUT`、`INTERNAL`、`OUTCOME_UNKNOWN`。插件返回业务失败时填这些字符串，不要再套一层数字状态。

未知字段用 `json.Decoder.DisallowUnknownFields` 拒绝，避免 PHP 和 Go 字段名写错还能跑。空 body、长度为 0、超过 `maxFrameBytes`（默认 16 MiB）、半帧、JSON 不是对象：关闭这条连接，不回错误帧。请求 JSON 能解码但 `method` 为空，回 `INVALID_ARGUMENT` 后关闭。

```go
func readFrame(r io.Reader, max int) ([]byte, error) {
    var hdr [4]byte
    if _, err := io.ReadFull(r, hdr[:]); err != nil {
        return nil, err
    }
    n := int(binary.BigEndian.Uint32(hdr[:]))
    if n < 1 || n > max {
        return nil, fmt.Errorf("frame length %d", n)
    }
    buf := make([]byte, n)
    _, err := io.ReadFull(r, buf)
    return buf, err
}

func writeFrame(w io.Writer, body []byte) error {
    var hdr [4]byte
    binary.BigEndian.PutUint32(hdr[:], uint32(len(body)))
    if _, err := io.Copy(w, io.MultiReader(bytes.NewReader(hdr[:]), bytes.NewReader(body))); err != nil {
        return err
    }
    return nil
}

func decodeRequest(body []byte) (Request, error) {
    dec := json.NewDecoder(bytes.NewReader(body))
    dec.DisallowUnknownFields()
    var req Request
    err := dec.Decode(&req)
    if err != nil {
        return Request{}, err
    }
    if dec.More() {
        return Request{}, fmt.Errorf("trailing json")
    }
    return req, nil
}
```

`io.ReadFull` / `io.Copy` 已经处理短读和短写。不要再包一层只调用一次 `conn.Read` 或 `conn.Write` 的函数。

`timeoutMs` 先截断到框架配置的上限（默认 30 秒），再用它做这次调用的 `context`。插件只能使用这个 `ctx`，不能再起一个更长的后台任务。

---

## 5. 连接与并发

```text
Accept
  在途数已达 SIDECAR_MAX_INFLIGHT（默认 128）→ 能写则返回 OVERLOADED，然后关闭
  否则：
    读一帧 → 路由 → 插件 Handle → 写一帧 → 关闭连接
```

在途用 `atomic.Int32`。`Add(1)` 之后如果超过上限，立刻 `Add(-1)` 并尝试写 `OVERLOADED`。`Handle` 放在 `defer conn.Close()` 和 `defer inflight.Add(-1)` 之间。写响应失败只记日志，不再调用插件。

```go
func (s *Server) serveConn(conn net.Conn) {
    defer conn.Close()
    now := s.inflight.Add(1)
    defer s.inflight.Add(-1)
    if int(now) > s.cfg.MaxInflight {
        _ = writeResult(conn, Fail("OVERLOADED", "inflight limit"))
        return
    }
    _ = conn.SetDeadline(time.Now().Add(time.Duration(s.cfg.MaxTimeoutMs) * time.Millisecond))
    body, err := readFrame(conn, s.cfg.MaxFrameBytes)
    if err != nil {
        return
    }
    req, err := decodeRequest(body)
    if err != nil {
        _ = writeResult(conn, Fail("INVALID_ARGUMENT", "bad request"))
        return
    }
    timeout := req.TimeoutMs
    if timeout == 0 || timeout > s.cfg.MaxTimeoutMs {
        timeout = s.cfg.MaxTimeoutMs
    }
    ctx, cancel := context.WithTimeout(s.root, time.Duration(timeout)*time.Millisecond)
    defer cancel()
    handler, ok := s.router.Dispatch(req.Method)
    var result Result
    if !ok {
        result = Fail("METHOD_NOT_FOUND", req.Method)
    } else {
        result = handler(ctx, req.Payload)
    }
    resp := Response{RequestID: req.RequestID, Code: result.Code, Message: result.Message, Payload: result.Payload}
    raw, err := json.Marshal(resp)
    if err != nil {
        return
    }
    _ = writeFrame(conn, raw)
}
```

`SetDeadline` 先用 `maxTimeoutMs` 罩住读帧。路由之后的业务截止时间是截断后的 `timeoutMs`，通过 `ctx` 传给插件。插件不能再 `context.Background()`。`writeResult` 用于还没有解析出 `requestId` 的失败，`requestId` 写空字符串。

一条连接上没有第二个请求，因此不需要连接内的多路复用，也不需要按 `requestId` 做响应队列。`requestId` 原样抄回响应，供 PHP 核对。

在途计数在读帧之前加上，在连接关闭时减去。`rocketmq.consumer.receive` 会堵住几十秒，它占用的是这 128 里的一个名额，框架不为它单开线程池。RocketMQ 插件按 `consumerGroup + topic` 再加一把门闩：同一把锁上的第二个 `rocketmq.consumer.receive` 直接 `OVERLOADED`，不排队、不缓存。不同 Topic 互不影响。同一批消息的并发在 PHP 进程里完成。这把门闩属于插件，不属于框架。AI 如果没有这种单飞要求，就不要复用它。

`writeFull` / `readFull` 必须循环到写完或读满。`Accept` 在监听 socket 已关闭时退出循环，其他错误记日志后继续。

---

## 6. 进程生命周期

环境变量由 PHP 在 `exec` 前写入。名称与 PHP 配置键一一对应：`sidecar_socket_path` 变成 `SIDECAR_SOCKET_PATH`。Go 只读 `SIDECAR_` 开头的变量，不读 `ROCKETMQ_*` 或 `SWOOLEFY_SIDECAR_*`。

| Go 环境变量 | 说明 | PHP 配置键 | 谁使用 |
| --- | --- | --- | --- |
| `SIDECAR_NAME` | 进程名，写入日志，并参与默认 socket 文件名 | `sidecar_name` | 日志 |
| `SIDECAR_ENABLE` | 是否拉起。启用时 PHP 写成 `1` | `sidecar_enable` | PHP 决定是否拉起；Go 进程存在时值为启用 |
| `SIDECAR_EXEC_BINARY` | 外部二进制的绝对路径，由 PHP `exec` | `sidecar_exec_binary` | PHP 用来 `exec` |
| `SIDECAR_ARGS` | `exec` 参数的 JSON 数组。`exec` 仍使用原来的字符串列表 | `sidecar_args` | PHP 用来 `exec` |
| `SIDECAR_SOCKET_PATH` | UDS 绝对路径。配置为空时由 PHP 解析后再写入 | `sidecar_socket_path` | 框架监听 |
| `SIDECAR_MAX_FRAME_BYTES` | 单帧最大字节数，默认 `16777216` | `sidecar_max_frame_bytes` | 框架 |
| `SIDECAR_DEFAULT_TIMEOUT_MS` | 调用未传超时时间时使用的默认值，默认 `3000` | `sidecar_default_timeout_ms` | 框架 |
| `SIDECAR_MAX_TIMEOUT_MS` | 单次调用超时上限，默认 `30000`。信封里的 `timeoutMs` 截断到这个值 | `sidecar_max_timeout_ms` | 框架 |
| `SIDECAR_MAX_INFLIGHT` | 同时处理的连接数上限，默认 `128`。超过返回 `OVERLOADED` | `sidecar_max_inflight` | 框架 |
| `SIDECAR_SHUTDOWN_GRACE_MS` | 收到 `SIGTERM` 后等待在途请求结束的毫秒数，默认 `5000` | `sidecar_shutdown_grace_ms` | 框架 |
| `SIDECAR_PLUGINS` | 逗号分隔的插件名，例如 `rocketmq`，以后可以是 `rocketmq,ai` | `sidecar_plugins` | `main` |
| `SIDECAR_ROCKETMQ_PROXY_ENDPOINT` | RocketMQ Proxy 的 gRPC 地址，格式 `host:port`。PHP 从 `APP_PATH/.env` 的同名大写变量读出后再 `putenv` | `sidecar_rocketmq_proxy_endpoint` | RocketMQ 插件 |
| `SIDECAR_ROCKETMQ_ACCESS_KEY` | Proxy 访问密钥。来源同上。空表示不校验。不写入日志和 `health.check` | `sidecar_rocketmq_access_key` | RocketMQ 插件 |
| `SIDECAR_ROCKETMQ_SECRET_KEY` | Proxy 密钥。来源同上。空表示不校验。不写入日志和 `health.check` | `sidecar_rocketmq_secret_key` | RocketMQ 插件 |
| `SIDECAR_ROCKETMQ_ALLOWED_TOPICS` | 允许发送的 Topic，逗号分隔。值为 `*` 时允许所有 Topic。空列表拒绝一切发送 | `sidecar_rocketmq_allowed_topics` | RocketMQ 插件 |

`config.Load` 只读环境变量，读不到就用默认值。解析失败属于配置错误，`main` 在 `Listen` 之前退出。

```go
func Load() (Config, error) {
    var err error
    cfg := Config{
        Name:           os.Getenv("SIDECAR_NAME"),
        SocketPath:     os.Getenv("SIDECAR_SOCKET_PATH"),
        MaxFrameBytes:  16 * 1024 * 1024,
        DefaultTimeout: 3000,
        MaxTimeoutMs:   30000,
        MaxInflight:    128,
        ShutdownGrace:  5000,
    }
    if cfg.Plugins, err = splitCSV(os.Getenv("SIDECAR_PLUGINS")); err != nil {
        return cfg, err
    }
    if cfg.SocketPath == "" || !filepath.IsAbs(cfg.SocketPath) {
        return cfg, fmt.Errorf("SIDECAR_SOCKET_PATH")
    }
    if cfg.MaxFrameBytes, err = envInt("SIDECAR_MAX_FRAME_BYTES", cfg.MaxFrameBytes, 1024, 16*1024*1024); err != nil {
        return cfg, err
    }
    if cfg.MaxTimeoutMs, err = envInt("SIDECAR_MAX_TIMEOUT_MS", cfg.MaxTimeoutMs, 1, 300000); err != nil {
        return cfg, err
    }
    if cfg.DefaultTimeout, err = envInt("SIDECAR_DEFAULT_TIMEOUT_MS", cfg.DefaultTimeout, 1, cfg.MaxTimeoutMs); err != nil {
        return cfg, err
    }
    if cfg.MaxInflight, err = envInt("SIDECAR_MAX_INFLIGHT", cfg.MaxInflight, 1, 1024); err != nil {
        return cfg, err
    }
    if cfg.ShutdownGrace, err = envInt("SIDECAR_SHUTDOWN_GRACE_MS", cfg.ShutdownGrace, 0, 30000); err != nil {
        return cfg, err
    }
    return cfg, nil
}

func envInt(key string, fallback, min, max int) (int, error) {
    raw := os.Getenv(key)
    if raw == "" {
        return fallback, nil
    }
    n, err := strconv.Atoi(raw)
    if err != nil || n < min || n > max {
        return 0, fmt.Errorf("%s out of range", key)
    }
    return n, nil
}
```

`envInt` 在变量为空时返回默认值。有值但不是十进制整数，或超出上下限，返回错误。`splitCSV` 按逗号切开并去掉空白，空段或重复项返回错误。`Load` 里要先声明 `err error`。

RocketMQ 插件另有 `rocketmq.Load`：

| 变量 | 解析 |
| --- | --- |
| `SIDECAR_ROCKETMQ_PROXY_ENDPOINT` | 必填。不含 `://` 和 `/`，且含一个 `:`。值由 PHP 从 `.env` 同名大写变量写入 |
| `SIDECAR_ROCKETMQ_ACCESS_KEY` / `SIDECAR_ROCKETMQ_SECRET_KEY` | 原样，来源同上。可以都空，表示 Proxy 不校验 |
| `SIDECAR_ROCKETMQ_ALLOWED_TOPICS` | 整段 trim 后等于 `*` 时 `allowAll=true`，不再按逗号拆。其他值走 `splitCSV`，其中任一项是 `*` 则配置错误。空列表表示拒绝一切发送 |

启动顺序：

```text
检查 socket 路径（与 PHP 第 4.4 节同一规则）
Listen
chmod 0600
按 SIDECAR_PLUGINS 注册并 Start 插件
开始 Accept
```

socket 路径是普通文件、目录、符号链接，或已经有进程在监听：进程启动失败并退出。这是配置错误，不是下游抖动。只有确认是无人监听的 socket 残留才删除。

`SIGTERM`（Swoole 停进程时会发）时的顺序：

```text
停止 Accept
关闭监听，解除 Accept 阻塞
取消在途 ctx
等待在途处理，不超过 SIDECAR_SHUTDOWN_GRACE_MS
按注册的反序 Stop 插件
若本进程创建的路径仍是 socket，则删除
退出
```

自定义进程不会随 PHP `reload()` 换二进制。升级 Go 要重启 Swoole Server。

---

## 7. health.check

框架注册，插件不各自暴露一个健康方法。

响应 `payload` 使用驼峰，与 PHP `HealthCheckResponseDto` 一致：

```json
{
  "version": "0.1.0",
  "uptimeMs": 1000,
  "listenerOk": true,
  "inflight": 1,
  "producerReady": true,
  "consumerReady": false,
  "sendOk": 0,
  "sendFail": 0,
  "sendTimeout": 0
}
```

`producerReady`、`sendOk`、`sendFail`、`sendTimeout` 只有注册了 `rocketmq.producer.send` 才放进 map。`consumerReady` 只有注册了 `rocketmq.consumer.receive` 才放进 map。没有的键不要写成 `false` 或 `0`。`producerReady` 表示 Producer 已 `Start` 成功。`consumerReady` 表示消费插件和 Proxy 配置可用，不表示某个消费组已经订阅。两者都不表示 RocketMQ 集群健康。

计数由 RocketMQ 插件用 `atomic.Uint64` 累加，健康方法只读 `Load()`。不在 `health.check` 里发探测请求。`ctx.Err()==context.DeadlineExceeded` 记 `sendTimeout`，SDK 返回其他错误记 `sendFail`，SDK 成功记 `sendOk`。

---

## 8. RocketMQ 插件

插件名 `rocketmq`。它是框架的使用者，Producer 和 Consumer 的 SDK 客户端都放在 `internal/rocketmq`。

### 8.1 发送

`rocketmq.producer.send` 的 `payload`（`keys`、`properties`、`deliverAtMs`、`messageGroup` 可选，PHP 为空时不发；校验规则和取舍见 `docs/Swoolefy-RocketMQ-业务能力补齐技术方案.md`）：

```json
{
  "topic": "order",
  "bodyBase64": "...",
  "tag": "",
  "keys": ["ORDER_1"],
  "properties": {"traceId": "t-1"},
  "delayMs": 0,
  "deliverAtMs": 0,
  "messageGroup": ""
}
```

```go
type sendRequest struct {
    Topic        string            `json:"topic"`
    BodyBase64   string            `json:"bodyBase64"`
    Tag          string            `json:"tag"`
    Keys         []string          `json:"keys"`
    Properties   map[string]string `json:"properties"`
    DelayMs      int64             `json:"delayMs"`
    DeliverAtMs  int64             `json:"deliverAtMs"`
    MessageGroup string            `json:"messageGroup"`
}

func (p *Producer) Send(ctx context.Context, raw json.RawMessage) Result {
    if !p.ready.Load() {
        return Fail("UNAVAILABLE", "producer not ready")
    }
    var req sendRequest
    if err := decodePayload(raw, &req); err != nil {
        return Fail("INVALID_ARGUMENT", "payload")
    }
    if !p.allow(req.Topic) {
        return Fail("INVALID_ARGUMENT", "topic")
    }
    body, err := base64.StdEncoding.DecodeString(req.BodyBase64)
    if err != nil || len(body) == 0 {
        return Fail("INVALID_ARGUMENT", "bodyBase64")
    }
    if req.DelayMs < 0 {
        return Fail("INVALID_ARGUMENT", "delayMs")
    }
    msg := &golang.Message{Topic: req.Topic, Body: body}
    if req.Tag != "" {
        msg.SetTag(req.Tag)
    }
    if len(req.Keys) > 0 {
        msg.SetKeys(req.Keys...)
    }
    if req.DelayMs > 0 {
        msg.SetDelayTimestamp(time.Now().Add(time.Duration(req.DelayMs) * time.Millisecond))
    }
    receipts, err := p.client.Send(ctx, msg)
    if err != nil {
        return classify(err)
    }
    if len(receipts) == 0 || receipts[0].MessageID == "" {
        return Fail("INTERNAL", "empty message id")
    }
    return OK(map[string]any{"messageId": receipts[0].MessageID})
}
```

`decodePayload` 同样 `DisallowUnknownFields`。`delayMs` 是相对毫秒。`0` 不调用 `SetDelayTimestamp`。大于 0 时用 `time.Now().Add`，禁止把 Unix 毫秒再 `Add` 一次。

`SIDECAR_ROCKETMQ_ALLOWED_TOPICS` 为 `*` 时，`allow` 对任何非空 `topic` 返回 true。否则 `topic` 必须整段等于列表中的一项，空列表或未命中都是 `INVALID_ARGUMENT`。`order,*` 这种混写在启动时失败，不在发送时当成通配。Proxy 地址来自 `SIDECAR_ROCKETMQ_PROXY_ENDPOINT`。未就绪返回 `UNAVAILABLE`。

`classify`：`ctx.Err()` 非空返回 `TIMEOUT`；SDK 明确拒绝参数返回 `INVALID_ARGUMENT`；其余返回 `UNAVAILABLE` 并触发退避重连。不要把 SDK 错误原文放进 `message`，只放固定短句，避免把代理地址或密钥拼进去。

一次 `Send` 在返回前结束。框架不重试。`Send` 已经返回成功、但 `writeFrame` 失败时，PHP 会看成结果未知；Go 不要在后台再 `Send` 一次。

不实现 `properties`，直到 SDK 映射和测试都有了再加字段。Producer 用 `golang.NewProducer`，`Config.Endpoint` 是 `host:port`，`Credentials` 使用 access key / secret key。`Start` 失败则 `ready=false`，按 1s、2s、4s、8s、16s、30s 封顶退避，再次 `Start`。进程不退出。

### 8.2 消费

PHP 循环驱动。Go 没有消费 `for`，也不保存消息。进程启动时不订阅。`topic`、`consumerGroup`、`consumerTag` 来自本次 payload，与 PHP 消费进程的 `sidecar_rocketmq_consume_topics`、`sidecar_rocketmq_consumer_group`、`sidecar_rocketmq_consumer_tag` 对应。

同一个 `consumerGroup` 只建一个 SimpleConsumer。第一次见到这个组时 `NewSimpleConsumer` 并 `Start`。`Subscribe(topic, filter)` 每个 Topic 只做一次。`consumerTag` 为 `*` 或空时用 `golang.SUB_ALL`，否则 `golang.NewFilterExpression(consumerTag)`。payload 带了非空 `consumerSql` 时用 `golang.NewFilterExpressionWithType(consumerSql, golang.SQL92)`，此时 `consumerTag` 只能是 `*` 或空。同一 Topic 再次带来不同的过滤类型或表达式：返回 `INVALID_ARGUMENT` / `filter`。

`rocketmq.consumer.receive`：

```go
const (
    maxReceiveNum  = 32
    maxInvisibleMs = 5 * 60 * 1000
)

type receiveRequest struct {
    Topic         string `json:"topic"`
    ConsumerGroup string `json:"consumerGroup"`
    ConsumerTag   string `json:"consumerTag"`
    ConsumerSQL   string `json:"consumerSql,omitempty"`
    MaxNum        int32  `json:"maxNum"`
    InvisibleMs   int64  `json:"invisibleMs"`
}

func (c *Consumer) Receive(ctx context.Context, raw json.RawMessage) Result {
    if !c.ready.Load() {
        return Fail("UNAVAILABLE", "consumer not ready")
    }
    var req receiveRequest
    if err := decodePayload(raw, &req); err != nil {
        return Fail("INVALID_ARGUMENT", "payload")
    }
    if req.Topic == "" || req.ConsumerGroup == "" {
        return Fail("INVALID_ARGUMENT", "topic or consumerGroup")
    }
    if req.MaxNum <= 0 || req.MaxNum > maxReceiveNum || req.InvisibleMs <= 0 || req.InvisibleMs > maxInvisibleMs {
        return Fail("INVALID_ARGUMENT", "maxNum or invisibleMs")
    }
    key := req.ConsumerGroup + "\x00" + req.Topic
    if !c.beginReceive(key) {
        return Fail("OVERLOADED", "receive in progress")
    }
    defer c.endReceive(key)

    sc, err := c.consumerFor(req.ConsumerGroup)
    if err != nil {
        return Fail("UNAVAILABLE", "consumer group")
    }
    if err = sc.ensureSubscription(req.Topic, req.ConsumerTag); err != nil {
        return Fail("INVALID_ARGUMENT", "subscription")
    }
    mvs, err := sc.Receive(ctx, req.MaxNum, time.Duration(req.InvisibleMs)*time.Millisecond)
    if err != nil {
        if ctx.Err() != nil {
            return OK(map[string]any{"messages": []any{}})
        }
        return classify(err)
    }
    out := make([]receivedMessage, 0, len(mvs))
    for _, mv := range mvs {
        out = append(out, receivedMessage{
            MessageID:       mv.GetMessageId(),
            Topic:           mv.GetTopic(),
            BodyBase64:      base64.StdEncoding.EncodeToString(mv.GetBody()),
            Tag:             mv.GetTag(),
            Keys:            mv.GetKeys(),
            ReceiptHandle:   mv.GetReceiptHandle(),
            DeliveryAttempt: int(mv.GetDeliveryAttempt()),
        })
    }
    return OK(map[string]any{"messages": out})
}

type receivedMessage struct {
    MessageID           string            `json:"messageId"`
    Topic               string            `json:"topic"`
    BodyBase64          string            `json:"bodyBase64"`
    Tag                 string            `json:"tag"`
    Keys                []string          `json:"keys"`
    Properties          map[string]string `json:"properties"`
    MessageGroup        string            `json:"messageGroup"`
    BornTimestampMs     int64             `json:"bornTimestampMs"`
    DeliveryTimestampMs int64             `json:"deliveryTimestampMs"`
    ReceiptHandle       string            `json:"receiptHandle"`
    DeliveryAttempt     int               `json:"deliveryAttempt"`
}
```

上面的 getter 名按锁定的 SDK 实际导出名来写。字段集合不能少。`keys` 原样返回数组，没有时是 `[]`；`properties` 去掉 `__` 开头的系统属性，没有时是 `{}`，都不输出 `null`。`deliveryAttempt` 在 JSON 里是数字。函数返回前不把 `MessageView` 放进 map 或包级变量。

`ctx` 到期或取消，并且 SDK 因此返回错误：响应用 `OK` 加 `"messages": []`。PHP 把空列表当成这一轮没拉到，马上进入下一轮。其他 SDK 错误走 `classify`。连接已经写不回时，结果丢在 `writeFrame` 的错误里，不 `Ack`。

`maxNum`、`invisibleMs` 来自本次 payload。只拒绝 `<= 0` 和超过上限的值，不改写成 1 或 30 秒。无消息且 SDK 返回空切片时，同样是 `OK` 加空列表。

`rocketmq.consumer.ack`：

```go
type ackRequest struct {
    ConsumerGroup string `json:"consumerGroup"`
    ReceiptHandle string `json:"receiptHandle"`
    MessageID     string `json:"messageId"`
    Topic         string `json:"topic"`
}

type acker interface {
    Ack(ctx context.Context, consumerGroup, receiptHandle, messageID, topic string) error
}
```

`consumerGroup` 用来选中 `consumerFor` 返回的那个 SimpleConsumer。插件只依赖 `acker`。`internal/rocketmq/sdk_ack.go` 是唯一允许引用 SDK `Ack` 的文件。实现必须用请求里的回执字段向 Proxy 确认，不能按 `messageId` 找回上一轮的 `*MessageView`。

锁定 `github.com/apache/rocketmq-clients/golang/v5` 时先看 `SimpleConsumer.Ack` 的签名。若它只接受 `*MessageView`，且该类型没有从 `receiptHandle` 构造的导出函数，这一版停止在 `sdk_ack.go`，不合并缓存 map。换用提供回执确认的 API 之后再写这个文件。`Receive` 和 `Send` 可以先对着假的 `acker` 测完。

Proxy 地址不可用时 `producerReady` 为 false，发送返回 `UNAVAILABLE`。某个消费组 `Start` 失败只让该组的拉取返回 `UNAVAILABLE`，进程继续 Accept，并按 1s、2s、4s、8s、16s、30s 封顶退避重建这个组。

---

## 9. 以后加一个业务

以 AI 为例，不改 `ipc` 和 `app`：

1. 新增 `internal/ai`，实现 `Plugin`。
2. `Register` 里挂上 `ai.complete` 这类方法。
3. `main` 在 `SIDECAR_PLUGINS` 包含 `ai` 时 `Register`。
4. PHP 增加一条 Sidecar，或在同一进程的 Spec 里启用该插件，用现成的 `SidecarClient::call`。
5. 健康字段如果要暴露，在插件的 `Ready()` 上增加，由 `health.check` 汇总，不新开一条健康协议。

AI 插件自己决定超时、密钥和是否允许并发。框架只提供连接、帧、信封和在途上限。

---

## 10. 测试

框架：

- 半包、短写、长度为 0、超长、未知 JSON 字段、`method` 不存在。
- 在途达到上限时新连接得到 `OVERLOADED`。
- `timeoutMs` 超过上限时被截断，插件拿到的 `ctx` 不会更长。
- 重复注册同一个 method，进程拒绝启动。
- `SIGTERM` 后不再 Accept，插件 `Stop` 被调用，自己的 socket 被删掉；别人的活跃 socket 不会被删。

RocketMQ 插件：

- `delayMs` 是相对延迟。
- `maxNum`、`invisibleMs` 与请求一致地传给 `Receive`，且只调用一次。
- 无消息是 `OK` 加空列表。同一个 `consumerGroup + topic` 的第二个并发 `rocketmq.consumer.receive` 是 `OVERLOADED`。另一个 Topic 的并发拉取不受影响。
- `topic`、`consumerGroup`、`consumerTag` 来自本次请求。`*` 订阅全部 Tag。同一 Topic 的 Tag 前后不一致时返回 `INVALID_ARGUMENT`。
- 未 `Ack` 或结果被丢弃时，不向 RocketMQ 确认。
- Proxy 失败时进程仍在监听。

插件测试使用假的 Producer / Consumer 接口，不在单元测试里连接真实 Proxy。