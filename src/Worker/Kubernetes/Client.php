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

namespace Swoolefy\Worker\Kubernetes;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 基于 Guzzle 的 Kubernetes API 客户端。
 *
 * ## 两种凭证来源
 *
 * 1. **集群内（推荐）**：自动读取 `KUBERNETES_SERVICE_HOST/PORT` 与
 *    `/var/run/secrets/kubernetes.io/serviceaccount/` 下的 token / ca.crt。
 *    projected token 会轮换，因此 token 按 TTL 重读。
 * 2. **集群外**：显式配 `K8S_API_SERVER` + `K8S_TOKEN`（+ 可选 `K8S_CA_CERT_FILE`）。
 *
 * Client 内部不自动重试、不缓存 Deployment。Job 是否成功由 Cron 层判定。
 */
class Client implements ClientInterface
{
    private const SA_DIR = '/var/run/secrets/kubernetes.io/serviceaccount';

    private const TOKEN_TTL_SECONDS = 60;

    private const ERROR_BODY_MAX = 500;

    private string $token = '';

    private int $tokenLoadedAt = 0;

    public function __construct(
        private readonly string $apiServer,
        private readonly string $tokenFile = '',
        private readonly string $staticToken = '',
        private readonly string $caCertFile = '',
        private readonly bool $verifyTls = true,
        private readonly int $timeout = 15,
    ) {
    }

    /**
     * @throws ApiException
     */
    public static function fromEnv(): self
    {
        $apiServer = rtrim(trim((string) (getenv('K8S_API_SERVER') ?: '')), '/');
        $staticToken = trim((string) (getenv('K8S_TOKEN') ?: ''));
        $caCertFile = trim((string) (getenv('K8S_CA_CERT_FILE') ?: ''));
        $tokenFile = '';

        if ($apiServer === '') {
            $host = (string) (getenv('KUBERNETES_SERVICE_HOST') ?: '');
            $port = (string) (getenv('KUBERNETES_SERVICE_PORT') ?: '443');
            if ($host === '') {
                throw new ApiException(
                    '未配置 Kubernetes 凭证：既没有 K8S_API_SERVER，也不在集群内（无 KUBERNETES_SERVICE_HOST）'
                );
            }
            $apiServer = 'https://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
            $tokenFile = self::SA_DIR . '/token';
            if ($caCertFile === '') {
                $caCertFile = self::SA_DIR . '/ca.crt';
            }
        }

        $verifyEnv = strtolower(trim((string) (getenv('K8S_VERIFY_TLS') ?: '')));
        $verifyTls = !in_array($verifyEnv, ['0', 'false', 'off', 'no'], true);
        $timeout = (int) (getenv('K8S_API_TIMEOUT') ?: 15);

        return new self(
            apiServer: $apiServer,
            tokenFile: $tokenFile,
            staticToken: $staticToken,
            caCertFile: is_file($caCertFile) ? $caCertFile : '',
            verifyTls: $verifyTls,
            timeout: $timeout > 0 ? $timeout : 15,
        );
    }

    public static function inClusterNamespace(): string
    {
        $file = self::SA_DIR . '/namespace';

        return is_readable($file) ? trim((string) file_get_contents($file)) : '';
    }

    public function getDeployment(string $namespace, string $name): array
    {
        return $this->request('GET', sprintf(
            '/apis/apps/v1/namespaces/%s/deployments/%s',
            rawurlencode($namespace),
            rawurlencode($name),
        ));
    }

    public function createJob(string $namespace, array $job): array
    {
        return $this->request(
            'POST',
            sprintf('/apis/batch/v1/namespaces/%s/jobs', rawurlencode($namespace)),
            body: $job,
        );
    }

    public function getJob(string $namespace, string $name): array
    {
        return $this->request('GET', sprintf(
            '/apis/batch/v1/namespaces/%s/jobs/%s',
            rawurlencode($namespace),
            rawurlencode($name),
        ));
    }

    public function deleteJob(string $namespace, string $name): bool
    {
        try {
            $this->request('DELETE', sprintf(
                '/apis/batch/v1/namespaces/%s/jobs/%s?propagationPolicy=Background',
                rawurlencode($namespace),
                rawurlencode($name),
            ));

            return true;
        } catch (ApiException $e) {
            if ($e->isNotFound()) {
                return true;
            }
            throw $e;
        }
    }

    public function listJobs(string $namespace, string $labelSelector): array
    {
        $response = $this->request('GET', sprintf(
            '/apis/batch/v1/namespaces/%s/jobs?labelSelector=%s',
            rawurlencode($namespace),
            rawurlencode($labelSelector),
        ));
        $items = $response['items'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    public function listPods(string $namespace, string $labelSelector): array
    {
        $response = $this->request('GET', sprintf(
            '/api/v1/namespaces/%s/pods?labelSelector=%s',
            rawurlencode($namespace),
            rawurlencode($labelSelector),
        ));
        $items = $response['items'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    public function getPodLogs(string $namespace, string $pod, string $container = '', int $tailLines = 50): string
    {
        $query = [
            'tailLines' => (string) max(1, $tailLines),
            'timestamps' => 'false',
        ];
        if ($container !== '') {
            $query['container'] = $container;
        }

        try {
            return $this->requestRaw('GET', sprintf(
                '/api/v1/namespaces/%s/pods/%s/log?%s',
                rawurlencode($namespace),
                rawurlencode($pod),
                http_build_query($query),
            ));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws ApiException
     */
    protected function request(string $method, string $path, ?array $body = null): array
    {
        $raw = $this->requestRaw($method, $path, $body);
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed>|null $body
     * @throws ApiException
     */
    protected function requestRaw(string $method, string $path, ?array $body = null): string
    {
        $url = $this->apiServer . $path;
        $options = [
            'headers' => [
                'Accept' => 'application/json, */*',
                'Authorization' => 'Bearer ' . $this->resolveToken(),
            ],
            'timeout' => $this->timeout,
            'connect_timeout' => min(10, $this->timeout),
            'http_errors' => false,
            'verify' => $this->resolveVerify(),
        ];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $response = (new GuzzleClient())->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new ApiException(
                sprintf('Kubernetes API 连接失败 %s %s: %s', $method, $path, $e->getMessage()),
            );
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status >= 200 && $status < 300) {
            return $raw;
        }

        [$reason, $message] = $this->parseStatusError($raw);

        throw new ApiException(
            sprintf(
                'Kubernetes API %s %s 返回 %d%s%s',
                $method,
                $path,
                $status,
                $reason !== '' ? ' reason=' . $reason : '',
                $message !== '' ? ' message=' . $message : '',
            ),
            statusCode: $status,
            reason: $reason,
            body: mb_substr($raw, 0, self::ERROR_BODY_MAX),
        );
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseStatusError(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['', mb_substr(trim($raw), 0, 200)];
        }

        return [
            (string) ($decoded['reason'] ?? ''),
            mb_substr((string) ($decoded['message'] ?? ''), 0, 200),
        ];
    }

    private function resolveToken(): string
    {
        if ($this->staticToken !== '') {
            return $this->staticToken;
        }
        if ($this->tokenFile === '') {
            return '';
        }
        $now = time();
        if ($this->token !== '' && $now - $this->tokenLoadedAt < self::TOKEN_TTL_SECONDS) {
            return $this->token;
        }
        $content = is_readable($this->tokenFile) ? (string) file_get_contents($this->tokenFile) : '';
        $this->token = trim($content);
        $this->tokenLoadedAt = $now;

        return $this->token;
    }

    private function resolveVerify(): bool|string
    {
        if (!$this->verifyTls) {
            return false;
        }

        return $this->caCertFile !== '' ? $this->caCertFile : true;
    }
}
