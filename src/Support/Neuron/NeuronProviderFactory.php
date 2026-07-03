<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Swoolefy\Support\Neuron\Http\NeuronHttpFactory;
use Swoolefy\Support\Workflow\Exception\WorkflowException;

/**
 * 从 neuron_ai.php → ai_model_providers 实例化 NeuronAI\Providers。
 *
 * 除 provider（FQCN）外，其余键名与对应 Provider 构造函数参数一一对应。
 */
final class NeuronProviderFactory
{
    public function __construct(
        private readonly ?NeuronAiConfig $config = null,
    ) {
    }

    /** 使用 default_provider 别名创建；缺少 key/model 时返回 null。 */
    public function createDefault(): ?AIProviderInterface
    {
        $alias = $this->neuronConfig()->defaultProviderName();
        if ($alias === '') {
            return null;
        }

        return $this->createFromAlias($alias);
    }

    /**
     * AINode 节点级覆盖：provider 为 ai_model_providers 别名，其余键合并进构造参数。
     *
     * @param array<string, mixed> $nodeConfig
     */
    public function createFromNodeConfig(array $nodeConfig): ?AIProviderInterface
    {
        $alias = $nodeConfig['provider'] ?? $nodeConfig['provider_name'] ?? null;
        if (!is_string($alias) || $alias === '') {
            return null;
        }

        $overrides = is_array($nodeConfig['provider_params'] ?? null)
            ? $nodeConfig['provider_params']
            : $this->extractParamOverrides($nodeConfig);

        return $this->createFromAlias($alias, $overrides);
    }

    /**
     * 按 ai_model_providers 别名实例化 Provider。
     *
     * @param array<string, mixed> $overrides 覆盖构造参数（如 model）
     */
    public function createFromAlias(string $alias, array $overrides = []): ?AIProviderInterface
    {
        $providerConfig = $this->neuronConfig()->providerConfig($alias);
        if ($providerConfig === null) {
            throw new WorkflowException("Unknown ai_model_providers alias: {$alias}");
        }

        $class = (string) ($providerConfig['provider'] ?? '');
        if ($class === '' || !class_exists($class)) {
            throw new WorkflowException("Invalid provider class for alias {$alias}");
        }

        $params = $providerConfig;
        unset($params['provider']);
        $params = array_replace_recursive($params, $overrides);

        return $this->createFromParams($class, $params);
    }

    /**
     * 直接使用构造参数实例化（单测 / 脚本）。
     *
     * @param class-string<AIProviderInterface> $class
     * @param array<string, mixed>              $params
     */
    public function createFromParams(string $class, array $params): ?AIProviderInterface
    {
        if (!is_subclass_of($class, AIProviderInterface::class) && $class !== AIProviderInterface::class) {
            throw new WorkflowException("{$class} must implement AIProviderInterface");
        }

        if (!$this->hasRequiredCredentials($class, $params)) {
            return null;
        }

        $params = $this->injectHttpClient($class, $params);

        return $this->instantiate($class, $params);
    }

    /** Agent 子类若自行实现 provider()，则不应注入默认 Provider。 */
    public static function agentDeclaresCustomProvider(string $agentClass): bool
    {
        if (!is_subclass_of($agentClass, Agent::class)) {
            return false;
        }

        $method = new ReflectionMethod($agentClass, 'provider');

        return $method->getDeclaringClass()->getName() !== Agent::class;
    }

    /**
     * @param class-string<AIProviderInterface> $class
     * @param array<string, mixed>              $params
     */
    private function instantiate(string $class, array $params): AIProviderInterface
    {
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $ref->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $params)) {
                $args[] = $this->castArgument($params[$name], $parameter);
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }
            throw new WorkflowException("Missing constructor argument [{$name}] for provider {$class}");
        }

        /** @var AIProviderInterface $instance */
        $instance = $ref->newInstanceArgs($args);

        return $instance;
    }

    /**
     * @param class-string<AIProviderInterface> $class
     * @param array<string, mixed>              $params
     *
     * @return array<string, mixed>
     */
    private function injectHttpClient(string $class, array $params): array
    {
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $params;
        }

        foreach ($ctor->getParameters() as $parameter) {
            if ($parameter->getName() !== 'httpClient') {
                continue;
            }
            if (!array_key_exists('httpClient', $params) || $params['httpClient'] === null) {
                $params['httpClient'] = NeuronHttpFactory::create();
            }
            break;
        }

        return $params;
    }

    /**
     * @param class-string<AIProviderInterface> $class
     * @param array<string, mixed>              $params
     */
    private function hasRequiredCredentials(string $class, array $params): bool
    {
        if ($class === Ollama::class || is_subclass_of($class, Ollama::class)) {
            $model = (string) ($params['model'] ?? '');

            return $model !== '';
        }

        $key = (string) ($params['key'] ?? '');
        $model = (string) ($params['model'] ?? '');

        return $key !== '' && $model !== '';
    }

    /**
     * 从 AINode 节点配置提取可覆盖的构造参数（排除框架保留键）。
     *
     * @param array<string, mixed> $nodeConfig
     *
     * @return array<string, mixed>
     */
    private function extractParamOverrides(array $nodeConfig): array
    {
        $reserved = [
            'provider', 'provider_name', 'provider_params', 'agent', 'memory', 'stream',
            'structured', 'outputKey', 'promptKey', 'threadIdKey', 'contextWindow',
            'timeout', 'executor', 'mcpServers', 'mcp', 'mcpOnly', 'mcpExclude',
        ];

        $overrides = [];
        foreach ($nodeConfig as $key => $value) {
            if (!is_string($key) || in_array($key, $reserved, true)) {
                continue;
            }
            $overrides[$key] = $value;
        }

        return $overrides;
    }

    private function castArgument(mixed $value, \ReflectionParameter $parameter): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $value;
        }

        if ($type->getName() === HttpClientInterface::class && !$value instanceof HttpClientInterface) {
            return NeuronHttpFactory::create();
        }

        return $value;
    }

    private function neuronConfig(): NeuronAiConfig
    {
        return $this->config ?? NeuronAiConfig::load();
    }
}
