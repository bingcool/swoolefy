<?php

declare(strict_types=1);

namespace Swoolefy\Script\Sdk;

/**
 * Minimal framework stubs for generated SDK DTOs (no Swoole / Http runtime).
 */
final class SdkSupportWriter
{
    public function __construct(
        private string $supportDir,
        private string $supportNamespace,
        private string $nacosServiceName = 'my-service',
    ) {
    }

    private function ns(string $template): string
    {
        return str_replace('__SDK_SUPPORT_NAMESPACE__', $this->supportNamespace, $template);
    }

    public function writeAll(): void
    {
        if (!is_dir($this->supportDir)) {
            mkdir($this->supportDir, 0755, true);
        }

        file_put_contents($this->supportDir . '/SdkArrayDto.php', $this->arrayDto());
        file_put_contents($this->supportDir . '/SdkAbstractDto.php', $this->abstractDto());
        file_put_contents($this->supportDir . '/SdkBaseRequest.php', $this->baseRequest());
        file_put_contents($this->supportDir . '/SdkBasePageRequest.php', $this->basePageRequest());
        file_put_contents($this->supportDir . '/SdkBaseResponse.php', $this->baseResponse());
        file_put_contents($this->supportDir . '/SdkBasePageResultResponse.php', $this->basePageResultResponse());
        file_put_contents($this->supportDir . '/SdkClientException.php', $this->exception());
        file_put_contents($this->supportDir . '/BaseClientApi.php', $this->baseClientApi());
        file_put_contents($this->supportDir . '/SdkNacosServiceDiscovery.php', $this->sdkNacosServiceDiscovery());
        file_put_contents($this->supportDir . '/ApiProperty.php', $this->apiProperty());
        file_put_contents($this->supportDir . '/ArrayList.php', $this->arrayList());
        file_put_contents($this->supportDir . '/SdkCovertProperty.php', $this->covertProperty());
        file_put_contents($this->supportDir . '/SdkArrayInterface.php', $this->arrayInterface());
        file_put_contents($this->supportDir . '/SdkArrayInteger.php', $this->arrayInteger());
        file_put_contents($this->supportDir . '/SdkArrayString.php', $this->arrayString());
        file_put_contents($this->supportDir . '/StringToInt.php', $this->stringToInt());
        file_put_contents($this->supportDir . '/IntToString.php', $this->intToString());
    }

    private function apiProperty(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Attribute;

/**
 * SDK copy: documents a property (or method) for client-side hints; no framework dependency.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
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

PHP);
    }

    private function arrayList(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Attribute;

/**
 * SDK copy: marks list properties and their item DTO class.
 */
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

PHP);
    }

    private function covertProperty(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use __SDK_SUPPORT_NAMESPACE__\ArrayList;
use __SDK_SUPPORT_NAMESPACE__\SdkArrayInteger;
use __SDK_SUPPORT_NAMESPACE__\SdkArrayString;
use __SDK_SUPPORT_NAMESPACE__\SdkArrayInterface;

final class SdkCovertProperty
{
    public static function toCovertDeepProperty(mixed $data, String $tagetClass): mixed
    {
        if (!class_exists($tagetClass)) {
            throw new \InvalidArgumentException('Target class does not exist: ' . $tagetClass);
        }

        $data = self::normalizeSourceData($data);

        // API 响应中的数组 -> SdkArrayInteger / SdkArrayString
        if (is_array($data) && (is_a($tagetClass, SdkArrayInteger::class, true) 
            || is_a($tagetClass, SdkArrayString::class, true))) 
        {
            return new $tagetClass($data);
        }

        $object = self::newObject($tagetClass);
        if (!is_array($data)) {
            if (method_exists($object, 'setData')) {
                $object->setData($data);
            }

            return $object;
        }

        $filled = self::fillObject($object, $data);
        if (!$filled && method_exists($object, 'setData')) {
            $object->setData($data);
        }

        return $object;
    }

    private static function fillObject(object $object, array $data): bool
    {
        $filled = false;
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $property = self::reflectionPropertyForDeclaredField($object, (string) $key);
            if ($property === null || $property->isReadOnly()) {
                continue;
            }

            $property->setAccessible(true);
            $convertedValue = self::valueForProperty($property, $value);
            $property->setValue($object, $convertedValue);
            $filled = true;
        }

        return $filled;
    }

    private static function valueForProperty(ReflectionProperty $property, mixed $value): mixed
    {
        $value = self::normalizeSourceData($value);
        
        // 检查是否有 ArrayList 注解
        $itemClass = self::arrayListItemClass($property);
        if ($itemClass !== null && is_array($value)) {
            // 对数组中的每个元素进行递归转换
            $convertedItems = [];
            foreach ($value as $key => $item) {
                $convertedItems[$key] = self::toCovertDeepProperty($item, $itemClass);
            }
            return $convertedItems;
        }

        $class = self::propertyObjectClass($property);
        if ($class !== null && $value !== null) {
            return self::toCovertDeepProperty($value, $class);
        }

        return $value;
    }

    private static function arrayListItemClass(ReflectionProperty $property): ?string
    {
        foreach ($property->getAttributes(ArrayList::class) as $attribute) {
            $arrayList = $attribute->newInstance();
            $itemClass = $arrayList->getItemClass();
            if ($itemClass === '') {
                continue;
            }
            if (!class_exists($itemClass)) {
                throw new \InvalidArgumentException('ArrayList item class does not exist: ' . $itemClass);
            }

            return $itemClass;
        }

        return null;
    }

    private static function propertyObjectClass(ReflectionProperty $property): ?string
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $class = $type->getName();
        if (!class_exists($class)) {
            return null;
        }

        return $class;
    }

    private static function normalizeSourceData(mixed $data): mixed
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::normalizeSourceData($value);
            }

            return $data;
        }

        if (is_object($data) && method_exists($data, 'toDeepArray')) {
            return self::normalizeSourceData($data->toDeepArray());
        }

        if (is_object($data) && method_exists($data, 'toArray')) {
            return self::normalizeSourceData($data->toArray());
        }

        return $data;
    }

    private static function newObject(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function reflectionPropertyForDeclaredField(object $object, string $name): ?ReflectionProperty
    {
        if ($name === '') {
            return null;
        }

        for (
            $class = new ReflectionClass($object);
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


PHP);
    }

    private function stringToInt(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Attribute;

/**
 * SDK copy: marks request integer fields that may be supplied as numeric strings.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class StringToInt
{
}

PHP);
    }

    private function intToString(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Attribute;

/**
 * SDK copy: marks response integer fields that should be treated as strings by clients.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IntToString
{
}

PHP);
    }

    private function baseClientApi(): string
    {
        $serviceName = addcslashes($this->nacosServiceName, "'\\");

        return $this->ns(str_replace(
            '__SDK_NACOS_SERVICE_NAME__',
            $serviceName,
            <<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Swoolefy\Support\HeaderPropagation\HeaderPropagator;

/**
 * SDK HTTP 客户端基类：Nacos 服务发现、Connect 重试、JSON 解析与业务码校验。
 *
 * 构造模式：
 * - makeService()                         → Nacos 发现 base_uri
 * - makeService(null, 'http://host:port') → 固定地址
 * - makeService($customClient)            → 完全自定义 Guzzle Client
 */
abstract class BaseClientApi
{
    /**
     * 本应用在 Nacos 注册的服务名（gen:sdk 时由 NacosServiceRegisterConfig 注入）。
     */
    protected string $serviceName = '__SDK_NACOS_SERVICE_NAME__';

    /** Guzzle base_uri，末尾带 / */
    protected string $baseUri = '';

    /** 当前 Nacos 发现选中实例的 metadata；固定地址/外部 Client 模式为空 */
    protected array $nacosInstanceMetadata = [];

    protected ClientInterface $httpClient;

    /** 未传入 $httpClient 且未指定 $baseUri 时，通过 Nacos 发现节点，RequestException 时可换节点/退避重试 */
    protected bool $nacosDiscoveryEnabled = false;

    /** 固定地址重试退避（毫秒）：第 1/2/3 次重试分别等待 200ms / 500ms / 1s */
    private const CONNECT_RETRY_BACKOFF_MS = [200, 500, 1000];

    /**
     * @param ClientInterface|null $httpClient 传入则使用外部 Client，不走 Nacos 发现
     * @param string $baseUri 固定 base_uri；与 $httpClient=null 组合时可跳过 Nacos
     */
    public function __construct(
        ?ClientInterface $httpClient = null,
        string $baseUri = '',
    ) {
        if (null === $httpClient) {
            if ('' !== $baseUri) {
                // 模式 B：固定地址，重试时退避但不换节点
                $this->baseUri = rtrim($baseUri, '/') . '/';
                $this->nacosDiscoveryEnabled = false;
            } else {
                // 模式 A：Nacos 发现，重试时 choose 新节点
                $resolved = SdkNacosServiceDiscovery::resolveInstance($this->serviceName);
                $this->baseUri = $resolved['base_uri'];
                $this->nacosInstanceMetadata = $resolved['metadata'];
                $this->nacosDiscoveryEnabled = true;
            }
            $this->httpClient = $this->createHttpClient();
        } else {
            // 模式 C：外部注入 Client
            $this->httpClient = $httpClient;
            if ('' !== $baseUri) {
                $this->baseUri = rtrim($baseUri, '/') . '/';
            }
        }
    }

    /** 快捷构造，等价于 new static(...) */
    public static function makeService(?ClientInterface $httpClient = null, string $baseUri = ''): static
    {
        return new static($httpClient, $baseUri);
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function getHttpClient(): ClientInterface
    {
        return $this->httpClient;
    }

    /**
     * 获取当前 SDK 客户端选中 Nacos 实例的 metadata。
     *
     * @return array<string, mixed>
     */
    public function getNacosInstanceMetadata(): array
    {
        return $this->nacosInstanceMetadata;
    }

    public function getNacosInstanceMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->nacosInstanceMetadata[$key] ?? $default;
    }

    /** 拼接相对路径与 base_uri */
    protected function uri(string $path): string
    {
        if ('' !== $this->baseUri) {
            return rtrim($this->baseUri, '/') . '/' . ltrim($path, '/');
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * 发起 HTTP 请求；RequestException（含 ConnectException、4xx/5xx）时按策略重试。
     *
     * 重试次数优先级：$retryNum > $options['connect_retry_num'] > 方法默认值。
     * - GET/HEAD/PUT/DELETE/OPTIONS 默认 1 次；POST/PATCH 默认 0（需业务显式开启，保证幂等）
     * - 最大 3 次
     * - Nacos：换节点后立即重试；固定地址：退避后重试同一节点
     *
     * @param int|null $retryNum 显式重试次数
     */
    protected function requestWithConnectRetry(
        string $method,
        string $uri,
        array $options = [],
        ?int $retryNum = null,
    ): ResponseInterface {
        // connect_retry_num 为 SDK 专用项，须剥离后再传给 Guzzle
        if (null === $retryNum && array_key_exists('connect_retry_num', $options)) {
            $retryNum = (int) $options['connect_retry_num'];
        }
        unset($options['connect_retry_num']);

        $maxRetries = self::normalizeConnectRetryNum($retryNum ?? self::defaultConnectRetryNumForMethod($method));
        $retriesLeft = $maxRetries;
        $attempt = 0;

        while (true) {
            try {
                return $this->httpClient->request($method, $uri, $options);
            } catch (RequestException $e) {
                if ($retriesLeft <= 0) {
                    throw $e;
                }

                $attempt++;
                [$failedHost, $failedPort] = $this->parseRequestHostPort($e->getRequest());

                if ($this->nacosDiscoveryEnabled) {
                    // Nacos：重新 choose 实例，无退避
                    $this->reconnectViaNacosDiscovery();
                    [$nextHost, $nextPort] = $this->parseBaseUriHostPort();
                } else {
                    // 固定地址：指数退避后重试同一 base_uri
                    $this->waitConnectRetryBackoff($attempt);
                    [$nextHost, $nextPort] = [$failedHost, $failedPort];
                }

                $this->logConnectRetry(
                    $e,
                    $method,
                    $uri,
                    $attempt,
                    $maxRetries,
                    $failedHost,
                    $failedPort,
                    $nextHost,
                    $nextPort,
                );
                $retriesLeft--;
            }
        }
    }

    /** Nacos 模式下重新发现 base_uri 并重建 Guzzle Client */
    protected function reconnectViaNacosDiscovery(): void
    {
        $resolved = SdkNacosServiceDiscovery::resolveInstance($this->serviceName);
        $this->baseUri = $resolved['base_uri'];
        $this->nacosInstanceMetadata = $resolved['metadata'];
        $this->httpClient = $this->createHttpClient();
    }

    /**
     * 固定地址重试退避等待。
     *
     * @param int $attempt 当前为第几次重试（从 1 开始）
     */
    protected function waitConnectRetryBackoff(int $attempt): void
    {
        $index = max(0, min(count(self::CONNECT_RETRY_BACKOFF_MS) - 1, $attempt - 1));
        usleep(self::CONNECT_RETRY_BACKOFF_MS[$index] * 1000);
    }

    /**
     * 按 HTTP 方法返回默认重试次数。
     * 幂等读/删/改默认 1；POST 等写操作默认 0。
     */
    private static function defaultConnectRetryNumForMethod(string $method): int
    {
        return match (strtoupper($method)) {
            'GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS' => 1,
            default => 0,
        };
    }

    /**
     * 记录重试日志（guzzle_curl 通道），含失败/下一跳 host:port 与异常信息。
     */
    protected function logConnectRetry(
        RequestException $e,
        string $method,
        string $uri,
        int $attempt,
        int $maxRetries,
        string $failedHost,
        int $failedPort,
        string $nextHost,
        int $nextPort,
    ): void {
        $logger = \Swoolefy\Library\CurlProxy\CurlProxyHandler::buildLogChannel();
        if (null === $logger) {
            return;
        }

        $mode = $this->nacosDiscoveryEnabled ? 'nacos' : 'client';
        $logger->error(sprintf(
            '【sdk-connect-retry】 model=%s service=%s method=%s uri=%s attempt=%d/%d failed_host=%s failed_port=%d next_host=%s next_port=%d error=%s',
            $mode,
            $this->serviceName,
            $method,
            $uri,
            $attempt,
            $maxRetries,
            $failedHost,
            $failedPort,
            $nextHost,
            $nextPort,
            $e->getMessage(),
        ));
    }

    /**
     * 从 Guzzle Request 解析 host:port（缺省端口按 scheme 推断 80/443）。
     *
     * @return array{0: string, 1: int}
     */
    protected function parseRequestHostPort(\Psr\Http\Message\RequestInterface $request): array
    {
        $host = $request->getUri()->getHost();
        $port = $request->getUri()->getPort();
        if (null === $port || 0 === $port) {
            $port = 'https' === $request->getUri()->getScheme() ? 443 : 80;
        }

        return [$host, (int) $port];
    }

    /**
     * 从 base_uri 解析 host:port。
     *
     * @return array{0: string, 1: int}
     */
    protected function parseBaseUriHostPort(?string $baseUri = null): array
    {
        $baseUri ??= $this->baseUri;
        $parts = parse_url($baseUri);
        if (!is_array($parts)) {
            return ['', 0];
        }

        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? 0);
        if ($port <= 0) {
            $port = ('https' === ($parts['scheme'] ?? 'http')) ? 443 : 80;
        }

        return [$host, $port];
    }

    /** 限制重试次数在 0~3 */
    private static function normalizeConnectRetryNum(int $retryNum): int
    {
        if ($retryNum < 0) {
            return 0;
        }

        return min(3, $retryNum);
    }

    /** 创建 Guzzle Client；http_errors=true 以便 RequestException 触发重试逻辑 */
    protected function createHttpClient(): Client
    {
        return new Client([
            'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            'base_uri' => $this->baseUri,
            'http_errors' => true,
        ]);
    }

    /** 仅校验 HTTP 2xx；流式接口不做业务 code 校验 */
    protected function assertHttpOk(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status < \Swoole\Http\Status::OK || $status >= \Swoole\Http\Status::MULTIPLE_CHOICES) {
            throw new SdkClientException('Unexpected HTTP status: ' . $status, $status);
        }
    }

    /**
     * 解析 JSON 响应体为数组，非 2xx 或非法 JSON 抛 SdkClientException。
     *
     * @return array<string, mixed>
     */
    protected function parseJsonResponse(ResponseInterface $response): array
    {
        $this->assertHttpOk($response);
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [];
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SdkClientException('Invalid JSON: ' . $e->getMessage(), $response->getStatusCode(), $raw);
        }
        if (!is_array($decoded)) {
            throw new SdkClientException('Expected JSON object', $response->getStatusCode(), $decoded);
        }

        return $decoded;
    }

    /** 校验业务 code 字段，非 0 抛 SdkClientException */
    protected function assertBusinessOk(array $payload): void
    {
        $code = $payload['code'] ?? null;
        if ($code !== 0 && $code !== '0') {
            $msg = (string) ($payload['msg'] ?? 'server error');
            $status = is_int($code) ? $code : 0;
            throw new SdkClientException($msg, $status, $payload);
        }
    }

    /**
     * JSON client defaults + per-request keys (body, query, …) + caller overrides; headers are deep-merged.
     *
     * SDK 专用选项（不会传给 Guzzle）：connect_retry_num（0~3，POST 默认 0，GET/PUT/DELETE 等默认 1）。
     *
     * @param array<string, mixed> $requestDefaults
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function mergeClientOptions(array $requestDefaults, array $options = []): array
    {
        // GuzzleHttp\Exception\RequestException 或其子类 GuzzleHttp\Exception\ConnectException。
        // 你可以使用 try-catch 来捕获它们，但这有一个重要前提：请求的 http_errors 选项必须被设置为 true（这也是该选项的默认行为）
        $defaults = [
            'http_errors' => true,
            'headers' => array_merge(
                HeaderPropagator::outgoingHeaders(),
                ['Content-Type' => 'application/json'],
            ),
            'connect_timeout' => 30.0,
            'timeout' => 120.0,
        ];
        $defaults = array_merge($defaults, $requestDefaults);
        $merged = array_merge($defaults, $options);
        if (isset($defaults['headers'], $options['headers']) && is_array($defaults['headers']) && is_array($options['headers'])) {
            $merged['headers'] = array_merge($defaults['headers'], $options['headers']);
        }

        return $merged;
    }

    /**
     * 流式请求默认选项：不设置 Content-Type: application/json。
     *
     * 用于 SSE / Chunked 等接口；普通 JSON API 请用 mergeClientOptions()。
     *
     * @param array<string, mixed> $requestDefaults
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function mergeStreamClientOptions(array $requestDefaults, array $options = []): array
    {
        $defaults = [
            'http_errors' => true,
            'headers' => HeaderPropagator::outgoingHeaders(),
            'connect_timeout' => 30.0,
            'timeout' => 120.0,
        ];
        $defaults = array_merge($defaults, $requestDefaults);
        $merged = array_merge($defaults, $options);
        if (isset($defaults['headers'], $options['headers']) && is_array($defaults['headers']) && is_array($options['headers'])) {
            $merged['headers'] = array_merge($defaults['headers'], $options['headers']);
        }

        return $merged;
    }

    /**
     * 按注解或响应头选择解析器（优先生效 $forceType）。
     *
     * 无注解时根据响应头识别：
     * - text/event-stream → SSE
     * - Content-Disposition: attachment/inline → 文件下载
     * - application/json → JSON 信封 + 业务 code 校验
     * - application/xml、text/xml、*+xml → XML 解析为数组
     * - 其他 Content-Type → 原始 body（分块流等）
     *
     * @param 'sse'|'chunked'|'download'|null $forceType
     * @return array<string, mixed>|string|list<array{event: string, id: ?string, data: mixed}>
     */
    protected function parseResponseByHeaders(ResponseInterface $response, ?string $forceType = null): mixed
    {
        $type = $forceType ?? $this->detectResponseTypeFromHeaders($response);

        return match ($type) {
            'sse' => $this->parseSseResponse($response),
            'download' => $this->parseDownloadResponse($response),
            'chunked' => $this->parseStreamResponse($response),
            'xml' => $this->parseXmlResponse($response),
            default => $this->parseJsonResponseWithBusinessOk($response),
        };
    }

    /**
     * 根据响应头推断响应类型。
     *
     * @return 'sse'|'chunked'|'download'|'xml'|'json'
     */
    protected function detectResponseTypeFromHeaders(ResponseInterface $response): string
    {
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        if ($contentType === 'text/event-stream') {
            return 'sse';
        }

        $disposition = $response->getHeaderLine('Content-Disposition');
        if ($disposition !== '') {
            $lower = strtolower(ltrim($disposition));
            if (str_starts_with($lower, 'attachment') || str_starts_with($lower, 'inline')) {
                return 'download';
            }
        }

        if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
            return 'json';
        }

        if ($this->isXmlContentType($contentType)) {
            return 'xml';
        }

        if ($contentType !== '') {
            return 'chunked';
        }

        return 'json';
    }

    protected function isXmlContentType(string $contentType): bool
    {
        if ($contentType === 'application/xml' || $contentType === 'text/xml') {
            return true;
        }

        return str_ends_with($contentType, '+xml');
    }

    /**
     * 解析 XML 响应体为关联数组。
     *
     * @return array<string, mixed>
     */
    protected function parseXmlResponse(ResponseInterface $response): array
    {
        $this->assertHttpOk($response);
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                $message = $errors !== [] ? trim($errors[0]->message) : 'syntax error';

                throw new SdkClientException('Invalid XML: ' . $message, $response->getStatusCode(), $raw);
            }

            $json = json_encode($xml, JSON_THROW_ON_ERROR);
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SdkClientException('Invalid XML: ' . $e->getMessage(), $response->getStatusCode(), $raw);
        } finally {
            libxml_use_internal_errors($previous);
            libxml_clear_errors();
        }

        if (!is_array($decoded)) {
            return ['value' => $decoded];
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseJsonResponseWithBusinessOk(ResponseInterface $response): array
    {
        $payload = $this->parseJsonResponse($response);
        $this->assertBusinessOk($payload);

        return $payload;
    }

    /** 分块流响应：返回完整原始 body，调用方自行按行/格式解析 */
    protected function parseStreamResponse(ResponseInterface $response): string
    {
        $this->assertHttpOk($response);

        return (string) $response->getBody();
    }

    /**
     * 文件下载响应：返回二进制内容与响应头中的文件名、MIME。
     *
     * @return array{content: string, filename: ?string, contentType: ?string}
     */
    protected function parseDownloadResponse(ResponseInterface $response): array
    {
        $this->assertHttpOk($response);

        $contentType = $response->getHeaderLine('Content-Type');

        return [
            'content' => (string) $response->getBody(),
            'filename' => $this->extractDownloadFilename($response->getHeaderLine('Content-Disposition')),
            'contentType' => $contentType !== '' ? $contentType : null,
        ];
    }

    /** 从 Content-Disposition 解析文件名（兼容 filename 与 filename*） */
    protected function extractDownloadFilename(string $contentDisposition): ?string
    {
        if ($contentDisposition === '') {
            return null;
        }
        if (preg_match("/filename\\*=UTF-8''([^;\\s]+)/i", $contentDisposition, $matches) === 1) {
            return rawurldecode($matches[1]);
        }
        if (preg_match('/filename="([^"]+)"/', $contentDisposition, $matches) === 1) {
            return $matches[1];
        }
        if (preg_match('/filename=([^;\\s]+)/', $contentDisposition, $matches) === 1) {
            return trim($matches[1], " \t\"'");
        }

        return null;
    }

    /**
     * SSE 响应：解析 text/event-stream 为事件列表。
     *
     * @return list<array{event: string, id: ?string, data: mixed}>
     */
    protected function parseSseResponse(ResponseInterface $response): array
    {
        $this->assertHttpOk($response);

        return $this->decodeSseEvents((string) $response->getBody());
    }

    /**
     * 按 SSE 规范解析原始文本（event / id / data 字段，空行分隔事件）。
     *
     * data 若为 JSON 字符串会自动 decode 为 array；非 JSON 保持 string。
     *
     * @return list<array{event: string, id: ?string, data: mixed}>
     */
    protected function decodeSseEvents(string $raw): array
    {
        $events = [];
        $current = ['event' => 'message', 'id' => null, 'data' => ''];

        $flush = function () use (&$events, &$current): void {
            if ($current['data'] === '' && $current['id'] === null && $current['event'] === 'message') {
                return;
            }

            $data = $current['data'];
            if ($data !== '') {
                try {
                    /** @var mixed $decoded */
                    $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    $data = $decoded;
                } catch (\JsonException) {
                    // data 非 JSON 时保留原始字符串
                }
            }

            $events[] = [
                'event' => $current['event'],
                'id' => $current['id'],
                'data' => $data !== '' ? $data : null,
            ];
            $current = ['event' => 'message', 'id' => null, 'data' => ''];
        };

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            if ($line === '') {
                $flush();
                continue;
            }
            if (str_starts_with($line, ':')) {
                // SSE comment 行（含心跳），忽略
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $field = substr($line, 0, $colon);
            $value = substr($line, $colon + 1);
            if ($value !== '' && $value[0] === ' ') {
                $value = substr($value, 1);
            }

            if ($field === 'event') {
                $current['event'] = $value;
            } elseif ($field === 'id') {
                $current['id'] = $value;
            } elseif ($field === 'data') {
                $current['data'] .= ($current['data'] === '' ? '' : "\n") . $value;
            }
        }

        $flush();

        return $events;
    }
}

PHP
        ));
    }

    private function sdkNacosServiceDiscovery(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Swoolefy\Exception\NacosDiscoveryException;
use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Support\Nacos\Discovery\DiscoveryClient;
use Swoolefy\Support\Nacos\Discovery\DiscoveryConfig;
use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Support\Nacos\NacosConst;
use Swoolefy\Support\Nacos\NacosLogger;

/**
 * SDK 侧 Nacos 服务发现薄封装，委托框架 DiscoveryClient。
 *
 * - 按 serviceName + groupName 缓存 DiscoveryClient，负载均衡状态可延续
 * - LOCAL_NACOS_SERVICE_AUTO_SWITCH=1 时，本地分组无实例则回退到 yaml service_register.group_name（只针对dev环境）
 * - nacos.yaml：常量 NACOS_FILE_PATH（未设置时 APP_PATH/nacos.yaml）
 * - application.yaml：常量 APP_PATH
 */
final class SdkNacosServiceDiscovery
{
    /** @var array<string, DiscoveryClient> */
    private static array $discoveryClients = [];

    /**
     * 发现可用实例并返回 Guzzle base_uri（末尾带 /）。
     *
     * @throws SdkClientException
     */
    public static function resolveBaseUri(string $serviceName): string
    {
        return self::resolveInstance($serviceName)['base_uri'];
    }

    /**
     * 发现可用实例，并返回 base_uri 与 metadata。
     *
     * @return array{base_uri: string, metadata: array<string, mixed>}
     *
     * @throws SdkClientException
     */
    public static function resolveInstance(string $serviceName): array
    {
        if ('' === trim($serviceName)) {
            throw new SdkClientException('Nacos service name is empty');
        }

        try {
            $discoveryConfig = DiscoveryConfig::load();
            $instance = self::chooseInstance($serviceName, $discoveryConfig);
            $useInnerExternalBaseUri = false;

            // 本地分组（如 bingcool）无实例时，回退到 yaml 中 定义的 部署分组（dev环境
            // 同时确保本地开发环境能够调通dev部署的环境，即本地开发环境能够访问dev环境各个服务注册到nacos的ip
            if (null === $instance && self::isLocalAutoSwitchEnabled()) {
                $fallbackGroup = self::resolveYamlRegisterGroupName();
                $currentGroup = $discoveryConfig->groupName;
                if ('' !== $fallbackGroup && $fallbackGroup !== $currentGroup) {
                    NacosLogger::get()->info(sprintf(
                        'nacos local auto switch: service=%s, group %s -> %s',
                        $serviceName,
                        $currentGroup,
                        $fallbackGroup,
                    ));
                    $instance = self::chooseInstance(
                        $serviceName,
                        self::discoveryConfigWithGroup($discoveryConfig, $fallbackGroup),
                    );
                    $useInnerExternalBaseUri = $instance instanceof ServiceInstance;

                    if (function_exists('fmtPrintNote')) {
                        if ($instance instanceof ServiceInstance) {
                            fmtPrintNote(sprintf(
                                "调用nacos注册的服务【%s】自动切换到其开发环境调用uri=%s",
                                $serviceName,
                                self::resolveBaseUriFromInstance($instance, $useInnerExternalBaseUri),
                            ));
                        } else {
                            fmtPrintNote(sprintf("调用nacos注册的服务【%s】自动切换到其开发环境, 无法获取其host+ip", $serviceName));
                        }
                    }
                }
            }
        } catch (NacosDiscoveryException $e) {
            throw new SdkClientException($e->getMessage());
        } catch (\Throwable $e) {
            throw new SdkClientException('Nacos discovery failed: ' . $e->getMessage());
        }

        if (!$instance instanceof ServiceInstance) {
            throw new SdkClientException(sprintf(
                'No available Nacos instance for service [%s]',
                $serviceName,
            ));
        }

        return [
            'base_uri' => self::resolveBaseUriFromInstance($instance, $useInnerExternalBaseUri),
            'metadata' => $instance->getMetadata(),
        ];
    }

    private static function resolveBaseUriFromInstance(ServiceInstance $instance, bool $useInnerExternalBaseUri): string
    {
        if ($useInnerExternalBaseUri) {
            $metadata = $instance->getMetadata();
            $innerExternalBaseUri = (string) ($metadata[NacosConst::METADATA_INNER_EXTERNAL_BASE_URI] ?? '');
            if ('' !== $innerExternalBaseUri) {
                return rtrim($innerExternalBaseUri, '/') . '/';
            }
        }

        return rtrim($instance->getUri('http'), '/') . '/';
    }

    private static function chooseInstance(string $serviceName, DiscoveryConfig $discoveryConfig): ?ServiceInstance
    {
        $client = self::getDiscoveryClient($serviceName, $discoveryConfig);
        $instance = $client->choose();
        if (!$instance instanceof ServiceInstance) {
            $instance = $client->refresh() !== [] ? $client->choose() : null;
        }

        return $instance;
    }

    private static function isLocalAutoSwitchEnabled(): bool
    {
        $env = getenv(NacosConst::ENV_LOCAL_NACOS_SERVICE_AUTO_SWITCH);
        if (false === $env || '' === $env) {
            return false;
        }

        return filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }

    /** application.yaml → nacos.service_register.group_name（yaml 优先，即 dev 部署分组） */
    private static function resolveYamlRegisterGroupName(): string
    {
        $section = ApplicationConfig::load()->nacosSection('service_register');

        return ApplicationConfig::pickString($section, 'group_name', NacosConst::ENV_SERVICE_GROUP_NAME, '');
    }

    private static function discoveryConfigWithGroup(DiscoveryConfig $base, string $groupName): DiscoveryConfig
    {
        return new DiscoveryConfig(
            $base->cacheTtl,
            $base->loadBalancer,
            $base->healthyOnly,
            $base->clusters,
            $groupName,
            $base->namespaceId,
        );
    }

    private static function getDiscoveryClient(string $serviceName, DiscoveryConfig $discoveryConfig): DiscoveryClient
    {
        $cacheKey = $serviceName . '@' . $discoveryConfig->groupName;
        if (isset(self::$discoveryClients[$cacheKey])) {
            return self::$discoveryClients[$cacheKey];
        }

        self::$discoveryClients[$cacheKey] = DiscoveryClient::create(
            $serviceName,
            NacosConfig::load(),
            $discoveryConfig,
        );

        return self::$discoveryClients[$cacheKey];
    }
}

PHP);
    }

    private function arrayDto(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ReflectionProperty;

/**
 * SDK copy of core DTO helpers (no framework deps).
 */
class SdkArrayDto extends \stdClass
{
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

    private function valueToDeepArray(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->valueToDeepArray($item);
            }

            return $value;
        }

        // SdkArrayInteger / SdkArrayString：序列化前转为纯数组
        if ($value instanceof SdkArrayInterface) {
            return $this->valueToDeepArray($value->toDeepArray());
        }

        if ($value instanceof self) {
            return $value->toDeepArray();
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->valueToDeepArray($value->toArray());
        }

        return $value;
    }

    public function copyProperty(array|self $data): void
    {
        $data = $data instanceof self ? $data->toArray() : $data;
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

    public function copyDeepProperty(array|self $data): void
    {
        $data = $data instanceof self ? $data->toArray() : $data;
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
            $property->setValue($this, $this->valueForDeepProperty($property, $value));
        }
    }

    private function valueForDeepProperty(ReflectionProperty $property, mixed $value): mixed
    {
        if ($value instanceof SdkArrayInterface) {
            return $value;
        }

        // copyDeepProperty：JSON 数组 -> SdkArrayInteger / SdkArrayString
        if (is_array($value)) {
            $arrayStructClass = $this->arrayStructClassFromProperty($property);
            if ($arrayStructClass !== null) {
                return new $arrayStructClass($value);
            }
        }

        if ($value instanceof self) {
            $value = $value->toArray();
        }

        if (!is_array($value)) {
            return $value;
        }

        if ($property->isInitialized($this)) {
            $currentValue = $property->getValue($this);
            if ($currentValue instanceof self) {
                $currentValue->copyDeepProperty($value);

                return $currentValue;
            }
        }

        $dto = $this->newDtoFromPropertyType($property);
        if ($dto === null) {
            return $value;
        }

        $dto->copyDeepProperty($value);

        return $dto;
    }

    /** 解析属性类型是否为 SdkArrayInteger / SdkArrayString 等集合类 */
    private function arrayStructClassFromProperty(ReflectionProperty $property): ?string
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();
        if (!is_a($className, SdkArrayInterface::class, true)) {
            return null;
        }

        return $className;
    }

    private function newDtoFromPropertyType(ReflectionProperty $property): ?self
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();
        if (!is_a($className, self::class, true)) {
            return null;
        }

        $class = new \ReflectionClass($className);
        if (!$class->isInstantiable()) {
            return null;
        }

        $constructor = $class->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        return $class->newInstance();
    }

    private function reflectionPropertyForDeclaredField(string $name): ?ReflectionProperty
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

PHP);
    }

    private function abstractDto(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

/**
 * SDK copy of core DTO base (no framework deps).
 */
class SdkAbstractDto extends SdkArrayDto
{

    public function __set(string $name, $value): void
    {
        $this->$name = $value;
    }

    public function __get(string $name)
    {
        return $this->$name ?? null;
    }
}

PHP);
    }

    private function baseRequest(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

class SdkBaseRequest extends SdkArrayDto
{
    public function getRequestInput(): never
    {
        throw new \BadMethodCallException('SDK client has no RequestInput.');
    }
}

PHP);
    }

    private function basePageRequest(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

class SdkBasePageRequest extends SdkBaseRequest
{
    /**
     * @var int
     * #[ValidationRule(
     *   rule: 'required|int',
     *   message: [
     *       'required' => 'page is required',
     *       'int' => 'page must be int'
     *   ]
     * )]
     */
    #[ApiProperty(
        description: 'page页码'
    )]
    protected int $page = 1;

    /**
     * @var int
     * #[ValidationRule(
     *   rule: 'required|int',
     *   message: [
     *       'required' => 'pageSize is required',
     *       'int' => 'pageSize must be int'
     *   ]
     * )]
     */
    #[ApiProperty(
        description: 'pageSize每页数量'
    )]
    protected int $pageSize = 10;

    public function setPage(int $page): static
    {
        $this->page = $page;
        return $this;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = $pageSize;
        return $this;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }
}

PHP);
    }

    private function baseResponse(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

class SdkBaseResponse extends SdkArrayDto
{
    protected int $code = 0;

    protected string $msg = 'success';
    
    protected string $trace_id = '';
        
    public function setCode(int $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function setMsg(string $msg): static
    {
        $this->message = $msg;

        return $this;
    }

    public function getMsg(): string
    {
        return $this->message;
    }
    
    public function getTraceId(): string
    {
        return $this->trace_id;
    }

    public function setData(mixed $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}

PHP);
    }

    private function basePageResultResponse(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

/**
 * SDK copy: base page result response for pagination.
 * Extend this class for paginated list responses.
 */
class SdkBasePageResultResponse extends SdkBaseResponse
{
}

PHP);
    }

    private function exception(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

class SdkClientException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $statusCode = 0,
        private mixed $payload = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }
}

PHP);
    }

    private function arrayInterface(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

/**
 * SDK copy: typed array collections (ArrayInteger, ArrayString, …).
 */
interface SdkArrayInterface
{
    public function toArray(): array;

    public function toDeepArray(): array;
}

PHP);
    }

    private function arrayInteger(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * SDK copy: int[] collection (mirrors Swoolefy\DataStruct\ArrayInteger).
 */
class SdkArrayInteger implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, SdkArrayInterface
{
    /** @var int[] */
    protected array $items = [];

    public function __construct(mixed $items = [])
    {
        $this->items = $this->convertToIntegerArray($items);
    }

    public static function make(mixed $items = []): static
    {
        return new static($items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function add(int $value): static
    {
        $this->items[] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->items;
    }

    public function toDeepArray(): array
    {
        return $this->items;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function merge(mixed $items): static
    {
        return new static(array_merge($this->items, $this->convertToIntegerArray($items)));
    }

    public function distinct(): static
    {
        return new static(array_values(array_unique($this->items, SORT_NUMERIC)));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_values(array_filter($this->items, $callback)));
        }

        return new static(array_values(array_filter($this->items)));
    }

    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    public function first(): ?int
    {
        return $this->items === [] ? null : $this->items[array_key_first($this->items)];
    }

    public function last(): ?int
    {
        return $this->items === [] ? null : $this->items[array_key_last($this->items)];
    }

    public function count(): int
    {
        return count($this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset): int
    {
        return $this->items[$offset];
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException('SdkArrayInteger only accepts integer values');
        }
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * @return int[]
     */
    protected function convertToIntegerArray(mixed $items): array
    {
        if ($items instanceof self) {
            return $items->all();
        }

        $items = (array) $items;
        foreach ($items as $key => $value) {
            if (!is_int($value)) {
                throw new \InvalidArgumentException(
                    "SdkArrayInteger only accepts integer values. Invalid value at key '{$key}': " . gettype($value)
                );
            }
        }

        return $items;
    }
}

PHP);
    }

    private function arrayString(): string
    {
        return $this->ns(<<<'PHP'
<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * SDK copy: string[] collection (mirrors Swoolefy\DataStruct\ArrayString).
 */
class SdkArrayString implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, SdkArrayInterface
{
    /** @var string[] */
    protected array $items = [];

    public function __construct(mixed $items = [])
    {
        $this->items = $this->convertToStringArray($items);
    }

    public static function make(mixed $items = []): static
    {
        return new static($items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function add(string $value): static
    {
        $this->items[] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->items;
    }

    public function toDeepArray(): array
    {
        return $this->items;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function merge(mixed $items): static
    {
        return new static(array_merge($this->items, $this->convertToStringArray($items)));
    }

    public function distinct(): static
    {
        return new static(array_values(array_unique($this->items, SORT_STRING)));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_values(array_filter($this->items, $callback)));
        }

        return new static(array_values(array_filter($this->items)));
    }

    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    public function first(): ?string
    {
        return $this->items === [] ? null : $this->items[array_key_first($this->items)];
    }

    public function last(): ?string
    {
        return $this->items === [] ? null : $this->items[array_key_last($this->items)];
    }

    public function count(): int
    {
        return count($this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset): string
    {
        return $this->items[$offset];
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('SdkArrayString only accepts string values');
        }
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * @return string[]
     */
    protected function convertToStringArray(mixed $items): array
    {
        if ($items instanceof self) {
            return $items->all();
        }

        $items = (array) $items;
        foreach ($items as $key => $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    "SdkArrayString only accepts string values. Invalid value at key '{$key}': " . gettype($value)
                );
            }
        }

        return $items;
    }
}

PHP);
    }
}
