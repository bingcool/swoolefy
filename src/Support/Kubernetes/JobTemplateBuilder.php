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

declare(strict_types=1);

namespace Swoolefy\Support\Kubernetes;

/**
 * 把 Deployment.spec.template 派生成一次性 Job。
 *
 * 输入是 Deployment 全量对象（需要 spec.selector 才知道哪些 label 不能带），
 * 输出是可以直接 POST 的 batch/v1 Job。不发 HTTP、不判定成败。
 *
 * $spec 由 Cron 层的 KubernetesJobSpec::toBuilderArray() 提供，本包不依赖 Cron。
 */
class JobTemplateBuilder
{
    public const LABEL_MANAGED_BY = 'app.kubernetes.io/managed-by';
    public const LABEL_NAME = 'app.kubernetes.io/name';
    public const LABEL_CRON_ID = 'schedule-job.cron-id';
    public const LABEL_EXEC_BATCH_ID = 'schedule-job.exec-batch-id';
    public const LABEL_ATTEMPT = 'schedule-job.attempt';
    public const LABEL_EXECUTION_ID = 'schedule-job.execution-id';

    public const MANAGED_BY = 'schedule-job';
    public const APP_NAME = 'schedule-job-cron';

    public const REASON_DEPLOYMENT_NOT_FOUND = 'KUBERNETES_DEPLOYMENT_NOT_FOUND';
    public const REASON_CONTAINER_NOT_FOUND = 'KUBERNETES_CONTAINER_NOT_FOUND';

    private const CONTAINER_STRIP_KEYS = [
        'livenessProbe',
        'readinessProbe',
        'startupProbe',
        'lifecycle',
    ];

    private const POD_STRIP_KEYS = [
        'hostNetwork',
        'hostPID',
        'hostIPC',
        'hostname',
        'subdomain',
        'readinessGates',
        'setHostnameAsFQDN',
    ];

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

    private const ANNOTATION_OPT_OUT = [
        'sidecar.istio.io/inject' => 'false',
        'linkerd.io/inject' => 'disabled',
        'kuma.io/sidecar-injection' => 'disabled',
        'dapr.io/enabled' => 'false',
        'vault.hashicorp.com/agent-inject' => 'false',
    ];

    /**
     * Kubernetes 里是 map/object 的字段。json_decode 会把 `{}` 变成 PHP `[]`，
     * 再 json_encode 就变成 `[]`，API 会 400（例如 ResourceRequirements）。
     */
    private const OBJECT_FIELDS = [
        'resources',
        'limits',
        'requests',
        'securityContext',
        'nodeSelector',
        'affinity',
        'dnsConfig',
        'emptyDir',
        'hostPath',
        'metadata',
        'labels',
        'annotations',
        'selector',
        'matchLabels',
        'overhead',
        'capabilities',
        'seLinuxOptions',
        'seccompProfile',
        'windowsOptions',
        'configMap',
        'secret',
        'projected',
        'downwardAPI',
        'persistentVolumeClaim',
        'csi',
        'httpGet',
        'exec',
        'tcpSocket',
    ];

    /**
     * @param array<string, mixed> $deployment
     * @param array{
     *     namespace: string,
     *     deployment: string,
     *     container?: string,
     *     command?: list<string>,
     *     args?: list<string>,
     *     overrides_command?: bool,
     *     overrides_args?: bool
     * } $spec
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     * @throws JobTemplateException
     */
    public function build(array $deployment, array $spec, array $meta): array
    {
        $template = $deployment['spec']['template'] ?? null;
        if (!is_array($template) || !is_array($template['spec'] ?? null)) {
            throw new JobTemplateException(sprintf(
                '%s: Deployment %s/%s 没有可用的 spec.template',
                self::REASON_DEPLOYMENT_NOT_FOUND,
                (string) ($spec['namespace'] ?? ''),
                (string) ($spec['deployment'] ?? ''),
            ));
        }

        $podSpec = $template['spec'];
        $containers = is_array($podSpec['containers'] ?? null) ? array_values($podSpec['containers']) : [];
        $target = $this->pickContainer($containers, $spec);
        $target = $this->sanitizeContainer($target);
        $target = $this->applyArgv($target, $spec);

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
                'namespace' => (string) ($spec['namespace'] ?? ''),
                'labels' => $labels,
            ],
            'spec' => [
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

        return $this->normalizeEmptyObjects($job);
    }

    /**
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
     * @param list<array<string, mixed>> $containers
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     * @throws JobTemplateException
     */
    private function pickContainer(array $containers, array $spec): array
    {
        $namespace = (string) ($spec['namespace'] ?? '');
        $deployment = (string) ($spec['deployment'] ?? '');
        $containerName = (string) ($spec['container'] ?? '');

        if ($containers === []) {
            throw new JobTemplateException(sprintf(
                '%s: Deployment %s/%s 的模板里没有任何容器',
                self::REASON_CONTAINER_NOT_FOUND,
                $namespace,
                $deployment,
            ));
        }
        if ($containerName === '') {
            if (count($containers) === 1) {
                return $containers[0];
            }
            $names = implode(',', array_map(
                static fn (array $c): string => (string) ($c['name'] ?? '?'),
                $containers,
            ));
            throw new JobTemplateException(sprintf(
                '%s: Deployment %s/%s 是多容器（%s），k8s_spec.container 必填',
                self::REASON_CONTAINER_NOT_FOUND,
                $namespace,
                $deployment,
                $names,
            ));
        }

        foreach ($containers as $container) {
            if ((string) ($container['name'] ?? '') === $containerName) {
                return $container;
            }
        }

        throw new JobTemplateException(sprintf(
            '%s: Deployment %s/%s 里没有名为 %s 的容器',
            self::REASON_CONTAINER_NOT_FOUND,
            $namespace,
            $deployment,
            $containerName,
        ));
    }

    /**
     * @param array<string, mixed> $container
     * @return array<string, mixed>
     */
    private function sanitizeContainer(array $container): array
    {
        foreach (self::CONTAINER_STRIP_KEYS as $key) {
            unset($container[$key]);
        }

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

        unset($container['stdin'], $container['stdinOnce'], $container['tty']);

        return $this->normalizeEmptyObjects($container);
    }

    /**
     * @param array<string, mixed> $container
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function applyArgv(array $container, array $spec): array
    {
        $overridesCommand = (bool) ($spec['overrides_command'] ?? false);
        $overridesArgs = (bool) ($spec['overrides_args'] ?? false);
        if ($overridesCommand) {
            $container['command'] = is_array($spec['command'] ?? null) ? $spec['command'] : [];
            if (!$overridesArgs) {
                unset($container['args']);
            }
        }
        if ($overridesArgs) {
            $container['args'] = is_array($spec['args'] ?? null) ? $spec['args'] : [];
        }

        return $container;
    }

    /**
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
     * @param mixed $volumes
     * @param array<string, mixed> $podSpec
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
     * @param array<string, mixed> $podSpec
     * @return array<string, mixed>
     */
    private function sanitizePodSpec(array $podSpec): array
    {
        $wasHostNetwork = !empty($podSpec['hostNetwork']);
        foreach (self::POD_STRIP_KEYS as $key) {
            unset($podSpec[$key]);
        }
        if ($wasHostNetwork && ($podSpec['dnsPolicy'] ?? '') === 'ClusterFirstWithHostNet') {
            $podSpec['dnsPolicy'] = 'ClusterFirst';
        }
        $podSpec['restartPolicy'] = 'Never';

        return $podSpec;
    }

    /**
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

    private function shouldDropAnnotation(string $key): bool
    {
        foreach (self::ANNOTATION_DROP_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 把「本该是对象、却被 PHP 解成空数组」的字段还原成 `{}`，避免 POST Job 400。
     *
     * 空列表字段（command / args / ports / env …）保持 `[]`。
     *
     * @template T
     * @param T $value
     * @return T
     */
    private function normalizeEmptyObjects(mixed $value, ?string $key = null): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return $key !== null && in_array($key, self::OBJECT_FIELDS, true)
                ? new \stdClass()
                : $value;
        }
        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                $value[$index] = $this->normalizeEmptyObjects($item);
            }

            return $value;
        }
        foreach ($value as $childKey => $item) {
            $value[$childKey] = $this->normalizeEmptyObjects($item, is_string($childKey) ? $childKey : null);
        }

        return $value;
    }
}
