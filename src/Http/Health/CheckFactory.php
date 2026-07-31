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

namespace Swoolefy\Http\Health;

use Swoolefy\Http\Health\Check\DatabaseHealthCheck;
use Swoolefy\Http\Health\Check\FileStorageHealthCheck;
use Swoolefy\Http\Health\Check\ProcessHealthCheck;
use Swoolefy\Http\Health\Check\RedisHealthCheck;

/**
 * 将 Config 中的 check 声明实例化为 {@see HealthCheckInterface}。
 *
 * ## 支持的 type
 * | type | 类 | 主要配置键 |
 * |------|----|------------|
 * | process | {@see ProcessHealthCheck} | — |
 * | redis | {@see RedisHealthCheck} | component（默认 redis）、name、timeout_seconds |
 * | database / db | {@see DatabaseHealthCheck} | component（默认 db）、name、timeout_seconds |
 * | file_storage / storage / filestorage | {@see FileStorageHealthCheck} | component、disk、probe_path、name |
 * | class | 用户 FQCN | class（须实现接口，零参构造） |
 *
 * 未知 type / 无效 class 抛 {@see InvalidHealthCheckConfigException}（fail closed）。
 */
final class CheckFactory
{
    /**
     * 批量构造；任一条声明非法即抛配置异常。
     *
     * @param list<array<string, mixed>> $defs
     * @return list<HealthCheckInterface>
     *
     * @throws InvalidHealthCheckConfigException
     */
    public static function fromDefs(array $defs, float $defaultTimeoutSeconds = 2.0): array
    {
        $checks = [];
        foreach ($defs as $index => $def) {
            if (!is_array($def)) {
                throw new InvalidHealthCheckConfigException(sprintf(
                    'health check def at index %d must be an array',
                    $index,
                ));
            }
            $checks[] = self::make($def, $defaultTimeoutSeconds);
        }

        return $checks;
    }

    /**
     * 启动期校验：未知 type 立即失败，避免运行期静默跳过。
     *
     * @param list<array<string, mixed>> $defs
     *
     * @throws InvalidHealthCheckConfigException
     */
    public static function assertDefsValid(array $defs, float $defaultTimeoutSeconds = 2.0): void
    {
        self::fromDefs($defs, $defaultTimeoutSeconds);
    }

    /**
     * 单条声明 → 检查实例。
     *
     * @param array<string, mixed> $def 至少含 `type`
     *
     * @throws InvalidHealthCheckConfigException
     */
    public static function make(array $def, float $defaultTimeoutSeconds = 2.0): HealthCheckInterface
    {
        $type = strtolower((string) ($def['type'] ?? ''));
        if ($type === '') {
            throw new InvalidHealthCheckConfigException('health check def missing string type');
        }

        // name 可覆盖默认，便于同一 type 多组件（如 redis + redis-cache）
        $name = isset($def['name']) && is_string($def['name']) && $def['name'] !== ''
            ? $def['name']
            : $type;
        $component = isset($def['component']) && is_string($def['component']) && $def['component'] !== ''
            ? $def['component']
            : null;
        $timeout = self::resolveTimeout($def, $defaultTimeoutSeconds);

        return match ($type) {
            'process' => new ProcessHealthCheck(),
            'redis' => new RedisHealthCheck(
                $component ?? 'redis',
                $name !== '' ? $name : 'redis',
                $timeout,
            ),
            'database', 'db' => new DatabaseHealthCheck(
                $component ?? 'db',
                $name !== '' ? $name : 'database',
                $timeout,
            ),
            'file_storage', 'storage', 'filestorage' => self::makeFileStorage($def, $component, $name),
            'class' => self::makeClass($def),
            default => throw new InvalidHealthCheckConfigException(sprintf(
                'unknown health check type "%s"',
                $type,
            )),
        };
    }

    /**
     * FileStorageSystem：local / aws_s3 / aliyun_oss / tengxun_cos（及 fake）。
     *
     * @param array<string, mixed> $def
     */
    private static function makeFileStorage(array $def, ?string $component, string $name): FileStorageHealthCheck
    {
        $disk = isset($def['disk']) && is_string($def['disk']) && $def['disk'] !== ''
            ? $def['disk']
            : (isset($def['provider']) && is_string($def['provider']) && $def['provider'] !== ''
                ? $def['provider']
                : null);
        $probePath = isset($def['probe_path']) && is_string($def['probe_path']) && $def['probe_path'] !== ''
            ? $def['probe_path']
            : '.swoolefy/health-probe';

        return new FileStorageHealthCheck(
            component: $component ?? 'file_storage',
            name: $name !== '' && $name !== 'storage' && $name !== 'filestorage' ? $name : 'file_storage',
            disk: $disk,
            probePath: $probePath,
        );
    }

    /**
     * 自定义检查：`['type'=>'class','class'=>\App\Health\Xxx::class]`。
     * 要求零参构造且实现 {@see HealthCheckInterface}。
     *
     * @param array<string, mixed> $def
     *
     * @throws InvalidHealthCheckConfigException
     */
    private static function makeClass(array $def): HealthCheckInterface
    {
        $class = $def['class'] ?? null;
        if (!is_string($class) || $class === '') {
            throw new InvalidHealthCheckConfigException('health check type=class requires non-empty class');
        }
        if (!class_exists($class)) {
            throw new InvalidHealthCheckConfigException(sprintf(
                'health check class "%s" does not exist',
                $class,
            ));
        }

        $object = new $class();
        if (!$object instanceof HealthCheckInterface) {
            throw new InvalidHealthCheckConfigException(sprintf(
                'health check class "%s" must implement HealthCheckInterface',
                $class,
            ));
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $def
     */
    private static function resolveTimeout(array $def, float $defaultTimeoutSeconds): float
    {
        if (isset($def['timeout_seconds']) && is_numeric($def['timeout_seconds'])) {
            $timeout = (float) $def['timeout_seconds'];
            if ($timeout > 0) {
                return $timeout;
            }
        }

        return $defaultTimeoutSeconds > 0 ? $defaultTimeoutSeconds : 2.0;
    }
}
