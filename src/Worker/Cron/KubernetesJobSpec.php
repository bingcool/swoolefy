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

namespace Swoolefy\Worker\Cron;

use Swoolefy\Exception\CronException;

/**
 * `cron_task.k8s_spec` 的不可变解析结果（方案 §5）。
 *
 * ```json
 * {
 *   "namespace": "production",
 *   "deployment": "order-service",
 *   "container":  "order-service",
 *   "command":    ["/app/bin/task"],
 *   "args":       ["reconcile"]
 * }
 * ```
 *
 * 刻意**不保存** image / env / volumes：权威来源永远是执行当下的
 * `Deployment.spec.template`（方案 §4）。
 *
 * ## command / args 的三态语义
 *
 * Kubernetes 的规则是「显式给了 command 就完全忽略镜像 ENTRYPOINT；显式给了 args
 * 就完全忽略镜像 CMD」。为了不出现「新 command + 模板残留 args」这种拼接事故，
 * 本对象区分三种状态：
 *
 * | 配置              | overridesCommand | overridesArgs | 结果 |
 * |-------------------|------------------|---------------|------|
 * | 都不填            | false            | false         | 完全沿用模板容器的 command/args |
 * | 只填 command      | true             | false         | 覆盖 command，并**移除**模板 args（见 JobTemplateBuilder） |
 * | 填了 args（含 []）| 视 command 而定  | true          | args 整体替换，不追加 |
 *
 * ## 校验
 *
 * {@see fromArray} 只做结构校验（必填、类型、非法字符），不查集群。
 * Namespace 白名单在 {@see KubernetesExecutor} 里查，因为它属于运行环境而非配置结构。
 */
final class KubernetesJobSpec
{
    /**
     * @param list<string> $command
     * @param list<string> $args
     */
    private function __construct(
        public readonly string $namespace,
        public readonly string $deployment,
        public readonly string $container,
        public readonly array $command,
        public readonly array $args,
        public readonly bool $overridesCommand,
        public readonly bool $overridesArgs,
    ) {
    }

    /**
     * 解析并校验 `k8s_spec`。
     *
     * @param array<string, mixed> $spec
     * @throws CronException 结构非法时（调用方需把它收成本次 Execution 的 FAILED）
     */
    public static function fromArray(array $spec): self
    {
        $namespace = self::readName($spec, 'namespace');
        $deployment = self::readName($spec, 'deployment');
        // container 允许留空：目标 Deployment 只有一个容器时由 Builder 自动选中
        $container = trim((string) ($spec['container'] ?? ''));
        if ($container !== '' && !self::isDnsLabel($container)) {
            throw new CronException('k8s_spec.container 不是合法的容器名: ' . $container);
        }

        $hasCommand = array_key_exists('command', $spec) && $spec['command'] !== null && $spec['command'] !== '';
        $hasArgs = array_key_exists('args', $spec) && $spec['args'] !== null && $spec['args'] !== '';

        $command = $hasCommand ? self::readArgv($spec['command'], 'command') : [];
        $args = $hasArgs ? self::readArgv($spec['args'], 'args') : [];

        // command 给了空数组等于没给：Job 没有可执行入口时应回落到镜像 ENTRYPOINT
        $overridesCommand = $command !== [];
        // args 给了空数组是有意义的「清空镜像 CMD」，因此保留显式标记
        $overridesArgs = $hasArgs && is_array($spec['args']);

        return new self(
            namespace: $namespace,
            deployment: $deployment,
            container: $container,
            command: $command,
            args: $args,
            overridesCommand: $overridesCommand,
            overridesArgs: $overridesArgs,
        );
    }

    /**
     * 供日志 / UI 展示的一行摘要，不作为执行来源。
     */
    /**
     * 交给 {@see \Swoolefy\Support\Kubernetes\JobTemplateBuilder} 的纯数组，避免 K8s 包依赖 Cron。
     *
     * @return array{
     *     namespace: string,
     *     deployment: string,
     *     container: string,
     *     command: list<string>,
     *     args: list<string>,
     *     overrides_command: bool,
     *     overrides_args: bool
     * }
     */
    public function toBuilderArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'deployment' => $this->deployment,
            'container' => $this->container,
            'command' => $this->command,
            'args' => $this->args,
            'overrides_command' => $this->overridesCommand,
            'overrides_args' => $this->overridesArgs,
        ];
    }

    public function summary(): string
    {
        $argv = array_merge($this->command, $this->args);

        return sprintf(
            '%s/%s[%s]%s',
            $this->namespace,
            $this->deployment,
            $this->container !== '' ? $this->container : 'auto',
            $argv === [] ? '' : ' ' . implode(' ', $argv),
        );
    }

    /**
     * 读取必填的 Kubernetes 对象名并校验成 DNS-1123 label。
     *
     * @param array<string, mixed> $spec
     * @throws CronException
     */
    private static function readName(array $spec, string $key): string
    {
        $value = trim((string) ($spec[$key] ?? ''));
        if ($value === '') {
            throw new CronException('k8s_spec.' . $key . ' 为必填');
        }
        if (!self::isDnsLabel($value)) {
            throw new CronException('k8s_spec.' . $key . ' 不是合法的 Kubernetes 名称: ' . $value);
        }

        return $value;
    }

    /**
     * 把配置里的 command / args 规范成 string 列表。
     *
     * 支持三种写法：JSON 数组字符串、真数组、多行文本（每行一个参数，便于 UI 输入）。
     * **不支持**单行 shell 命令：方案 §5.2 明确禁止 `sh -c "..."`，argv 必须由调用方
     * 拆好，避免把 Shell 注入面带进集群。
     *
     * @return list<string>
     * @throws CronException
     */
    private static function readArgv(mixed $value, string $key): array
    {
        if (is_string($value)) {
            $text = trim($value);
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                // 多行文本：逐行一个参数
                $value = array_values(array_filter(
                    array_map('trim', preg_split('/\R/', $text) ?: []),
                    static fn (string $line): bool => $line !== '',
                ));
            }
        }

        if (!is_array($value)) {
            throw new CronException('k8s_spec.' . $key . ' 必须是字符串数组');
        }

        $argv = [];
        foreach ($value as $item) {
            if (is_array($item) || is_object($item) || is_bool($item) || $item === null) {
                throw new CronException('k8s_spec.' . $key . ' 的每一项必须是字符串');
            }
            $argv[] = (string) $item;
        }

        return $argv;
    }

    /**
     * RFC 1123 label：小写字母数字与中划线，首尾必须是字母数字，最长 63。
     */
    private static function isDnsLabel(string $value): bool
    {
        return strlen($value) <= 63
            && preg_match('/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $value) === 1;
    }
}
