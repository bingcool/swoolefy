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

/**
 * 把 `Deployment.spec.template` 派生成一次性 Job（方案 §7 / §8）。
 *
 * ## 为什么必须消毒
 *
 * Deployment 的 Pod 模板是按「长驻 Service」写的，直接拿来跑批任务会出三类事故：
 *
 * 1. **探针杀容器**：任务进程不监听 HTTP，liveness/readiness 必然失败，Pod 被反复重启。
 * 2. **Sidecar 不退出**：Istio 等注入的 sidecar 不会随主容器结束，Job 永远 Complete 不了。
 * 3. **流量打到 Cron Pod**：Deployment 的 selector labels 一旦被复制，Service 的
 *    Endpoints 会把线上请求转发给这个只跑批的 Pod。
 *
 * 因此本类的输入是 Deployment 全量对象（需要 `spec.selector` 才知道哪些 label 不能带），
 * 输出是一个可以直接 POST 的 batch/v1 Job。
 *
 * ## 不做的事
 *
 * - 不发任何 HTTP 请求（那是 {@see KubernetesClient} 的事）。
 * - 不判定成功失败（那是 {@see KubernetesExecutor} 的事）。
 * - 不修改传入的 Deployment 数组。PHP 数组是值语义，赋值即深拷贝，天然满足方案
 *   「Deep Copy，禁止改 Deployment 原对象」的要求。
 */
class KubernetesJobTemplateBuilder
{
    public const LABEL_MANAGED_BY = 'app.kubernetes.io/managed-by';
    public const LABEL_NAME = 'app.kubernetes.io/name';
    public const LABEL_CRON_ID = 'schedule-job.cron-id';
    public const LABEL_EXEC_BATCH_ID = 'schedule-job.exec-batch-id';
    public const LABEL_ATTEMPT = 'schedule-job.attempt';
    public const LABEL_EXECUTION_ID = 'schedule-job.execution-id';

    public const MANAGED_BY = 'schedule-job';
    public const APP_NAME = 'schedule-job-cron';

    /** Deployment 找不到 → 不建 Job（方案 §7 末尾）。 */
    public const REASON_DEPLOYMENT_NOT_FOUND = 'KUBERNETES_DEPLOYMENT_NOT_FOUND';

    /** 目标容器不在模板里 → 不建 Job。 */
    public const REASON_CONTAINER_NOT_FOUND = 'KUBERNETES_CONTAINER_NOT_FOUND';

    /**
     * 容器级必须剥离的键（方案 §7.1）。
     *
     * probe 三兄弟会杀掉不监听端口的批任务进程；lifecycle 钩子仍按 Service 语义写，
     * preStop 常带 `sleep`，会让 Job 结束被无谓拖长甚至卡死。
     */
    private const CONTAINER_STRIP_KEYS = [
        'livenessProbe',
        'readinessProbe',
        'startupProbe',
        'lifecycle',
    ];

    /**
     * Pod 级必须剥离的键。
     *
     * hostNetwork/hostPID/hostIPC：与线上 Service Pod 抢宿主机资源。
     * hostname/subdomain：会让 Cron Pod 出现在 headless Service 的 DNS 记录里。
     * readinessGates：批任务不会有人去满足这些条件。
     */
    private const POD_STRIP_KEYS = [
        'hostNetwork',
        'hostPID',
        'hostIPC',
        'hostname',
        'subdomain',
        'readinessGates',
        'setHostnameAsFQDN',
    ];

    /**
     * 需要整段丢弃的注解前缀。
     *
     * 前三个是 Service Mesh / CNI 的注入与流量配置，留着会注入 sidecar 或改网络行为；
     * 后两个是 kubectl / Deployment 控制器自己写的元数据（last-applied-configuration
     * 体积很大且会误导审计）。
     */
    private const ANNOTATION_DROP_PREFIXES = [
        'sidecar.istio.io/',
        'proxy.istio.io/',
        'traffic.sidecar.istio.io/',
        'linkerd.io/',
        'config.linkerd.io/',
        'kuma.io/',
        'appmesh.k8s.aws/',
        'instrumentation.opentelemetry.io/',
        'vault.hashicorp.com/',
        'sidecar.jaegertracing.io/',
        'dapr.io/',
        'cni.projectcalico.org/',
        'kubectl.kubernetes.io/',
        'deployment.kubernetes.io/',
    ];

    /**
     * 显式写成「关闭」的注入开关。
     *
     * 光删掉注解不够：很多 mesh 的准入 webhook 是「namespace 打了标签就默认注入」，
     * 必须显式声明关闭才能保证 Job Pod 里只有一个容器。
     */
    private const ANNOTATION_OPT_OUT = [
        'sidecar.istio.io/inject' => 'false',
        'linkerd.io/inject' => 'disabled',
        'kuma.io/sidecar-injection' => 'disabled',
        'dapr.io/enabled' => 'false',
        'vault.hashicorp.com/agent-inject' => 'false',
    ];

    /**
     * 派生 Job 对象。
     *
     * @param array<string, mixed> $deployment 完整 Deployment（含 spec.selector 与 spec.template）
     * @param array{
     *     job_name: string,
     *     cron_id: int,
     *     exec_batch_id: string,
     *     attempt: int,
     *     execution_id?: int,
     *     ttl_seconds?: int,
     *     active_deadline_seconds?: int
     * } $meta
     * @return array<string, mixed> 可直接 POST 的 batch/v1 Job
     * @throws CronException 模板缺失或目标容器不存在
     */
    public function build(array $deployment, KubernetesJobSpec $spec, array $meta): array
    {
        $template = $deployment['spec']['template'] ?? null;
        if (!is_array($template) || !is_array($template['spec'] ?? null)) {
            throw new CronException(sprintf(
                '%s: Deployment %s/%s 没有可用的 spec.template',
                self::REASON_DEPLOYMENT_NOT_FOUND,
                $spec->namespace,
                $spec->deployment,
            ));
        }

        $podSpec = $template['spec'];
        $containers = is_array($podSpec['containers'] ?? null) ? array_values($podSpec['containers']) : [];
        $target = $this->pickContainer($containers, $spec);
        $target = $this->sanitizeContainer($target);
        $target = $this->applyArgv($target, $spec);

        // 只留目标业务容器：Service sidecar 一律不复制（方案 §7）
        $podSpec['containers'] = [$target];
        $podSpec['initContainers'] = $this->sanitizeInitContainers($podSpec['initContainers'] ?? []);
        if ($podSpec['initContainers'] === []) {
            unset($podSpec['initContainers']);
        }
        $podSpec['volumes'] = $this->retainReferencedVolumes($podSpec['volumes'] ?? [], $podSpec);
        if ($podSpec['volumes'] === []) {
            unset($podSpec['volumes']);
        }
        $podSpec = $this->sanitizePodSpec($podSpec);

        $labels = $this->buildLabels($meta);
        $job = [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'name' => $meta['job_name'],
                'namespace' => $spec->namespace,
                'labels' => $labels,
            ],
            'spec' => [
                // Kubernetes 不负责业务重试：失败立即结束，由 CronManager 起下一个 attempt
                'backoffLimit' => 0,
                'parallelism' => 1,
                'completions' => 1,
                'template' => [
                    'metadata' => [
                        'labels' => $labels,
                        'annotations' => $this->buildPodAnnotations($template['metadata']['annotations'] ?? []),
                    ],
                    'spec' => $podSpec,
                ],
            ],
        ];

        $ttl = (int) ($meta['ttl_seconds'] ?? 0);
        if ($ttl > 0) {
            $job['spec']['ttlSecondsAfterFinished'] = $ttl;
        }
        $deadline = (int) ($meta['active_deadline_seconds'] ?? 0);
        if ($deadline > 0) {
            $job['spec']['activeDeadlineSeconds'] = $deadline;
        }

        return $job;
    }

    /**
     * 本次 Job / Pod 允许携带的全部 label（方案 §7.1）。
     *
     * 这是一个**白名单**而不是「模板 labels 减去 selector」：模板里可能有
     * `version` / `release` 之类被别的 Service 或 NetworkPolicy 选中的标签，
     * 逐一排查不现实，只带自己的更安全。
     *
     * @param array<string, mixed> $meta
     * @return array<string, string>
     */
    public function buildLabels(array $meta): array
    {
        $labels = [
            self::LABEL_MANAGED_BY => self::MANAGED_BY,
            self::LABEL_NAME => self::APP_NAME,
            self::LABEL_CRON_ID => (string) (int) ($meta['cron_id'] ?? 0),
            self::LABEL_EXEC_BATCH_ID => (string) ($meta['exec_batch_id'] ?? ''),
            self::LABEL_ATTEMPT => (string) max(1, (int) ($meta['attempt'] ?? 1)),
        ];
        $executionId = (int) ($meta['execution_id'] ?? 0);
        if ($executionId > 0) {
            $labels[self::LABEL_EXECUTION_ID] = (string) $executionId;
        }

        return $labels;
    }

    /**
     * 定位 Pod 的 label 选择器：`exec-batch-id` + `attempt` 唯一确定一次尝试。
     *
     * 不用 Pod 名前缀匹配——Job 生成的 Pod 名带随机后缀，且 `sj-xxx-a1` 是
     * `sj-xxx-a10` 的前缀，会误命中。
     */
    public function podLabelSelector(string $execBatchId, int $attempt): string
    {
        return sprintf(
            '%s=%s,%s=%s,%s=%d',
            self::LABEL_MANAGED_BY,
            self::MANAGED_BY,
            self::LABEL_EXEC_BATCH_ID,
            $execBatchId,
            self::LABEL_ATTEMPT,
            max(1, $attempt),
        );
    }

    /**
     * 选出目标业务容器。
     *
     * container 留空且模板只有一个容器时自动选中；多容器时必填，否则无法确定
     * 到底该跑哪个镜像（猜错会跑 sidecar）。
     *
     * @param list<array<string, mixed>> $containers
     * @return array<string, mixed>
     * @throws CronException
     */
    private function pickContainer(array $containers, KubernetesJobSpec $spec): array
    {
        if ($containers === []) {
            throw new CronException(sprintf(
                '%s: Deployment %s/%s 的模板里没有任何容器',
                self::REASON_CONTAINER_NOT_FOUND,
                $spec->namespace,
                $spec->deployment,
            ));
        }
        if ($spec->container === '') {
            if (count($containers) === 1) {
                return $containers[0];
            }
            $names = implode(',', array_map(
                static fn (array $c): string => (string) ($c['name'] ?? '?'),
                $containers,
            ));
            throw new CronException(sprintf(
                '%s: Deployment %s/%s 是多容器（%s），k8s_spec.container 必填',
                self::REASON_CONTAINER_NOT_FOUND,
                $spec->namespace,
                $spec->deployment,
                $names,
            ));
        }

        foreach ($containers as $container) {
            if ((string) ($container['name'] ?? '') === $spec->container) {
                return $container;
            }
        }

        throw new CronException(sprintf(
            '%s: Deployment %s/%s 里没有名为 %s 的容器',
            self::REASON_CONTAINER_NOT_FOUND,
            $spec->namespace,
            $spec->deployment,
            $spec->container,
        ));
    }

    /**
     * 剥离容器级的 Service 语义（探针 / 生命周期钩子 / hostPort）。
     *
     * @param array<string, mixed> $container
     * @return array<string, mixed>
     */
    private function sanitizeContainer(array $container): array
    {
        foreach (self::CONTAINER_STRIP_KEYS as $key) {
            unset($container[$key]);
        }

        // 保留 ports 的声明价值（可读性、监控约定），但 hostPort 会和线上 Pod 抢端口
        if (is_array($container['ports'] ?? null)) {
            $ports = [];
            foreach ($container['ports'] as $port) {
                if (is_array($port)) {
                    unset($port['hostPort'], $port['hostIP']);
                    $ports[] = $port;
                }
            }
            $container['ports'] = $ports;
            if ($ports === []) {
                unset($container['ports']);
            }
        }

        // 批任务没有交互式终端，stdin/tty 开着会让某些运行时等待输入
        unset($container['stdin'], $container['stdinOnce'], $container['tty']);

        return $container;
    }

    /**
     * 按 §5 的三态语义覆盖 command / args。
     *
     * 关键点：只覆盖 command 而模板里残留 args 时，Kubernetes 会拼成
     * `新command + 旧args`，几乎必然是错的。此时必须把 args 一并移除。
     *
     * @param array<string, mixed> $container
     * @return array<string, mixed>
     */
    private function applyArgv(array $container, KubernetesJobSpec $spec): array
    {
        if ($spec->overridesCommand) {
            $container['command'] = $spec->command;
            if (!$spec->overridesArgs) {
                unset($container['args']);
            }
        }
        if ($spec->overridesArgs) {
            $container['args'] = $spec->args;
        }

        return $container;
    }

    /**
     * initContainer 可以继承（通常是配置渲染 / DB migrate 检查），但要过滤两类：
     *
     * - `restartPolicy: Always` 的原生 Sidecar（K8s 1.29+）：它不会退出，Job 会一直 Running。
     * - 同样要剥离探针与 hostPort。
     *
     * @param mixed $initContainers
     * @return list<array<string, mixed>>
     */
    private function sanitizeInitContainers(mixed $initContainers): array
    {
        if (!is_array($initContainers)) {
            return [];
        }

        $result = [];
        foreach ($initContainers as $container) {
            if (!is_array($container)) {
                continue;
            }
            if (($container['restartPolicy'] ?? '') === 'Always') {
                continue;
            }
            $result[] = $this->sanitizeContainer($container);
        }

        return $result;
    }

    /**
     * 只保留仍被引用的 volume。
     *
     * 丢掉 sidecar 之后，它专用的 volume（例如 istio 的证书卷）会变成孤儿；
     * 留着虽然不报错，但会让 Pod 无谓地等待挂载 Secret/PVC。
     *
     * `projected` / `downwardAPI` 等由 kubelet 自动补的卷不在 spec.volumes 里，不受影响。
     *
     * @param mixed $volumes
     * @param array<string, mixed> $podSpec 已经只剩目标容器与筛过的 initContainers
     * @return list<array<string, mixed>>
     */
    private function retainReferencedVolumes(mixed $volumes, array $podSpec): array
    {
        if (!is_array($volumes)) {
            return [];
        }

        $used = [];
        $all = array_merge(
            is_array($podSpec['containers'] ?? null) ? $podSpec['containers'] : [],
            is_array($podSpec['initContainers'] ?? null) ? $podSpec['initContainers'] : [],
        );
        foreach ($all as $container) {
            foreach ((array) ($container['volumeMounts'] ?? []) as $mount) {
                if (is_array($mount) && isset($mount['name'])) {
                    $used[(string) $mount['name']] = true;
                }
            }
            foreach ((array) ($container['volumeDevices'] ?? []) as $device) {
                if (is_array($device) && isset($device['name'])) {
                    $used[(string) $device['name']] = true;
                }
            }
        }

        $result = [];
        foreach ($volumes as $volume) {
            if (is_array($volume) && isset($used[(string) ($volume['name'] ?? '')])) {
                $result[] = $volume;
            }
        }

        return $result;
    }

    /**
     * Pod 级消毒：去掉宿主机耦合，并强制 `restartPolicy: Never`。
     *
     * `restartPolicy: Always` 在 Job 里是非法值，API Server 会直接拒绝；
     * 而且配合 `backoffLimit: 0` 才是「失败就结束、由 schedule-job 决定要不要重试」。
     *
     * @param array<string, mixed> $podSpec
     * @return array<string, mixed>
     */
    private function sanitizePodSpec(array $podSpec): array
    {
        $wasHostNetwork = !empty($podSpec['hostNetwork']);
        foreach (self::POD_STRIP_KEYS as $key) {
            unset($podSpec[$key]);
        }
        // ClusterFirstWithHostNet 脱离 hostNetwork 就非法，回落到默认策略
        if ($wasHostNetwork && ($podSpec['dnsPolicy'] ?? '') === 'ClusterFirstWithHostNet') {
            $podSpec['dnsPolicy'] = 'ClusterFirst';
        }
        $podSpec['restartPolicy'] = 'Never';

        return $podSpec;
    }

    /**
     * Pod 注解：丢掉注入 / 控制器元数据，再显式声明关闭 sidecar 注入。
     *
     * 其余业务注解（配置 checksum、prometheus 抓取开关等）保留，它们对批任务无害，
     * 且往往是排障线索。
     *
     * @param mixed $annotations
     * @return array<string, string>
     */
    private function buildPodAnnotations(mixed $annotations): array
    {
        $result = [];
        if (is_array($annotations)) {
            foreach ($annotations as $key => $value) {
                $key = (string) $key;
                if ($this->shouldDropAnnotation($key)) {
                    continue;
                }
                if (is_array($value) || is_object($value)) {
                    continue;
                }
                $result[$key] = (string) $value;
            }
        }

        return array_merge($result, self::ANNOTATION_OPT_OUT);
    }

    /**
     * 注解是否命中丢弃前缀。
     */
    private function shouldDropAnnotation(string $key): bool
    {
        foreach (self::ANNOTATION_DROP_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
