<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Support\Neuron\Http\NeuronHttpFactory;

/**
 * Neuron AI / RAG / MCP 模块配置加载器。
 *
 * 读取 APP_PATH/config/neuron_ai.php（可选），环境变量优先。
 */
final class NeuronAiConfig
{
    /** @param array<string, mixed> $config */
    private function __construct(
        private readonly array $config,
    ) {
    }

    public static function load(): self
    {
        return new self(ApplicationConfig::loadPhpConfig('neuron_ai.php'));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @internal 单测 / 脚本注入
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /** @return array<string, mixed> */
    public function ragSection(): array
    {
        return (array) ($this->config['rag'] ?? []);
    }

    /** @return array<string, mixed> */
    public function mcpSection(): array
    {
        return (array) ($this->config['mcp'] ?? []);
    }

    /** @return array<string, mixed> */
    public function neuronSection(): array
    {
        return (array) ($this->config['neuron'] ?? []);
    }

    public function vectorStoreDriver(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->ragSection(),
            'vector_store',
            'RAG_VECTOR_STORE',
            'file',
        );
    }

    public function fileStorePath(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->ragSection(),
            'file_store_path',
            'RAG_FILE_STORE_PATH',
            sys_get_temp_dir() . '/swoolefy_rag',
        );
    }

    public function defaultTopK(): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->ragSection(),
            'default_top_k',
            'RAG_DEFAULT_TOP_K',
            5,
        );
    }

    public function embeddingModel(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->ragSection(),
            'embedding_model',
            'RAG_EMBEDDING_MODEL',
            'text-embedding-3-small',
        );
    }

    public function maxLocalProcesses(): int
    {
        return max(1, ApplicationConfig::pickIntEnvFirst(
            $this->mcpSection(),
            'max_local_processes',
            'MCP_MAX_LOCAL_PROCESSES',
            2,
        ));
    }

    public function httpClient(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->neuronSection(),
            'http_client',
            NeuronHttpFactory::ENV_HTTP_CLIENT,
            NeuronHttpFactory::CLIENT_SWOOLE,
        );
    }

    /** 默认 Provider 别名（ai_model_providers 的 key）。 */
    public function defaultProviderName(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->neuronSection(),
            'default_provider',
            'NEURON_DEFAULT_PROVIDER',
            NeuronAiProviderName::ANTHROPIC,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function aiModelProviders(): array
    {
        $providers = $this->neuronSection()['ai_model_providers'] ?? [];

        return is_array($providers) ? $providers : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function providerConfig(string $alias): ?array
    {
        $config = $this->aiModelProviders()[$alias] ?? null;

        return is_array($config) ? $config : null;
    }
}
