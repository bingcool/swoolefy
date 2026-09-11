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

use Swoolefy\Exception\CronException;
use Swoolefy\Worker\Kubernetes\ApiException;
use Swoolefy\Worker\Kubernetes\ClientInterface;
use Swoolefy\Worker\Kubernetes\ExecutorOptions;
use Swoolefy\Worker\Kubernetes\JobStatus;
use Swoolefy\Worker\Kubernetes\JobTemplateBuilder;
use Swoolefy\Worker\Kubernetes\JobTemplateException;

/**
 * Kubernetes 执行器（exec_type=3）。
 *
 * 与 {@see ShellExecutor} / {@see HttpExecutor} 同级，挂在 **Agent**（`cron.php`）上。
 * Admin 进程永远不调 Kubernetes API（方案 §2）。
 *
 * ## 一次 attempt 的完整时序
 *
 * ```text
 * 解析 k8s_spec ─ 非法 ────────────────────────────────► FAILED（不碰集群）
 * Namespace 白名单 ─ 不在列表 ─────────────────────────► FAILED
 * 解析等待上限 ─ timeout=0 且强制要求 ─────────────────► FAILED
 * hook.stopSignal() ─ 已取消 ─────────────────────────► CANCELLED（不建 Job）
 * hook.onJobPlanned()        先落 Job 名，再碰集群（§12）
 * GET Deployment ─ 404 ───────────────────────────────► FAILED
 * Builder：Deep Copy → 消毒 → 覆盖 argv
 * POST Job ─ 409 ─► GET 比对 label ─ 不是自己的 ──────► FAILED
 * hook.onJobCreated()        把「删 Job」句柄交给 Guard（§11.1）
 * 轮询 GET Job ┬ succeeded ──────────────────────────► SUCCESS
 *              ├ failed ─────────────────────────────► FAILED / TIMEOUT
 *              ├ stopSignal=CANCELLED ─ DELETE ──────► CANCELLED
 *              └ 超过硬上限 ─ DELETE ─────────────────► TIMEOUT
 * hook.onJobFinished()       写元数据 + 摘要日志，解除 Guard 句柄
 * ```
 *
 * ## 边界
 *
 * - **不重试**：retry 由 {@see CronManager::runWithRetry} 编排，每次 attempt 会带着
 *   递增的 `$snapshot->attempt` 重新进来，Job 名随之变化（§9.2）。
 * - **不抛异常**：任何集群故障都收成 FAILED，单个任务不得拖垮 Worker。
 * - **不无限等**：等待上限来自 `cron_task.timeout`，并被 `K8S_MAX_WAIT_SECONDS` 夹住（§11.2）。
 * - **不缓存 Deployment**：每次执行都即时 GET，保证用的是当下的 `spec.template`（§4）。
 */
class KubernetesExecutor implements CronExecutorInterface
{
    /** Job 名前缀。`sj-{execBatchId}-a{attempt}` 共 22 字符，远低于 63 上限。 */
    public const JOB_NAME_PREFIX = 'sj-';

    private readonly JobTemplateBuilder $builder;

    private readonly KubernetesExecutionHookInterface $hook;

    private readonly ExecutorOptions $options;

    public function __construct(
        private readonly ClientInterface $client,
        ?JobTemplateBuilder $builder = null,
        ?KubernetesExecutionHookInterface $hook = null,
        ?ExecutorOptions $options = null,
    ) {
        $this->builder = $builder ?? new JobTemplateBuilder();
        $this->hook = $hook ?? new NullKubernetesExecutionHook();
        $this->options = $options ?? new ExecutorOptions();
    }

    /**
     * 本次 attempt 的 Job 名。
     *
     * `execBatchId` 是 16 位小写 hex（`bin2hex(random_bytes(8))`），天然符合 DNS-1123；
     * 拼上 attempt 是因为**同一批次的重试不能复用 Job 名**——上一次失败的 Job 对象在
     * TTL 到期前还在集群里，同名 Create 只会 409 到那个已失败的对象（方案 §9.2）。
     */
    public static function jobName(string $execBatchId, int $attempt): string
    {
        return self::JOB_NAME_PREFIX . $execBatchId . '-a' . max(1, $attempt);
    }

    /**
     * 执行一次 Kubernetes Job 并等待终态。
     */
    public function run(ExecutionSnapshot $snapshot): ExecutionResult
    {
        $definition = $snapshot->definition;
        $attempt = max(1, $snapshot->attempt);

        try {
            $spec = KubernetesJobSpec::fromArray($definition->k8sSpec);
        } catch (CronException $e) {
            return ExecutionResult::failed('KUBERNETES_SPEC_INVALID: ' . $e->getMessage());
        }

        if (!$this->options->isNamespaceAllowed($spec->namespace)) {
            return ExecutionResult::failed(sprintf(
                'KUBERNETES_NAMESPACE_DENIED: namespace=%s 不在允许列表 [%s] 内',
                $spec->namespace,
                implode(',', $this->options->allowedNamespaces),
            ));
        }

        // 硬上限：不允许一个 Job 无限期占着协程与 with_block_lapping 的执行权（§11.2）
        $waitSeconds = $this->options->resolveWaitSeconds($definition->timeout);
        if ($waitSeconds <= 0) {
            return ExecutionResult::failed(
                'KUBERNETES_TIMEOUT_REQUIRED: Kubernetes 任务必须配置 timeout>0，否则无法界定等待上限'
            );
        }

        // 进入本 attempt 前 Execution 可能已经被判死：Admin 取消，或 Guard 判了超时。
        // 后者尤其要拦住——runWithRetry 会对 TIMEOUT 继续重试，不拦就会给一条已经
        // 收尾的 Execution 再建一个 Job，白白消耗集群资源且没人收割。
        $preSignal = $this->safeCall(
            fn (): string => $this->hook->stopSignal($snapshot),
            KubernetesExecutionHookInterface::STOP_NONE,
        );
        if ($preSignal === KubernetesExecutionHookInterface::STOP_CANCELLED) {
            return ExecutionResult::cancelled('执行前已收到取消请求，未创建 Kubernetes Job');
        }
        if ($preSignal === KubernetesExecutionHookInterface::STOP_TIMEOUT) {
            return ExecutionResult::timeout('执行前已超过 timeout_at，未创建 Kubernetes Job');
        }

        $namespace = $spec->namespace;
        $jobName = self::jobName($snapshot->execBatchId, $attempt);
        $meta = [
            'k8s_namespace' => $namespace,
            'k8s_job_name' => $jobName,
            'k8s_attempt' => $attempt,
        ];

        // Create 之前先落库：Create 成功后立刻崩溃时，Recovery 才有句柄可查（§12）
        $executionId = $this->safeCall(fn (): int => $this->hook->onJobPlanned($snapshot, $namespace, $jobName), 0);

        try {
            $deployment = $this->client->getDeployment($namespace, $spec->deployment);
            $job = $this->builder->build($deployment, $spec->toBuilderArray(), [
                'job_name' => $jobName,
                'cron_id' => $definition->cronTaskId,
                'exec_batch_id' => $snapshot->execBatchId,
                'attempt' => $attempt,
                'execution_id' => $executionId,
                'ttl_seconds' => $this->options->ttlSecondsAfterFinished,
                'active_deadline_seconds' => $this->options->resolveActiveDeadline($waitSeconds),
            ]);
            $meta['k8s_image'] = (string) ($job['spec']['template']['spec']['containers'][0]['image'] ?? '');

            $created = $this->createJobIdempotent($namespace, $jobName, $job, $snapshot, $attempt);
            if ($created instanceof ExecutionResult) {
                return $created;
            }
            $meta['k8s_job_uid'] = (string) ($created['metadata']['uid'] ?? '');

            $this->safeVoid(fn () => $this->hook->onJobCreated(
                $snapshot,
                $namespace,
                $jobName,
                (string) ($meta['k8s_job_uid'] ?? ''),
                // 交给 Guard 的「可杀句柄」：取消 / 超时时先删 Job 再收尾，避免孤儿（§11.1）
                fn (): bool => $this->client->deleteJob($namespace, $jobName),
            ));

            return $this->waitForCompletion($snapshot, $spec, $jobName, $waitSeconds, $meta);
        } catch (ApiException $e) {
            return ExecutionResult::failed($this->describeApiError($e, $jobName));
        } catch (JobTemplateException|CronException $e) {
            return ExecutionResult::failed($e->getMessage());
        } catch (\Throwable $e) {
            return ExecutionResult::failed(sprintf(
                'KUBERNETES_EXECUTOR_ERROR: job=%s error=%s',
                $jobName,
                $e->getMessage(),
            ));
        } finally {
            // _pod 只是本次调用内缓存的原始 Pod 对象，不落库
            unset($meta['_pod']);
            // 无论成败都要解除 Guard 上的删 Job 句柄，否则 attempt 2 期间 Guard 还攥着 attempt 1
            $this->safeVoid(fn () => $this->hook->onJobFinished($snapshot, $meta));
        }
    }

    /**
     * 创建 Job，并处理 409 幂等（方案 §9）。
     *
     * 409 有两种可能：
     * - **本 attempt 自己重入**（Worker 重启后恢复、网络抖动导致的重发）→ label 能对上，继续监控。
     * - **名字撞车**（脏数据 / 有人手工建了同名 Job）→ 不能接管，判 FAILED，
     *   否则会把别人的执行结果当成本次结果。
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>|ExecutionResult 成功返回 Job 对象；冲突返回终态
     * @throws ApiException
     */
    protected function createJobIdempotent(
        string $namespace,
        string $jobName,
        array $job,
        ExecutionSnapshot $snapshot,
        int $attempt,
    ): array|ExecutionResult {
        try {
            return $this->client->createJob($namespace, $job);
        } catch (ApiException $e) {
            if (!$e->isAlreadyExists()) {
                throw $e;
            }
        }

        $existing = $this->client->getJob($namespace, $jobName);
        $labels = $existing['metadata']['labels'] ?? [];
        $sameBatch = (string) ($labels[JobTemplateBuilder::LABEL_EXEC_BATCH_ID] ?? '') === $snapshot->execBatchId;
        $sameAttempt = (string) ($labels[JobTemplateBuilder::LABEL_ATTEMPT] ?? '') === (string) $attempt;
        if ($sameBatch && $sameAttempt) {
            return $existing;
        }

        return ExecutionResult::failed(sprintf(
            'KUBERNETES_JOB_NAME_CONFLICT: %s/%s 已存在且不属于本批次（exec_batch_id=%s attempt=%d）',
            $namespace,
            $jobName,
            $snapshot->execBatchId,
            $attempt,
        ));
    }

    /**
     * 轮询到终态，或被取消 / 超时打断。
     *
     * 循环的三个出口优先级：取消 > 超时 > Job 自身终态。取消优先是因为运维的显式指令
     * 应当立刻生效，不必等下一次 GET。
     *
     * @param array<string, mixed> $meta 会被就地补上 pod 名等信息
     */
    protected function waitForCompletion(
        ExecutionSnapshot $snapshot,
        KubernetesJobSpec $spec,
        string $jobName,
        int $waitSeconds,
        array &$meta,
    ): ExecutionResult {
        $namespace = $spec->namespace;
        $deadline = time() + $waitSeconds;
        $pollMicros = max(1, $this->options->pollIntervalSeconds) * 1000000;

        while (true) {
            $signal = $this->safeCall(fn (): string => $this->hook->stopSignal($snapshot), KubernetesExecutionHookInterface::STOP_NONE);
            if ($signal === KubernetesExecutionHookInterface::STOP_CANCELLED) {
                $this->deleteQuietly($namespace, $jobName);

                return ExecutionResult::cancelled(sprintf('收到取消请求，已删除 Kubernetes Job %s/%s', $namespace, $jobName));
            }
            if ($signal === KubernetesExecutionHookInterface::STOP_TIMEOUT || time() >= $deadline) {
                $this->deleteQuietly($namespace, $jobName);

                return ExecutionResult::timeout(sprintf(
                    '等待超过 %ds，已删除 Kubernetes Job %s/%s%s',
                    $waitSeconds,
                    $namespace,
                    $jobName,
                    $this->collectTail($spec, $snapshot, $meta),
                ));
            }

            try {
                $job = $this->client->getJob($namespace, $jobName);
            } catch (ApiException $e) {
                if (!$e->isNotFound()) {
                    throw $e;
                }

                // Job 消失有两种解释：Guard 刚替我们删掉，或 TTL/人为清理。再问一次 stopSignal 区分
                $signal = $this->safeCall(fn (): string => $this->hook->stopSignal($snapshot), KubernetesExecutionHookInterface::STOP_NONE);
                if ($signal === KubernetesExecutionHookInterface::STOP_CANCELLED) {
                    return ExecutionResult::cancelled(sprintf('取消请求已生效，Kubernetes Job %s/%s 已删除', $namespace, $jobName));
                }
                if ($signal === KubernetesExecutionHookInterface::STOP_TIMEOUT) {
                    return ExecutionResult::timeout(sprintf('超时已生效，Kubernetes Job %s/%s 已删除', $namespace, $jobName));
                }

                return ExecutionResult::failed(sprintf(
                    'KUBERNETES_JOB_DISAPPEARED: %s/%s 在监控期间被删除或回收',
                    $namespace,
                    $jobName,
                ));
            }

            $outcome = $this->classifyJob($job);
            if ($outcome !== null) {
                return $this->finalize($outcome, $spec, $snapshot, $jobName, $meta);
            }

            usleep($pollMicros);
        }
    }

    /**
     * 判定 Job 是否已到终态（方案 §12）。
     *
     * `status.succeeded/failed` 计数最直接；conditions 用来识别 `DeadlineExceeded`——
     * 那说明 K8s 的 `activeDeadlineSeconds` 先触发了，语义上是超时而非业务失败。
     *
     * @param array<string, mixed> $job
     * @return array{0:string,1:string}|null [ExecutionResult 状态, 原因描述]；null = 仍在跑
     */
    protected function classifyJob(array $job): ?array
    {
        $outcome = JobStatus::classify($job);
        if ($outcome === null) {
            return null;
        }
        [$status, $reason] = $outcome;
        $mapped = match ($status) {
            JobStatus::COMPLETE => ExecutionResult::SUCCESS,
            JobStatus::DEADLINE_EXCEEDED => ExecutionResult::TIMEOUT,
            default => ExecutionResult::FAILED,
        };

        return [$mapped, $reason];
    }

    /**
     * 拼装最终 ExecutionResult：补上 Pod 名 / exitCode / 日志摘要。
     *
     * @param array{0:string,1:string} $outcome
     * @param array<string, mixed> $meta
     */
    protected function finalize(
        array $outcome,
        KubernetesJobSpec $spec,
        ExecutionSnapshot $snapshot,
        string $jobName,
        array &$meta,
    ): ExecutionResult {
        [$status, $reason] = $outcome;
        $pod = $this->findPod($spec, $snapshot, $meta);
        $exitCode = $this->resolveExitCode($pod, $spec);
        $message = $this->truncate(sprintf(
            '%s/%s %s%s',
            $spec->namespace,
            $jobName,
            $reason,
            $this->collectTail($spec, $snapshot, $meta),
        ));

        return match ($status) {
            ExecutionResult::SUCCESS => ExecutionResult::success($message, 0, $exitCode ?? 0),
            ExecutionResult::TIMEOUT => ExecutionResult::timeout($message, 0, $exitCode),
            default => ExecutionResult::failed($message, 0, $exitCode),
        };
    }

    /**
     * 用 label 定位本次 attempt 的 Pod，并把 Pod 名写进 meta。
     *
     * Job 生成的 Pod 名带随机后缀，且 `sj-x-a1` 是 `sj-x-a10` 的前缀，按名字匹配会误命中，
     * 因此一律走 `exec-batch-id + attempt` 的 label 选择器（方案 §12）。
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    protected function findPod(KubernetesJobSpec $spec, ExecutionSnapshot $snapshot, array &$meta): ?array
    {
        if (isset($meta['_pod'])) {
            return is_array($meta['_pod']) ? $meta['_pod'] : null;
        }

        try {
            $pods = $this->client->listPods(
                $spec->namespace,
                $this->builder->podLabelSelector($snapshot->execBatchId, max(1, $snapshot->attempt)),
            );
        } catch (\Throwable) {
            $pods = [];
        }

        $pod = $pods[0] ?? null;
        $meta['_pod'] = $pod;
        if (is_array($pod)) {
            $meta['k8s_pod_name'] = (string) ($pod['metadata']['name'] ?? '');
        }

        return is_array($pod) ? $pod : null;
    }

    /**
     * 目标容器的退出码。OOMKilled 等异常终止也能从这里看出来（exitCode=137）。
     *
     * @param array<string, mixed>|null $pod
     */
    protected function resolveExitCode(?array $pod, KubernetesJobSpec $spec): ?int
    {
        foreach ((array) ($pod['status']['containerStatuses'] ?? []) as $containerStatus) {
            if (!is_array($containerStatus)) {
                continue;
            }
            if ($spec->container !== '' && (string) ($containerStatus['name'] ?? '') !== $spec->container) {
                continue;
            }
            $terminated = $containerStatus['state']['terminated'] ?? null;
            if (is_array($terminated) && isset($terminated['exitCode'])) {
                return (int) $terminated['exitCode'];
            }
        }

        return null;
    }

    /**
     * 取 Pod stdout 尾部作为 message 摘要。
     *
     * 完整日志留在集群（随 Job TTL 回收），`cron_task_log.message` 只是 text 列，
     * 不能当日志系统用（方案 §10）。
     *
     * @param array<string, mixed> $meta
     */
    protected function collectTail(KubernetesJobSpec $spec, ExecutionSnapshot $snapshot, array &$meta): string
    {
        $pod = $this->findPod($spec, $snapshot, $meta);
        $podName = (string) ($pod['metadata']['name'] ?? '');
        if ($podName === '') {
            return '';
        }

        $containerName = $spec->container !== ''
            ? $spec->container
            : (string) ($pod['spec']['containers'][0]['name'] ?? '');
        $log = trim($this->client->getPodLogs(
            $spec->namespace,
            $podName,
            $containerName,
            $this->options->logTailLines,
        ));

        return $log === '' ? '' : sprintf(' | pod=%s log=%s', $podName, $log);
    }

    /**
     * 删除 Job 且不因删除失败改变执行结论。
     *
     * 删不掉会留下孤儿 Job，但此时 Execution 的结论（取消 / 超时）已经确定，
     * 再抛异常只会把它变成一个语义更差的 FAILED。
     */
    protected function deleteQuietly(string $namespace, string $jobName): void
    {
        try {
            $this->client->deleteJob($namespace, $jobName);
        } catch (\Throwable) {
            // 交给 Guard 的 terminator 与 Job TTL 兜底
        }
    }

    /**
     * 按 §14 把 API 错误翻译成可检索的 message。
     */
    protected function describeApiError(ApiException $e, string $jobName): string
    {
        $prefix = match (true) {
            $e->isForbidden() => 'KUBERNETES_FORBIDDEN',
            $e->isNotFound() => 'KUBERNETES_NOT_FOUND',
            $e->isRetryable() => 'KUBERNETES_UNAVAILABLE',
            default => 'KUBERNETES_API_ERROR',
        };

        return sprintf('%s: job=%s %s', $prefix, $jobName, $e->getMessage());
    }

    /**
     * 调用应用层 Hook 且不让它的失败影响执行结论。
     *
     * @template T
     * @param callable():T $fn
     * @param T $fallback
     * @return T
     */
    private function safeCall(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * 无返回值版本的 {@see safeCall}。
     */
    private function safeVoid(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable) {
            // Hook 的持久化失败不改变 Kubernetes 侧的执行结论
        }
    }

    /**
     * 截断 message，避免超长日志撑爆 cron_task_log。
     */
    private function truncate(string $message): string
    {
        $max = $this->options->messageMaxChars;

        return mb_strlen($message) > $max ? mb_substr($message, 0, $max) . '...(truncated)' : $message;
    }
}
