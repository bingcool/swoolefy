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

namespace Swoolefy\Worker\Cron;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 基于 Guzzle 的 Kubernetes API 客户端（方案 §13）。
 *
 * ## 两种凭证来源
 *
 * 1. **集群内（推荐）**：Agent 以 Pod 形式跑在目标集群里，用 ServiceAccount。
 *    自动读取 `KUBERNETES_SERVICE_HOST/PORT` 与 `/var/run/secrets/kubernetes.io/serviceaccount/`
 *    下的 token / ca.crt。projected token 会轮换，因此 token **每次按 TTL 重读**，不做进程级缓存。
 * 2. **集群外**：显式配 `K8S_API_SERVER` + `K8S_TOKEN`（+ 可选 `K8S_CA_CERT_FILE`）。
 *
 * ## 不做的事
 *
 * - 不自动重试。429/5xx 直接抛，由 CronManager 的 `retry` 走下一个 attempt（方案 §14），
 *   否则 Create 重试会产生重复 Job。
 * - 不缓存 Deployment。方案要求「Create 前即时 GET」，缓存会让 Cron 用到过期镜像（§4）。
 * - 不解析业务语义。Job 是否成功由 {@see KubernetesExecutor} 判定。
 *
 * 必须在协程内调用：Swoole 已 hook curl，Guzzle 的等待不会阻塞 Worker。
 *
 * @see KubernetesClientInterface
 */
class KubernetesClient implements KubernetesClientInterface
{
    /** in-cluster ServiceAccount 挂载目录。 */
    private const SA_DIR = '/var/run/secrets/kubernetes.io/serviceaccount';

    /** projected token 会轮换，缓存这么久后重读文件。 */
    private const TOKEN_TTL_SECONDS = 60;

    /** 响应体记入异常时的截断长度，避免把整个 Status 塞进 cron_task_log。 */
    private const ERROR_BODY_MAX = 500;

    private string $token = '';

    private int $tokenLoadedAt = 0;

    /**
     * @param string $apiServer   形如 `https://10.96.0.1:443`，末尾不带斜杠
     * @param string $tokenFile   Bearer Token 文件路径；与 $staticToken 二选一
     * @param string $staticToken 直接给定的 Bearer Token（集群外场景）
     * @param string $caCertFile  CA 证书路径；空串表示用系统信任链
     * @param bool   $verifyTls   false 只应出现在本地调试环境
     * @param int    $timeout     单次 API 调用超时（秒）
     */
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
     * 按环境变量构造：优先显式配置，其次 in-cluster ServiceAccount。
     *
     * 环境变量：
     * - `K8S_API_SERVER`、`K8S_TOKEN`、`K8S_CA_CERT_FILE`、`K8S_VERIFY_TLS`、`K8S_API_TIMEOUT`
     * - in-cluster 回退：`KUBERNETES_SERVICE_HOST` / `KUBERNETES_SERVICE_PORT`
     *
     * @throws KubernetesApiException 两种来源都不可用时（配置问题，尽早暴露）
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
                throw new KubernetesApiException(
                    '未配置 Kubernetes 凭证：既没有 K8S_API_SERVER，也不在集群内（无 KUBERNETES_SERVICE_HOST）'
                );
            }
            // IPv6 字面量需要方括号
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

    /**
     * in-cluster 时 Agent 自身所在 Namespace，可作为白名单缺省值。
     */
    public static function inClusterNamespace(): string
    {
        $file = self::SA_DIR . '/namespace';

        return is_readable($file) ? trim((string) file_get_contents($file)) : '';
    }

    /**
     * @inheritDoc
     */
    public function getDeployment(string $namespace, string $name): array
    {
        return $this->request('GET', sprintf(
            '/apis/apps/v1/namespaces/%s/deployments/%s',
            rawurlencode($namespace),
            rawurlencode($name),
        ));
    }

    /**
     * @inheritDoc
     */
    public function createJob(string $namespace, array $job): array
    {
        return $this->request(
            'POST',
            sprintf('/apis/batch/v1/namespaces/%s/jobs', rawurlencode($namespace)),
            body: $job,
        );
    }

    /**
     * @inheritDoc
     */
    public function getJob(string $namespace, string $name): array
    {
        return $this->request('GET', sprintf(
            '/apis/batch/v1/namespaces/%s/jobs/%s',
            rawurlencode($namespace),
            rawurlencode($name),
        ));
    }

    /**
     * @inheritDoc
     *
     * `propagationPolicy=Background` 让 GC 连带删掉 Job 创建的 Pod；
     * 缺省的 Orphan 策略会留下仍在运行的 Pod，对 Timeout / Cancel 场景是致命的。
     */
    public function deleteJob(string $namespace, string $name): bool
    {
        try {
            $this->request('DELETE', sprintf(
                '/apis/batch/v1/namespaces/%s/jobs/%s?propagationPolicy=Background',
                rawurlencode($namespace),
                rawurlencode($name),
            ));

            return true;
        } catch (KubernetesApiException $e) {
            // 已经不在了就是我们想要的终态，重复删除必须幂等
            if ($e->isNotFound()) {
                return true;
            }
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
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

    /**
     * @inheritDoc
     *
     * 日志接口返回 text/plain 而非 JSON，因此单独走 raw 请求。
     * 任何失败都吞掉返回空串：拿不到日志不应该把一次成功的执行改判成失败。
     */
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
     * 发起 API 调用并把 JSON 响应解成数组。
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws KubernetesApiException
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
     * 发起 API 调用并返回原始响应体。非 2xx 一律抛 {@see KubernetesApiException}。
     *
     * @param array<string, mixed>|null $body
     * @throws KubernetesApiException
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
            // 自己判状态码，避免 Guzzle 抛出没有 reason 的通用异常
            'http_errors' => false,
            'verify' => $this->resolveVerify(),
        ];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $response = (new Client())->request($method, $url, $options);
        } catch (GuzzleException $e) {
            // 连接层失败：statusCode=0，isRetryable() 为 true
            throw new KubernetesApiException(
                sprintf('Kubernetes API 连接失败 %s %s: %s', $method, $path, $e->getMessage()),
            );
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status >= 200 && $status < 300) {
            return $raw;
        }

        [$reason, $message] = $this->parseStatusError($raw);

        throw new KubernetesApiException(
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
     * 从 Kubernetes Status 对象里取 reason / message。
     *
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

    /**
     * 取 Bearer Token。文件来源按 TTL 重读，兼容 projected token 轮换。
     */
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

    /**
     * Guzzle `verify` 选项：CA 文件路径 / true / false。
     */
    private function resolveVerify(): bool|string
    {
        if (!$this->verifyTls) {
            return false;
        }

        return $this->caCertFile !== '' ? $this->caCertFile : true;
    }
}
