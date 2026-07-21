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
 * | redis | {@see RedisHealthCheck} | component（默认 redis）、name |
 * | database / db | {@see DatabaseHealthCheck} | component（默认 db）、name |
 * | file_storage / storage / filestorage | {@see FileStorageHealthCheck} | component、disk、probe_path、name |
 * | class | 用户 FQCN | class（须实现接口，零参构造） |
 *
 * 未知 type 被忽略（返回 null），避免拼写错误直接打挂探针注册。
 */
final class CheckFactory
{
    /**
     * 批量构造；跳过无法识别的声明。
     *
     * @param list<array<string, mixed>> $defs
     * @return list<HealthCheckInterface>
     */
    public static function fromDefs(array $defs): array
    {
        $checks = [];
        foreach ($defs as $def) {
            $check = self::make($def);
            if ($check !== null) {
                $checks[] = $check;
            }
        }

        return $checks;
    }

    /**
     * 单条声明 → 检查实例。
     *
     * @param array<string, mixed> $def 至少含 `type`
     */
    public static function make(array $def): ?HealthCheckInterface
    {
        $type = strtolower((string) ($def['type'] ?? ''));
        // name 可覆盖默认，便于同一 type 多组件（如 redis + redis-cache）
        $name = isset($def['name']) && is_string($def['name']) && $def['name'] !== ''
            ? $def['name']
            : $type;
        $component = isset($def['component']) && is_string($def['component']) && $def['component'] !== ''
            ? $def['component']
            : null;

        return match ($type) {
            'process' => new ProcessHealthCheck(),
            'redis' => new RedisHealthCheck($component ?? 'redis', $name !== '' ? $name : 'redis'),
            'database', 'db' => new DatabaseHealthCheck($component ?? 'db', $name !== '' ? $name : 'database'),
            'file_storage', 'storage', 'filestorage' => self::makeFileStorage($def, $component, $name),
            'class' => self::makeClass($def),
            default => null,
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
     */
    private static function makeClass(array $def): ?HealthCheckInterface
    {
        $class = $def['class'] ?? null;
        if (!is_string($class) || $class === '' || !class_exists($class)) {
            return null;
        }

        $object = new $class();
        if (!$object instanceof HealthCheckInterface) {
            return null;
        }

        return $object;
    }
}
