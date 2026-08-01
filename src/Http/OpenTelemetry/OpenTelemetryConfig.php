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

namespace Swoolefy\Http\OpenTelemetry;

use Swoolefy\Support\ApplicationConfig;

/**
 * HTTP OpenTelemetry 最小采集配置（APP_PATH/Config/otel.php）。
 *
 * 全局采集总开关仍由 `.env` 的 `OTEL_PHP_AUTOLOAD_ENABLED` 控制（见 HttpAppServer）。
 * 本配置只管采集内容：敏感字段脱敏、attribute 最大长度。
 *
 * 模版：`src/Stubs/otel.conf.stub.php`。
 */
final class OpenTelemetryConfig
{
    /**
     * @param array<string, mixed> $config
     */
    private function __construct(
        private readonly array $config,
    ) {
    }

    public static function load(): self
    {
        return new self(ApplicationConfig::loadPhpConfig('otel.php'));
    }

    /**
     * 内存注入（单测），不读磁盘。
     *
     * @param array<string, mixed> $config
     *
     * @internal
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * @return array<string, mixed>
     */
    private function section(): array
    {
        $section = $this->config['otel'] ?? $this->config;

        return is_array($section) ? $section : [];
    }

    /**
     * 是否对 header/body/query 中的敏感字段强制脱敏。默认启用。
     *
     * env: `OTEL_ATTRIBUTE_SANITIZE_ENABLED`
     */
    public function isSanitizeEnabled(): bool
    {
        return ApplicationConfig::pickBoolEnvFirst(
            $this->section(),
            'sanitize_enabled',
            'OTEL_ATTRIBUTE_SANITIZE_ENABLED',
            true,
        );
    }

    /**
     * 所有 attribute 字符串最大长度；超出截断并标记。
     * 未设置、空或 ≤0 表示不限制。
     *
     * env: `OTEL_ATTRIBUTE_MAX_LENGTH`
     */
    public function attributeMaxLength(): ?int
    {
        $env = env('OTEL_ATTRIBUTE_MAX_LENGTH');
        if ($env !== null && $env !== '' && is_numeric($env)) {
            $value = (int) $env;

            return $value > 0 ? $value : null;
        }

        $section = $this->section();
        if (array_key_exists('attribute_max_length', $section)
            && $section['attribute_max_length'] !== null
            && $section['attribute_max_length'] !== ''
            && is_numeric($section['attribute_max_length'])
        ) {
            $value = (int) $section['attribute_max_length'];

            return $value > 0 ? $value : null;
        }

        return null;
    }

    /**
     * 是否采集 request body。默认采集。
     *
     * env: `OTEL_COLLECT_REQUEST_BODY`
     */
    public function collectRequestBody(): bool
    {
        return ApplicationConfig::pickBoolEnvFirst(
            $this->section(),
            'collect_request_body',
            'OTEL_COLLECT_REQUEST_BODY',
            true,
        );
    }
}
