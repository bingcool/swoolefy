<?php

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

        $env = getenv($envKey);
        if (false !== $env && '' !== $env) {
            return (string) $env;
        }

        return $default;
    }

    public static function pickInt(array $yaml, string $yamlKey, string $envKey, int $default): int
    {
        if (array_key_exists($yamlKey, $yaml) && is_numeric($yaml[$yamlKey])) {
            return (int) $yaml[$yamlKey];
        }

        $env = getenv($envKey);
        if (false !== $env && is_numeric($env)) {
            return (int) $env;
        }

        return $default;
    }

    public static function pickBool(array $yaml, string $yamlKey, string $envKey, bool $default): bool
    {
        if (array_key_exists($yamlKey, $yaml)) {
            return filter_var($yaml[$yamlKey], FILTER_VALIDATE_BOOLEAN);
        }

        $env = getenv($envKey);
        if (false !== $env) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        return $default;
    }
}
