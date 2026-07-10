<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Router\RouterProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Swoolefy\Support\Neuron\Http\NeuronHttpFactory;
use Swoolefy\Support\Workflow\Exception\WorkflowException;

/**
 * Neuron AI Provider 工厂。
 *
 * 主要职责：
 * 1. 从 `Config/neuron_ai.php` 的 `neuron.ai_model_providers` 读取 Provider 声明；
 * 2. 按别名实例化 NeuronAI 官方 Provider（OpenAI / Anthropic / Deepseek / OpenAILike 等）；
 * 3. 为 Provider 注入 Swoolefy 统一 HTTP Client（默认 Swoole 协程化 Guzzle）；
 * 4. 校验 Provider 出站 `baseUri`，避免绕过 `security.outbound_url_allowlist`；
 * 5. 当配置了 `neuron.provider_fallback.order` 时，用官方 `RouterProvider` 组合默认 Provider 与备用 Provider。
 *
 * 配置约定：
 * - `ai_model_providers.{alias}.provider` 必须是 Provider FQCN；
 * - 除 `provider` 外，其余键名与该 Provider 构造函数参数一一对应；
 * - `default_provider` 是默认主 Provider；
 * - `provider_fallback.order` 只声明备用 Provider，主 Provider 永远来自 `default_provider`。
 *
 * Fallback 行为由 `neuron-core/router` 的默认策略负责：
 * - 只对网络/超时、HTTP 429、HTTP 5xx 等瞬时错误切换；
 * - 401/403、参数错误等确定性错误不会切换；
 * - stream() 只会在首个 chunk 输出前失败时切换，输出开始后的错误会原样抛出。
 *
 * @see RouterProvider
 */
final class NeuronProviderFactory
{
    public function __construct(
        private readonly ?NeuronAiConfig $config = null,
    ) {
    }

    /**
     * 创建默认 Provider。
     *
     * 未配置 fallback 时：
     * - 优先使用 `neuron.default_provider`；
     * - 若默认 Provider 缺少 key/model 等必要凭证，则按 `ai_model_providers` 声明顺序尝试其它 Provider；
     * - 全部不可用时返回 null，由 `NeuronFactory` 决定是否抛错。
     *
     * 配置了 `provider_fallback.order` 时：
     * - 返回 `RouterProvider`；
     * - 候选链固定为 `default_provider` → `provider_fallback.order...`；
     * - fallback 只在推理调用阶段发生，不在这里主动探测远端服务健康。
     */
    public function createDefault(): ?AIProviderInterface
    {
        $config = $this->neuronConfig();
        $fallbackOrder = $config->providerFallbackOrder();
        if ($fallbackOrder !== []) {
            return $this->createFallbackProvider($this->fallbackProviderAliases($config, $fallbackOrder));
        }

        $aliases = $this->defaultProviderAliases($config);

        foreach ($aliases as $alias) {
            try {
                $provider = $this->createFromAlias($alias);
            } catch (WorkflowException) {
                continue;
            }

            if ($provider instanceof AIProviderInterface) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * 默认 Provider 的构造期候选顺序。
     *
     * 这是非 RouterProvider 模式下的“配置可用性兜底”：如果默认别名因未配置凭证返回 null，
     * 则继续尝试其它已声明 Provider。它不是运行时 LLM 调用失败后的 fallback。
     *
     * @return list<string>
     */
    private function defaultProviderAliases(NeuronAiConfig $config): array
    {
        $aliases = [];

        $default = $config->defaultProviderName();
        if ($default !== '') {
            $aliases[] = $default;
        }

        foreach (array_keys($config->aiModelProviders()) as $name) {
            $name = (string) $name;
            if ($name !== '' && !in_array($name, $aliases, true)) {
                $aliases[] = $name;
            }
        }

        return $aliases;
    }

    /**
     * RouterProvider 候选顺序。
     *
     * 这里有意不允许 `provider_fallback.order` 覆盖主 Provider：
     * - 主 Provider 由 `default_provider` 表达；
     * - `order` 只表达备用链路；
     * - 如果用户把 default_provider 也写进 order，会自动去重，避免重复注册。
     *
     * @param list<string> $fallbackOrder
     *
     * @return list<string>
     */
    private function fallbackProviderAliases(NeuronAiConfig $config, array $fallbackOrder): array
    {
        $aliases = [];
        $default = $config->defaultProviderName();
        if ($default !== '') {
            $aliases[] = $default;
        }

        foreach ($fallbackOrder as $alias) {
            if ($alias !== '' && !in_array($alias, $aliases, true)) {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    /**
     * 创建 RouterProvider fallback chain。
     *
     * 只注册成功实例化且具备必要凭证的 Provider：
     * - 某个备用 Provider 缺少 key/model 时会被跳过；
     * - 如果最终只有一个 Provider 可用，则直接返回该 Provider，避免 RouterProvider 缺少备用节点；
     * - 两个及以上 Provider 可用时才设置 `setFallbackOrder()`。
     *
     * 注意：每个子 Provider 在 `createFromAlias()` → `createFromParams()` 阶段已经注入
     * `NeuronHttpFactory::create()`。不要在这里对 RouterProvider 再调用裸 `setHttpClient()`，
     * 否则可能覆盖 Provider 构造函数中带 baseUri / Authorization 的 client 包装。
     *
     * @param list<string> $aliases
     */
    private function createFallbackProvider(array $aliases): ?AIProviderInterface
    {
        if (!class_exists(RouterProvider::class)) {
            throw new WorkflowException(
                'Provider fallback requires neuron-core/router. Install it with: composer require neuron-core/router',
            );
        }

        $router = RouterProvider::make();
        $registered = [];
        $singleProvider = null;

        foreach ($aliases as $alias) {
            $provider = $this->createFromAlias($alias);
            if (!$provider instanceof AIProviderInterface) {
                continue;
            }

            $router->addProvider($alias, $provider);
            $registered[] = $alias;
            $singleProvider ??= $provider;
        }

        if ($registered === []) {
            return null;
        }

        if (count($registered) === 1) {
            return $singleProvider;
        }

        try {
            $router
                ->setFallbackOrder(...$registered)
                ->setDefaultProvider($registered[0]);
        } catch (\Throwable $e) {
            throw new WorkflowException('Invalid provider fallback configuration: ' . $e->getMessage(), 0, $e);
        }

        return $router;
    }

    /**
     * 从 Agent 启动选项创建 Provider。
     *
     * 调用方 `provider` / `provider_name` 是显式选择，优先级高于默认 Provider 与 fallback：
     * - 传入 provider 时，只创建该别名对应的 Provider；
     * - 不自动套 RouterProvider，避免业务明确指定的模型被隐藏改写；
     * - `provider_params` 可覆盖构造参数；未设置时会从 agentOptions 中提取非框架保留键作为覆盖参数。
     *
     * @param array<string, mixed> $agentOptions
     */
    public function createFromAgentOptions(array $agentOptions): ?AIProviderInterface
    {
        $alias = $agentOptions['provider'] ?? $agentOptions['provider_name'] ?? null;
        if (!is_string($alias) || $alias === '') {
            return null;
        }

        $overrides = is_array($agentOptions['provider_params'] ?? null)
            ? $agentOptions['provider_params']
            : $this->extractParamOverrides($agentOptions);

        return $this->createFromAlias($alias, $overrides);
    }

    /**
     * 按 `ai_model_providers` 别名实例化 Provider。
     *
     * 该方法只负责别名解析和参数合并：
     * - alias 不存在或 provider FQCN 无效时抛 `WorkflowException`；
     * - `$overrides` 会覆盖配置中的同名构造参数，例如调用方覆盖 model；
     * - 实际构造、凭证校验、HTTP client 注入交给 `createFromParams()`。
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
     * 直接使用构造参数实例化 Provider（单测 / 脚本 / 内部复用）。
     *
     * 执行顺序：
     * 1. 校验目标类必须实现 `AIProviderInterface`；
     * 2. 校验必要凭证，普通远程 Provider 必须有 key + model，Ollama 只要求 model；
     * 3. 如果配置了 baseUri / base_uri，先经过 `OutboundUrlGuard`；
     * 4. 如构造函数支持 `httpClient` 且调用方未传，则注入 `NeuronHttpFactory::create()`；
     * 5. 通过反射按构造函数参数名组装实例。
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

        $baseUri = $params['baseUri'] ?? $params['base_uri'] ?? null;
        if (is_string($baseUri) && $baseUri !== '') {
            // Phase B：Provider baseUri 须通过 OutboundUrlGuard（security.outbound_url_allowlist）
            $this->neuronConfig()->outboundUrlGuard()->assertAllowed($baseUri, 'provider:' . $class);
        }

        $params = $this->injectHttpClient($class, $params);

        return $this->instantiate($class, $params);
    }

    /**
     * 判断 Agent 子类是否自行实现了 `provider()`。
     *
     * Neuron 官方 Agent 基类本身有默认 provider()，如果业务子类覆盖了该方法，
     * 说明业务希望完全自行控制 Provider，框架不应再注入默认 Provider 或 fallback chain。
     */
    public static function agentDeclaresCustomProvider(string $agentClass): bool
    {
        if (!is_subclass_of($agentClass, Agent::class)) {
            return false;
        }

        $method = new ReflectionMethod($agentClass, 'provider');

        return $method->getDeclaringClass()->getName() !== Agent::class;
    }

    /**
     * 通过反射调用 Provider 构造函数。
     *
     * Provider 配置采用“参数名绑定”方式，因此配置文件里的 key 必须和构造函数参数同名。
     * 缺少必填参数时 fail-fast，避免在真正请求 LLM 时才暴露配置错误。
     *
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
     * 为支持 HTTP client 注入的 Provider 自动传入框架统一 client。
     *
     * Neuron 官方 Provider 通常在构造函数接收 `?HttpClientInterface $httpClient`，
     * 然后在构造内部追加 baseUri / headers / Authorization。这里传入的是“底层 client”，
     * Provider 自己仍会完成认证头和 baseUri 包装。
     *
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
     * 判断 Provider 是否具备最小可用凭证。
     *
     * 这里做的是本地配置层面的轻量校验：
     * - OpenAI / Anthropic / Deepseek / OpenAILike 等远程 Provider：要求 key + model；
     * - Ollama 通常是本地服务，不需要 API key，只要求 model；
     * - 远端 401、额度不足、模型不存在等运行时错误不在这里探测。
     *
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
     * 从 Agent 启动选项（常来自 AINode 配置）提取可覆盖的构造参数（排除框架保留键）。
     *
     * 这样调用方可以直接声明 `model`、`parameters`、`strict_response` 等 Provider 构造参数，
     * 但不会把 `agent`、`memory`、`mcpServers` 等工作流/Agent 框架字段误传给 Provider。
     *
     * @param array<string, mixed> $agentOptions
     *
     * @return array<string, mixed>
     */
    private function extractParamOverrides(array $agentOptions): array
    {
        $reserved = [
            'provider', 'provider_name', 'provider_params', 'agent', 'memory', 'stream',
            'structured', 'outputKey', 'promptKey', 'threadIdKey', 'contextWindow',
            'timeout', 'executor', 'mcpServers', 'mcp', 'mcpOnly', 'mcpExclude',
            'middleware', 'globalMiddleware', 'chatHistory',
            'capabilityEnabled', 'capabilityQuery', 'capabilityTopK', 'capabilityProfile',
            'capabilityTags', 'profileTags', 'pinnedTools', 'pinnedToolIds',
            'tenantId', 'agentId', 'roles', 'userRoles', 'message', 'prompt', '_stateMessage',
        ];

        $overrides = [];
        foreach ($agentOptions as $key => $value) {
            if (!is_string($key) || in_array($key, $reserved, true)) {
                continue;
            }
            $overrides[$key] = $value;
        }

        return $overrides;
    }

    /**
     * 按构造函数类型提示转换参数。
     *
     * 当前主要处理 `HttpClientInterface`：如果配置里传了非实例值，统一替换为框架 HTTP client。
     * 其它对象类型暂不自动容器解析，避免在 Provider 构造阶段引入隐式依赖。
     */
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

    /** 获取配置对象；构造注入优先，未注入时按运行环境加载 `neuron_ai.php`。 */
    private function neuronConfig(): NeuronAiConfig
    {
        return $this->config ?? NeuronAiConfig::load();
    }
}
