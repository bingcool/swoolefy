# InterfaceApi 技术方案

`InterfaceApi` 是独立 Composer 包。各项目安装后直接调用其中的 Client。契约（接口、Request、Response、Dto、Const、Enum）与生成的 Client 都在这个包内，不再另建 `GenerateSdk`。

服务端路由文件保持不变，`dispatch_route` 仍指向 Controller。Controller 实现 `InterfaceApi` 接口，使入参和返回值与契约一致。接口上的 `#[RouteGroup]`、`#[Route]` 只用于生成 Client，不代替路由文件。

## 1. 仓库布局

无 `src` 目录。PSR-4 将 `InterfaceApi\` 映射到仓库根目录。

```text
InterfaceApi/
  composer.json
  {项目}/{应用}/Module/Agent/AgentChatApiInterface.php
  {项目}/{应用}/Module/Agent/Request/
  {项目}/{应用}/Module/Agent/Response/
  {项目}/{应用}/Module/Agent/Dto/
  {项目}/{应用}/Module/Agent/Const/
  {项目}/{应用}/Module/Agent/Enum/
  {项目}/{应用}/Module/Agent/Client/AgentChatApi.php
  Support/
```

```json
{
  "name": "swoolefy/interface-api",
  "autoload": {
    "psr-4": {
      "InterfaceApi\\": ""
    }
  }
}
```

| 类 | 文件 |
|---|---|
| `InterfaceApi\Swoolefy\Test\Module\Agent\AgentChatApiInterface` | `Swoolefy/Test/Module/Agent/AgentChatApiInterface.php` |
| `InterfaceApi\Swoolefy\Test\Module\Agent\Client\AgentChatApi` | `Swoolefy/Test/Module/Agent/Client/AgentChatApi.php` |
| `InterfaceApi\Support\Route` | `Support/Route.php` |
| `InterfaceApi\Support\ApiProperty` | `Support/ApiProperty.php` |
| `InterfaceApi\Support\StreamResponse` | `Support/StreamResponse.php` |

`{项目}`、`{应用}` 与服务端部署名一致。一个模块一个接口，对应一个 Client 类。Client 类名是接口名去掉 `Interface` 后缀：`AgentChatApiInterface` → `AgentChatApi`。

## 2. 引用边界

生成 Client 之前，扫描除 `Client/`、`Support/` 以外的全部 PHP 文件。用 `token_get_all` 收集 `use`、`extends`、`implements`、`new`、`类::常量`、`类::方法`、属性类型、属性注解里的 `::class`。解析为 FQCN 后，只允许：

- 以 `InterfaceApi\` 开头的类
- PHP 内建类（`ReflectionClass::getFileName()` 为 `false`，或无命名空间的全局类，如 `InvalidArgumentException`、`stdClass`、`DateTimeInterface`）

出现其他类时，打印 `文件:行号 FQCN`，退出码非 0，不写任何 Client 文件。

`Client/` 与 `Support/` 可以使用 Guzzle。`Swoolefy\Http\RequestInput`、`Swoolefy\Worker\...`、`Doctrine\...` 不能出现在契约里。

## 3. 响应信封

HTTP JSON 外层固定为：

```json
{
  "code": 0,
  "msg": "success",
  "data": {}
}
```

`code === 0` 为成功。列表的 `data` 是 `{ "total": 0, "list": [] }`。分页的 `data` 是 `{ "total": 0, "list": [], "page": 1, "pageSize": 10 }`。单个对象的 `data` 是字段对象。

Response 只声明强类型 `$data`。`code`、`msg` 在 `BaseResponse` 上。列表元素类型只写在 `#[ArrayList(itemClass: ...)]`。

## 4. Support 实现

命名空间一律为 `InterfaceApi\Support`。行为对齐现有 `Swoolefy\Annotation\*`、`Swoolefy\Http\BaseRequest`、`Swoolefy\Http\BaseResponse`、`Swoolefy\Core\Dto\AbstractDto`；`BaseClientApi` 骨架见 §4.10。契约包不依赖 Swoolefy，因此 `BaseRequest` 不再持有 `RequestInput`。

Support 源码维护在 InterfaceApi 仓库的 `Support/` 目录（见 §1），由契约包自行维护，不由 Swoolefy CLI 生成。

### 4.1 Route 与 RouteGroup

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class RouteGroup
{
    public function __construct(
        public readonly string $prefix = '',
        public readonly string $name = '',
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Route
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
    ) {
    }
}
```

`method` 取 `GET`、`POST`、`PUT`、`PATCH`、`DELETE`、`HEAD`、`OPTIONS`。`prefix` 与 `path` 拼接时各去首尾 `/`，结果以 `/` 开头。`prefix` 为空时路径等于 `path`。

接口示例：

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Swoolefy\Test\Module\Agent;

use InterfaceApi\Support\Route;
use InterfaceApi\Support\RouteGroup;
use InterfaceApi\Swoolefy\Test\Module\Agent\Request\ChatRequest;
use InterfaceApi\Swoolefy\Test\Module\Agent\Response\ChatResponse;

#[RouteGroup(prefix: '/v1/agent', name: 'agentchat')]
interface AgentChatApiInterface
{
    #[Route(method: 'POST', path: '/chat')]
    public function chat(ChatRequest $request): ChatResponse;
}
```

### 4.2 ApiProperty 与 ArrayList

对齐 `Swoolefy\Annotation\ApiProperty`、`Swoolefy\Annotation\ArrayList`。`ApiProperty` 只标在属性上，描述字段；不参与校验。`ArrayList` 只标在属性上，`itemClass` 声明列表元素 DTO，供文档和 `CovertProperty` 递归填充。

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class ApiProperty
{
    public function __construct(
        protected string $description = ''
    ) {
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class ArrayList
{
    public function __construct(
        protected string $itemClass = ''
    ) {
    }

    public function getItemClass(): string
    {
        return $this->itemClass;
    }
}
```

### 4.3 ValidationRule

对齐 `Swoolefy\Annotation\Validation\ValidationRule`。服务端校验器读取同一组字段：`rule`、`message`、`itemRule`、`itemMessage`、`itemClass`。类继承空的 `AbstractValidationRule`，与框架一致，便于服务端按基类识别规则注解。

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

abstract class AbstractValidationRule
{
}
```

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ValidationRule extends AbstractValidationRule
{
    public function __construct(
        protected string $rule = '',
        protected string|array $message = [],
        protected string $itemRule = '',
        protected string|array $itemMessage = [],
        protected string $itemClass = '',
    ) {
    }

    public function getRule(): string
    {
        return $this->rule;
    }

    public function getMessage(): string|array
    {
        return $this->message;
    }

    public function getItemRule(): string
    {
        return $this->itemRule;
    }

    public function getItemMessage(): string|array
    {
        return $this->itemMessage;
    }

    public function getItemClass(): string
    {
        return $this->itemClass;
    }
}
```

### 4.4 StringToInt 与 IntToString

对齐 `Swoolefy\Annotation\StringToInt`、`Swoolefy\Annotation\IntToString`。两者都是无参数的属性标记。

`StringToInt` 用在 Request：校验前把纯数字字符串转成 int。`IntToString` 用在 Response：输出 JSON 时把该属性的 int 转成 string，避免超过 `2^53-1` 的整数在调用方丢失精度。Client 反序列化时看到的是字符串，属性类型按 string 声明。

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class StringToInt
{
}
```

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class IntToString
{
}
```

### 4.5 ApiController 与 ApiOperation

对齐 `Swoolefy\Annotation\ApiController`、`Swoolefy\Annotation\ApiOperation`。两者只提供文案，不参与路由和校验。`ApiController` 标在 Controller 类上。`ApiOperation` 标在接口方法上，生成 Client 时把 `summary` 或 `description` 写进方法注释。只传一个位置参数时，该值作为 `description`。

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class ApiController
{
    public function __construct(
        protected string $description = ''
    ) {
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class ApiOperation
{
    public function __construct(
        protected string $description = '',
        protected string $summary = '',
    ) {
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }
}
```

### 4.6 StreamResponse、ChunkedResponse、DownloadResponse

对齐 `Swoolefy\Annotation\StreamResponse`、`ChunkedResponse`、`DownloadResponse`。三者都标在接口方法上，且一个方法只能有其中一个。生成 Client 时按注解选择解析器，不再把响应体填成 `BaseResponse`。

| 注解 | 响应 | Client 返回类型 |
|---|---|---|
| `StreamResponse` | `text/event-stream` | `array`，元素为 `{event, id, data}` |
| `ChunkedResponse` | 分块或原始 body | `string` |
| `DownloadResponse` | 附件或 inline 文件 | `array{content, filename, contentType}` |

未标注时仍走 JSON 信封，返回类型为方法声明的 `BaseResponse` 子类。

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class StreamResponse
{
}
```

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class ChunkedResponse
{
}
```

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class DownloadResponse
{
}
```

### 4.7 ArrayDto 与 AbstractDto

对齐当前 `Swoolefy\Core\Dto\ArrayDto` 与 `Swoolefy\Core\Dto\AbstractDto`。`AbstractDto` 是空子类。`$dto->field`、`$dto['field']`、`json_encode($dto)`、`foreach` 以及 `fromArray()` 由 `ArrayDto` 上的 `InteractsWithDtoArrayAccess` 提供。

`ArrayDto` 继承 `\stdClass`，并实现 `ArrayInterface`、`ArrayAccess`、`JsonSerializable`、`IteratorAggregate`。`toArray()` 导出已声明实例属性，再补上动态 public 属性。`toDeepArray()` 递归展开数组、`ArrayInterface`、`JsonSerializable` 和带 `toArray()` 的对象。`copyProperty()` 只写入已声明、非静态、非只读字段。`copyDeepProperty()` 先经 `CovertProperty` 按属性类型和 `#[ArrayList]` 转成 DTO，再浅拷到当前对象。`builder()` 返回 `new static()`。

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

interface ArrayInterface
{
    public function toArray(): array;

    public function toDeepArray(): array;
}
```

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use ArrayAccess;
use IteratorAggregate;
use JsonSerializable;
use ReflectionProperty;

class ArrayDto extends \stdClass implements ArrayInterface, ArrayAccess, JsonSerializable, IteratorAggregate
{
    use InteractsWithDtoArrayAccess;

    public static function builder(): static
    {
        return new static();
    }

    public function toArray(): array
    {
        $out = [];
        foreach (
            (new \ReflectionClass($this))->getProperties(
                ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE
            ) as $property
        ) {
            if ($property->isStatic()) {
                continue;
            }
            $property->setAccessible(true);
            if (!$property->isInitialized($this)) {
                continue;
            }
            $out[$property->getName()] = $property->getValue($this);
        }
        foreach (get_object_vars($this) as $name => $value) {
            if (!array_key_exists($name, $out)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    public function toDeepArray(): array
    {
        return $this->valueToDeepArray($this->toArray());
    }

    public function valueToDeepArray(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->valueToDeepArray($item);
            }

            return $value;
        }
        if ($value instanceof ArrayInterface) {
            return $this->valueToDeepArray($value->toDeepArray());
        }
        if ($value instanceof JsonSerializable) {
            return $this->valueToDeepArray($value->jsonSerialize());
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->valueToDeepArray($value->toArray());
        }

        return $value;
    }

    public function copyProperty(array|AbstractDto $data): void
    {
        $data = $data instanceof AbstractDto ? $data->toArray() : $data;
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $name = (string) $key;
            if ($name === '') {
                continue;
            }
            $property = $this->reflectionPropertyForDeclaredField($name);
            if ($property === null || $property->isReadOnly()) {
                continue;
            }
            $property->setAccessible(true);
            $property->setValue($this, $value);
        }
    }

    public function copyDeepProperty(array|ArrayDto $data): void
    {
        $dto = CovertProperty::toCovertDeepProperty($data, static::class);
        if (is_object($dto) && method_exists($dto, 'toArray')) {
            $this->copyProperty($dto->toArray());
        }
    }

    protected function reflectionPropertyForDeclaredField(string $name): ?ReflectionProperty
    {
        for (
            $class = new \ReflectionClass($this);
            $class !== null && $class->getName() !== 'stdClass';
            $class = $class->getParentClass()
        ) {
            if (!$class->hasProperty($name)) {
                continue;
            }
            $property = $class->getProperty($name);
            if ($property->isStatic()) {
                return null;
            }

            return $property;
        }

        return null;
    }
}
```

`InteractsWithDtoArrayAccess` 与 `Swoolefy\Core\Dto\Concerns\InteractsWithDtoArrayAccess` 同构，命名空间改为 `InterfaceApi\Support`，`ArrayList` 用本包注解。行为要点：

- `offsetGet` / `__get`：已声明属性优先，否则 `data` 走 `getData()`，其他字段走 `getXxx()`。
- `offsetSet` / `__set`：已有 `setXxx()` 时走 setter（`data` 走 `setData()`），否则写已声明属性。`$dto[] = ...`、未知字段、只读字段抛异常。
- `offsetUnset` 把有类型的属性恢复为未初始化。
- `jsonSerialize()` 与 `getIterator()` 使用 `toDeepArray()`。
- `fromArray()` 只填已声明字段。属性类型是 `ArrayDto` 子类时递归 `fromArray()`。带 `#[ArrayList]` 的数组按 `itemClass` 逐项 `fromArray()`。

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

class AbstractDto extends ArrayDto
{
}
```

### 4.8 BaseRequest

安装方没有 `RequestInput`。读参只走已声明属性。服务端在调用 Controller 之前，按 `#[ValidationRule]` 校验原始 HTTP 参数，再 `copyProperty()` 到该对象。

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

class BaseRequest extends ArrayDto
{
    public function validated(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->toArray();
        unset($data['requestInput']);
        if ($key === null) {
            return $data;
        }

        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    public function only(string ...$keys): array
    {
        $data = $this->validated();
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }

        return $out;
    }
}
```

分页入参继承 `BasePageRequest`，字段为 `page`（默认 1）与 `pageSize`（默认 10），并带 `#[ValidationRule(rule: 'required|int')]`。

### 4.9 BaseResponse

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

class BaseResponse extends ArrayDto
{
    protected int $code = 0;

    protected string $msg = 'success';

    public function getCode(): int
    {
        return $this->code;
    }

    public function getMsg(): string
    {
        return $this->msg;
    }

    public function getData(): mixed
    {
        return $this->data ?? null;
    }
}
```

子类重新声明 `$data`：

```php
class ChatResponse extends BaseResponse
{
    #[ApiProperty(description: '对话结果')]
    protected ChatResultDto $data;
}
```

列表载荷继承 `AbstractListDataDto`（`total` + `list`）。`addListItem` / `setList` 按子类 `#[ArrayList]` 校验元素类型，并把 `total` 同步为 `list` 长度。分页载荷继承 `AbstractPageDataDto`，额外有 `page`、`pageSize`；`total` 是全量条数，由 `setTotal()` 写入，不随当前页 `list` 长度变化。

### 4.10 BaseClientApi

生成的 Client 继承本类。JSON 接口的调用链是：`mergeClientOptions` → `requestWithConnectRetry` → `parseResponseByHeaders` → 业务码校验 → `CovertProperty` 填回 Response。

连接重试、Nacos 换节点、SSE、下载、XML 的行为与历史 SDK 客户端基类一致。下面给出 Client 生成所依赖的骨架。`serviceName` 由生成器按该应用的 Nacos 服务名写入子类。

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Support;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

abstract class BaseClientApi
{
    protected string $serviceName = '';

    protected string $baseUri = '';

    protected ClientInterface $httpClient;

    protected bool $nacosDiscoveryEnabled = false;

    public function __construct(
        ?ClientInterface $httpClient = null,
        string $baseUri = '',
    ) {
        if ($httpClient !== null) {
            $this->httpClient = $httpClient;
            if ($baseUri !== '') {
                $this->baseUri = rtrim($baseUri, '/') . '/';
            }

            return;
        }

        if ($baseUri !== '') {
            $this->baseUri = rtrim($baseUri, '/') . '/';
            $this->nacosDiscoveryEnabled = false;
        } else {
            $resolved = NacosServiceDiscovery::resolveInstance($this->serviceName);
            $this->baseUri = $resolved['base_uri'];
            $this->nacosDiscoveryEnabled = true;
        }
        $this->httpClient = new Client(['base_uri' => $this->baseUri]);
    }

    public static function makeService(?ClientInterface $httpClient = null, string $baseUri = ''): static
    {
        return new static($httpClient, $baseUri);
    }

    protected function uri(string $path): string
    {
        if ($this->baseUri !== '') {
            return rtrim($this->baseUri, '/') . '/' . ltrim($path, '/');
        }

        return '/' . ltrim($path, '/');
    }

    protected function requestWithConnectRetry(
        string $method,
        string $uri,
        array $options = [],
        ?int $retryNum = null,
    ): ResponseInterface {
        $maxRetries = $retryNum ?? (in_array(strtoupper($method), ['POST', 'PATCH'], true) ? 0 : 1);
        $maxRetries = max(0, min(3, $maxRetries));
        $left = $maxRetries;

        while (true) {
            try {
                return $this->httpClient->request($method, $uri, $options);
            } catch (RequestException $e) {
                if ($left <= 0) {
                    throw $e;
                }
                $left--;
                if ($this->nacosDiscoveryEnabled) {
                    $resolved = NacosServiceDiscovery::resolveInstance($this->serviceName);
                    $this->baseUri = $resolved['base_uri'];
                }
            }
        }
    }

    protected function mergeClientOptions(array $requestDefaults, array $options = []): array
    {
        $defaults = [
            'http_errors' => true,
            'headers' => ['Content-Type' => 'application/json'],
            'connect_timeout' => 30.0,
            'timeout' => 120.0,
        ];
        $defaults = array_merge($defaults, $requestDefaults);
        $merged = array_merge($defaults, $options);
        if (isset($defaults['headers'], $options['headers']) && is_array($options['headers'])) {
            $merged['headers'] = array_merge($defaults['headers'], $options['headers']);
        }

        return $merged;
    }

    protected function parseResponseByHeaders(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new ClientException('Unexpected HTTP status: ' . $status, $status);
        }
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ClientException('Invalid JSON: ' . $e->getMessage(), $status, $raw);
        }
        if (!is_array($decoded)) {
            throw new ClientException('Expected JSON object', $status, $decoded);
        }
        $code = $decoded['code'] ?? null;
        if ($code !== 0 && $code !== '0') {
            throw new ClientException((string) ($decoded['msg'] ?? 'server error'), is_int($code) ? $code : 0, $decoded);
        }

        return $decoded;
    }
}
```

`CovertProperty::toCovertDeepProperty($payload, Response::class)` 按属性类型和 `#[ArrayList]` 把整份 JSON（含 `code`、`msg`、`data`）填回 Response 对象。嵌套 `$data` 递归填充；完整实现位于 `InterfaceApi\Support\CovertProperty`。

## 5. 扫描并生成 Client

命令在 `InterfaceApi` 仓库根目录执行：

```text
php InterfaceApi/bin/generate-client.php --service=ScheduleJob/App
```

不读取服务端仓库。输入是本包里带 `#[RouteGroup]` 的接口。

### 5.1 发现接口

1. 递归列出仓库下全部 `.php`，跳过 `Support/`、`Client/`、`vendor/`。
2. `token_get_all` 取出 `namespace` 与 `interface` 名，拼出 FQCN。
3. `class_exists` 对接口为 false，使用 `interface_exists($fqcn, true)` 加载。
4. 读类上的 `#[RouteGroup]`。没有该注解的接口视为普通 PHP 接口，不生成 Client。
5. 一个接口文件只定义一个接口。

### 5.2 解析每个方法

对 `ReflectionClass::getMethods()` 中本接口声明的 `public` 方法：

| 检查 | 失败时 |
|---|---|
| 方法上恰好一个 `#[Route]` | 报错并停止 |
| `method` 属于允许的动词 | 报错并停止 |
| `path` 非空，且以 `/` 开头 | 报错并停止 |
| 参数个数为 0 或 1 | 报错并停止 |
| 唯一参数的类型是 `BaseRequest` 子类（含自身） | 报错并停止 |
| 返回类型是 `BaseResponse` 子类，或 `void`；若标了流式注解则按第 4.6 节的返回类型 | 报错并停止 |
| `StreamResponse`、`ChunkedResponse`、`DownloadResponse` 至多一个 | 报错并停止 |
| 参数名、返回类型都能在本包加载 | 报错并停止 |

PHP 不会把接口方法上的注解复制到实现类。生成 Client 时反射接口方法。服务端不读取这些注解来注册路由。

最终路径：

```text
fullPath = '/' + trim(prefix, '/') + '/' + trim(path, '/')
```

`prefix` 为空时即为 `path`。同一接口内 `(method, fullPath)` 不能重复。不同接口的路径可以相同，只要 HTTP 方法不同；方法也相同则报错。

`GET`、`HEAD`、`DELETE`、`OPTIONS` 把 Request 的 `toDeepArray()` 放到 query。`POST`、`PUT`、`PATCH` 放到 JSON body。

### 5.3 引用检查

在写文件之前执行第 2 节的检查。任一契约文件引用了包外的类，整个命令失败，已有 Client 文件保持不动。

### 5.4 写出 Client

输出路径：把接口文件所在目录加上 `Client/{Client类名}.php`。

`AgentChatApiInterface` 生成：

```php
<?php

declare(strict_types=1);

namespace InterfaceApi\Swoolefy\Test\Module\Agent\Client;

use InterfaceApi\Support\BaseClientApi;
use InterfaceApi\Support\CovertProperty;
use InterfaceApi\Swoolefy\Test\Module\Agent\Request\ChatRequest;
use InterfaceApi\Swoolefy\Test\Module\Agent\Response\ChatResponse;

class AgentChatApi extends BaseClientApi
{
    protected string $serviceName = 'agent-service';

    public function chat(ChatRequest $request, array $options = []): ChatResponse
    {
        $requestDefaults = [];
        $requestDefaults['body'] = json_encode($request->toDeepArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $options = $this->mergeClientOptions($requestDefaults, $options);
        $response = $this->requestWithConnectRetry('POST', $this->uri('/v1/agent/chat'), $options);
        $result = $this->parseResponseByHeaders($response);

        return CovertProperty::toCovertDeepProperty($result, ChatResponse::class);
    }
}
```

生成规则：

- 类 `extends BaseClientApi`，不 `implements` 接口。接口只有一个业务参数；Client 额外有 `array $options = []`，用于单次请求的头、超时和重试次数。
- 方法名与接口方法名相同。
- `serviceName` 来自该 `{应用}` 的配置 `interface-api.json`：`{"Swoolefy/Test": {"serviceName": "agent-service"}}`。缺配置则停止。
- 返回类型为 `void` 且没有流式注解时，解析信封后 `return;`。
- 方法上有 `#[StreamResponse]` 时，请求头 `Accept` 为 `text/event-stream`，返回 `$this->parseResponseByHeaders($response, 'sse')`，PHP 返回类型为 `array`。
- `#[ChunkedResponse]` 返回 `parseResponseByHeaders($response, 'chunked')`，类型为 `string`。
- `#[DownloadResponse]` 返回 `parseResponseByHeaders($response, 'download')`，类型为 `array`。这三种都不调用 `CovertProperty`，也不校验业务 `code`。
- 无 Request 参数时不设置 `body` / `query`。
- Request 属性上的 `#[StringToInt]` 由调用方在组请求前把数字字符串写成 int。Response 属性上的 `#[IntToString]` 在 `CovertProperty` 填充后保持字符串，不转回 int。
- 接口方法上的 `#[ApiOperation]` 写入生成方法的文档注释：有 `summary` 时用 `summary`，否则用 `description`。
- 路径中的 `{id}` 替换为 Request 同名属性。属性不存在则停止。替换后的值做 `rawurlencode`。
- 文件头写入 `// @generated`。生成器只删除并重写带该标记的 `Client/*.php`。手写的契约文件不在写入范围内。

### 5.5 调用方

```php
$api = AgentChatApi::makeService(null, 'http://127.0.0.1:9501');
$response = $api->chat((new ChatRequest())->setPrompt('hello'));
$result = $response->getData();
```

`makeService()` 走 Nacos。`makeService(null, $baseUri)` 使用固定地址。传入自定义 Guzzle Client 时由调用方管理连接。

## 6. 服务端路由与 Controller

路由文件继续放在服务端，例如 `Test/Router/`。`Route::get/post/...` 与 `dispatch_route` 决定路径、动词和 Controller 方法，中间件与鉴权也留在这里。

```php
Route::post('/v1/agent/chat', [
    'dispatch_route' => [\Test\Module\Agent\Controller\AgentChatController::class, 'chat'],
]);
```

Controller 实现对应接口。签名与接口一致，从而必须使用 `InterfaceApi` 中的 Request、Response。

```php
class AgentChatController implements AgentChatApiInterface
{
    public function chat(ChatRequest $request): ChatResponse
    {
        // ...
    }
}
```

框架仍按路由文件分发。调用前按 `#[ValidationRule]` 把 HTTP 参数灌进 Request，再传入 Controller。返回 `BaseResponse` 时，框架取 `getData()`，再包上 `code`、`msg` 输出。

接口注解中的路径、动词应与路由文件中的那一条一致。生成 Client 不读取路由文件。

## 7. 失败即停的清单

生成或启动遇到下列情况即停止，并打印文件与符号：

- 契约引用了 `InterfaceApi\` 与 PHP 内建类以外的类
- 接口方法缺少 `#[Route]`，或 `method` 非法
- 参数不是 0/1 个，或类型不是 `BaseRequest` 子类
- 返回类型与 `#[Route]`、流式注解不匹配（JSON 接口必须是 `BaseResponse` 子类或 `void`；SSE / 下载必须是 `array`；分块流必须是 `string`）
- 同一方法同时标了多种流式注解
- 路径占位符在 Request 上没有同名属性
- 接口上同一 `method + fullPath` 出现两次
- `{应用}` 缺少 `serviceName`
