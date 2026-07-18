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
 * 应用配置入口：application.yaml + APP_PATH/Config/*.php。
 *
 * PHP 配置目录与 Core {@see \Swoolefy\Core\SystemEnv} / create 脚手架一致，
 * 优先使用已定义的 CONFIG_PATH，否则回退 APP_PATH/Config。
 *
 * 注意：加载 workflow.php / neuron_ai.php / job.php 等**不依赖** application.yaml；
 * yaml 仅用于 Nacos 等声明式开关（{@see isEnableNacosRegister()}）。
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

    /**
     * PHP 配置根目录：CONFIG_PATH（若已定义）或 APP_PATH/Config。
     *
     * 与 SystemEnv、CreateCmd、constants.php 保持一致（大小写敏感文件系统上不可用小写 config/）。
     *
     * @throws \RuntimeException CONFIG_PATH 与 APP_PATH 均未定义时
     */
    public static function resolveConfigPath(): string
    {
        if (defined('CONFIG_PATH') && '' !== (string) CONFIG_PATH) {
            return rtrim((string) CONFIG_PATH, '/');
        }

        if (!defined('APP_PATH') || '' === (string) APP_PATH) {
            throw new \RuntimeException('Neither CONFIG_PATH nor APP_PATH is defined');
        }

        return self::resolveAppPath() . '/Config';
    }

    /** 是否已具备解析 Config 目录的路径常量（CONFIG_PATH 或 APP_PATH）。 */
    public static function hasConfigPathContext(): bool
    {
        if (defined('CONFIG_PATH') && '' !== (string) CONFIG_PATH) {
            return true;
        }

        return defined('APP_PATH') && '' !== (string) APP_PATH;
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
     * 加载 Config/{filename} 返回的 PHP 配置数组。
     *
     * 不依赖 application.yaml。无 APP_PATH/CONFIG_PATH、或文件不存在时返回 []；
     * require / 语法错误时记录日志。
     * 生产环境（{@see \Swoolefy\Core\SystemEnv::isPrdEnv()}）重新抛出，避免静默回退到危险默认值。
     *
     * @return array<string, mixed>
     *
     * @throws \Throwable 生产环境下配置加载失败时抛出
     */
    public static function loadPhpConfig(string $filename): array
    {
        // CLI 单测等未 bootstrap 场景：与旧 yaml 闸门「无上下文 → []」兼容，避免硬抛
        if (!self::hasConfigPathContext()) {
            return [];
        }

        $configFile = self::resolveConfigPath() . '/' . ltrim($filename, '/');
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

    /**
     * Nacos 注册开关：仅当存在 application.yaml 且 nacos.enable_nacos_register 为真时启用。
     */
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

        if (array_key_exists($yamlKey, $yaml)) {
            $value = $yaml[$yamlKey];
            // bool false → '' under (string) cast; keep explicit false as "0" for FILTER_VALIDATE_BOOLEAN
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if ($value !== null && '' !== (string) $value) {
                return (string) $value;
            }
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
