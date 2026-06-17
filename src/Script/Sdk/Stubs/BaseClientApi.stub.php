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
