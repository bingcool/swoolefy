# OpenTelemetry HTTP 最小采集

控制 HTTP 请求 Span 的**是否采集**与**采集内容**（脱敏、长度、body）。

## 开关语义

| 全局 `OTEL_PHP_AUTOLOAD_ENABLED` | 路由 `enableOpenTelemetry` | 结果 |
|---|---|---|
| false | * | 不采集 |
| true | 未设置 / true | 采集 |
| true | false | 不采集该路由 |

## 路由用法

```php
use Swoolefy\Http\Route;

// 默认采集（全局开启时）
Route::post('/api/order', [
    'dispatch_route' => [\App\Controller\OrderController::class, 'create'],
]);

// 关闭该路由采集（如登录、含凭证回调）
Route::post('/api/login', [
    'dispatch_route' => [\App\Controller\AuthController::class, 'login'],
])->enableOpenTelemetry(false);
```

## 配置（Config/otel.php）

模版：`src/Stubs/otel.conf.stub.php`。

| 配置 / 环境变量 | 默认 | 说明 |
|---|---|---|
| `sanitize_enabled` / `OTEL_ATTRIBUTE_SANITIZE_ENABLED` | true | Authorization、Cookie、Set-Cookie、Token、密码和凭证类字段强制脱敏 |
| `attribute_max_length` / `OTEL_ATTRIBUTE_MAX_LENGTH` | 不限制 | 所有 attribute 最大长度；超出截断并追加 `...[TRUNCATED]` |
| `collect_request_body` / `OTEL_COLLECT_REQUEST_BODY` | true | 是否采集 request body |

`.env` 示例：

```env
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_ATTRIBUTE_SANITIZE_ENABLED=true
# 不设或空 = 不限制长度
# OTEL_ATTRIBUTE_MAX_LENGTH=2048
OTEL_COLLECT_REQUEST_BODY=true
```

## 相关类

| 类 | 职责 |
|---|---|
| `OpenTelemetryConfig` | 读取 Config/otel.php |
| `OpenTelemetryAttributeSanitizer` | 敏感字段脱敏 + 长度截断 |
| `OpenTelemetryHttpCollector` | 采集决策与 attribute 组装 |
| `HttpAppServer::startOpenTelemetry` | 创建 Span 并写入 attributes |
| `RouteOption::enableOpenTelemetry` | 路由级开关 |
