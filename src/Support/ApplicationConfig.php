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
 * 读取 APP_PATH/application.yaml。
 */
final class ApplicationConfig
{
    public function __construct(
        public readonly string $appPath,
        public readonly string $yamlFile,
        /** @var array<string, mixed> */
        private readonly array $yaml = [],
    ) {
    }

    public static function load(): self
    {
        $appPath = self::resolveAppPath();
        $yamlFile = $appPath . '/application.yaml';
        $yaml = is_file($yamlFile) ? (array) Yaml::parseFile($yamlFile) : [];

        return new self($appPath, $yamlFile, $yaml);
    }

    public static function resolveAppPath(): string
    {
        if (!defined('APP_PATH') || '' === (string) APP_PATH) {
            throw new \RuntimeException('APP_PATH is not defined');
        }

        return rtrim((string) APP_PATH, '/');
    }

    public static function applicationYamlPath(): string
    {
        return self::resolveAppPath() . '/application.yaml';
    }

    public static function hasApplicationYaml(): bool
    {
        if (!defined('APP_PATH') || '' === (string) APP_PATH) {
            return false;
        }

        return is_file(self::applicationYamlPath());
    }

    /**
     * 加载 APP_PATH/config/{filename} 返回的 PHP 配置数组。
     *
     * 文件不存在返回 []；require / 语法错误时记录日志。
     * 生产环境（{@see SystemEnv::isPrdEnv()}）重新抛出，避免静默回退到危险默认值。
     *
     * @return array<string, mixed>
     *
     * @throws \Throwable 生产环境下配置加载失败时抛出
     */
    public static function loadPhpConfig(string $filename): array
    {
        if (!self::hasApplicationYaml()) {
            return [];
        }

        $configFile = self::resolveAppPath() . '/config/' . ltrim($filename, '/');
        if (!is_file($configFile)) {
            return [];
        }

        try {
            $loaded = require $configFile;

            return is_array($loaded) ? $loaded : [];
        } catch (\Throwable $e) {
            SupportLog::error('config', 'Failed to load PHP config file', [
                'file' => $configFile,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (\Swoolefy\Core\SystemEnv::isPrdEnv()) {
                throw $e;
            }

            return [];
        }
    }

    public static function isEnableNacosRegister(): bool
    {
        if (!self::hasApplicationYaml()) {
            return false;
        }

        $yaml = (array) Yaml::parseFile(self::applicationYamlPath());
        $nacos = (array) ($yaml['nacos'] ?? []);

        if (!array_key_exists('enable_nacos_register', $nacos)) {
            return false;
        }

        $value = $nacos['enable_nacos_register'];
        if ('' === $value || null === $value) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, mixed>
     */
    public function nacosSection(string $key): array
    {
        $nacos = (array) ($this->yaml['nacos'] ?? []);

        return (array) ($nacos[$key] ?? []);
    }

    public static function pickString(array $yaml, string $yamlKey, string $envKey, string $default): string
    {
        if (array_key_exists($yamlKey, $yaml) && '' !== (string) $yaml[$yamlKey]) {
            return (string) $yaml[$yamlKey];
        }

        $env = self::readEnv($envKey);
        if ($env !== null && $env !== '') {
            return (string) $env;
        }

        return $default;
    }

    public static function pickStringEnvFirst(array $yaml, string $yamlKey, string $envKey, string $default): string
    {
        $env = self::readEnv($envKey);
        if ($env !== null && $env !== '') {
            return (string) $env;
        }

        if (array_key_exists($yamlKey, $yaml) && '' !== (string) $yaml[$yamlKey]) {
            return (string) $yaml[$yamlKey];
        }

        return $default;
    }

    public static function pickInt(array $yaml, string $yamlKey, string $envKey, int $default): int
    {
        if (array_key_exists($yamlKey, $yaml) && is_numeric($yaml[$yamlKey])) {
            return (int) $yaml[$yamlKey];
        }

        $env = self::readEnv($envKey);
        if ($env !== null && is_numeric($env)) {
            return (int) $env;
        }

        return $default;
    }

    public static function pickIntEnvFirst(array $yaml, string $yamlKey, string $envKey, int $default): int
    {
        $env = self::readEnv($envKey);
        if ($env !== null && is_numeric($env)) {
            return (int) $env;
        }

        if (array_key_exists($yamlKey, $yaml) && is_numeric($yaml[$yamlKey])) {
            return (int) $yaml[$yamlKey];
        }

        return $default;
    }

    public static function pickBool(array $yaml, string $yamlKey, string $envKey, bool $default): bool
    {
        if (array_key_exists($yamlKey, $yaml)) {
            return filter_var($yaml[$yamlKey], FILTER_VALIDATE_BOOLEAN);
        }

        $env = self::readEnv($envKey);
        if ($env !== null) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        return $default;
    }

    public static function pickBoolEnvFirst(array $yaml, string $yamlKey, string $envKey, bool $default): bool
    {
        $env = self::readEnv($envKey);
        if ($env !== null) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists($yamlKey, $yaml)) {
            return filter_var($yaml[$yamlKey], FILTER_VALIDATE_BOOLEAN);
        }

        return $default;
    }

    /**
     * 从 .env / 进程环境读取（经 env()）。
     */
    private static function readEnv(string $key): mixed
    {
        $value = env($key);
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
