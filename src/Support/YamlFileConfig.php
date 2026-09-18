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

namespace Swoolefy\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * 通用 YAML 文件加载：解析后把字符串中的 `${VAR}` 替换为 env('VAR')。
 *
 * 供 application.yaml / nacos.yaml 等声明式配置共用，不绑定 APP_PATH。
 */
final class YamlFileConfig
{
    /**
     * 读取 YAML 文件并替换 `${ENV}` 占位符。
     *
     * 文件不存在或根节点不是映射时返回 []。
     *
     * @return array<string, mixed>
     */
    public static function load(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [];
        }

        $yaml = Yaml::parseFile($filePath);
        if (!is_array($yaml)) {
            return [];
        }

        /** @var array<string, mixed> $resolved */
        $resolved = self::resolveEnvPlaceholders($yaml);

        return $resolved;
    }

    /**
     * 递归把字符串里的 `${VAR}` 替换成 env('VAR')。
     *
     * 规则：
     * - 仅匹配 POSIX 名：`[A-Za-z_][A-Za-z0-9_]*`，例如 `${POD_IP}`、`${NACOS_HOST}`
     * - 同一字符串可出现多个占位符：`http://${HOST}:${PORT}`
     * - 未设置或空值替换为 ''，避免把字面量 `${POD_IP}` 当成 IP / Host
     * - 非字符串（int/bool/null）原样返回；数组键不替换
     *
     * @param callable(string): mixed|null $readEnv 单测可注入；默认 env()
     */
    public static function resolveEnvPlaceholders(mixed $value, ?callable $readEnv = null): mixed
    {
        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $item) {
                $resolved[$key] = self::resolveEnvPlaceholders($item, $readEnv);
            }

            return $resolved;
        }

        if (!is_string($value) || !str_contains($value, '${')) {
            return $value;
        }

        $readEnv ??= static fn (string $key): mixed => env($key);

        $replaced = preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $matches) use ($readEnv): string {
                $raw = $readEnv($matches[1]);
                if ($raw === null || $raw === '') {
                    return '';
                }
                if (is_bool($raw)) {
                    return $raw ? 'true' : 'false';
                }

                return (string) $raw;
            },
            $value,
        );

        return is_string($replaced) ? $replaced : $value;
    }
}
