<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Providers\AIProviderInterface;
use Swoolefy\Support\CapabilityCenter\CapabilityComponentFactory;
use Swoolefy\Support\CapabilityCenter\Resolver\ToolResolveContext;
use Swoolefy\Support\FrameworkContext;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\SupportLog;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Throwable;

/**
 * Neuron Agent 工厂 —— 统一注入 Provider 与 Tool。
 *
 * 职责：
 * 1. 创建或 boot Neuron Agent 实例；
 * 2. 注入 LLM Provider（节点 alias 或 default_provider，含 RouterProvider fallback）；
 * 3. 挂载 Tool（MCP 全量 或 CapabilityCenter Top-K 动态筛选）；
 * 4. 可选覆盖 ChatHistory。
 *
 * Tool 挂载策略：
 * - CAPABILITY_ENABLED=false（默认）：走 attachMcpTools() 全量加载 MCP Tools；
 * - CAPABILITY_ENABLED=true：走 CapabilityCenter 解析 Top-K + pinned，再懒加载注入；
 * - Capability 出错且 fail_closed=false：fail-open 回退旧 MCP 全量挂载。
 *
 * 会话记忆由业务 Agent 自行实现 {@see Agent::chatHistory()}；
 * 仅当 agentOptions['chatHistory'] 显式传入时才覆盖。
 *
 * 多租户：
 *   tenantId 解析顺序 = agentOptions['tenantId'] → FrameworkContext::getTenantId()
 *   传给 McpFactory / CapabilityCenter，驱动 DB 仓储按租户过滤配置。
 *
 * @see https://docs.neuron-ai.dev/agent/chat-history-and-memory
 * @see docs/CapabilityTool.md
 */
final class NeuronFactory
{
    /**
     * @param McpFactory|null                  $mcpFactory        MCP 配置与安全链；null 时不挂载 MCP Tool
     * @param callable|null                    $agentFactory      单测/mock 注入点，非 null 时跳过真实构造
     * @param NeuronProviderFactory|null       $providerFactory   LLM Provider 工厂；null 时使用默认实例
     * @param CapabilityComponentFactory|null  $capabilityFactory Capability 装配工厂；null 时按需临时创建
     * @param NeuronAiConfig|null              $config            neuron_ai 配置；null 时 load()
     */
    public function __construct(
        private readonly ?McpFactory $mcpFactory = null,
        private $agentFactory = null,
        private readonly ?NeuronProviderFactory $providerFactory = null,
        private readonly ?CapabilityComponentFactory $capabilityFactory = null,
        private readonly ?NeuronAiConfig $config = null,
    ) {
    }

    /**
     * 构造 Agent 并完成 boot（Provider + Tool + 可选 ChatHistory）。
     *
     * 流程：agentFactory 注入 → new Agent → agentOptionsWithState → boot。
     * WorkflowState 中的 message/prompt 会合并进 agentOptions，供 Capability tag 匹配。
     *
     * @param class-string<Agent>  $agentClass Agent 类名
     * @param WorkflowState        $state      工作流状态（用于提取 query）
     * @param array<string, mixed> $agentOptions provider / mcpServers / capabilityEnabled 等
     */
    public function create(string $agentClass, WorkflowState $state, array $agentOptions = []): Agent
    {
        // 单测/mock 注入点：完全接管 Agent 创建，跳过真实 boot
        if ($this->agentFactory !== null) {
            return ($this->agentFactory)($agentClass, $state, $agentOptions);
        }

        /** @var Agent $agent */
        $agent = new $agentClass();

        return $this->boot($agent, $this->agentOptionsWithState($agentOptions, $state));
    }

    /**
     * 对已构造的 Agent 注入 Provider、ChatHistory、Tool。
     *
     * boot 顺序：
     * 1. applyProvider — 注入 LLM；
     * 2. setChatHistory — 仅 agentOptions 显式传入时覆盖；
     * 3. attachTools   — MCP 全量 或 CapabilityCenter 动态筛选。
     *
     * @param array<string, mixed> $agentOptions
     */
    public function boot(Agent $agent, array $agentOptions = []): Agent
    {
        $agentClass = $agent::class;
        $this->applyProvider($agent, $agentClass, $agentOptions);

        // 显式传入 ChatHistory 时覆盖 Agent 自身 chatHistory() 声明
        if (($agentOptions['chatHistory'] ?? null) instanceof ChatHistoryInterface) {
            $agent->setChatHistory($agentOptions['chatHistory']);
        }

        $this->attachTools($agent, $agentOptions);

        return $agent;
    }

    /**
     * 注入 LLM Provider。
     *
     * 解析优先级：
     * 1. agentOptions['provider'] → NeuronProviderFactory::createFromAgentOptions()；
     * 2. 若 Agent 未自定义 provider() → createDefault()（含 RouterProvider fallback）；
     * 3. 仍无 Provider 且 Agent 未声明自定义 provider → 抛 WorkflowException。
     *
     * Agent 自身实现了 provider() 且无可用配置 Provider 时，保留 Agent 内置逻辑不抛错。
     */
    private function applyProvider(Agent $agent, string $agentClass, array $agentOptions): void
    {
        $factory = $this->providerFactory ?? new NeuronProviderFactory();

        // 优先使用 agentOptions 指定的 provider alias
        $provider = $factory->createFromAgentOptions($agentOptions);

        // 节点未指定且 Agent 未自定义 provider() 时，使用 default_provider
        if ($provider === null && !NeuronProviderFactory::agentDeclaresCustomProvider($agentClass)) {
            $provider = $factory->createDefault();
        }

        if ($provider instanceof AIProviderInterface) {
            $agent->setAiProvider($provider);

            return;
        }

        // Agent 未自定义 provider() 且无法解析任何 Provider 时 fail-fast
        if (!NeuronProviderFactory::agentDeclaresCustomProvider($agentClass)) {
            throw new WorkflowException(
                'No AI provider available. Configure API key and model for neuron.default_provider '
                . '(or any ai_model_providers entry) in neuron_ai.php / env, or pass "provider" in the request.',
            );
        }
    }

    /**
     * 旧链路：全量挂载 MCP Tools 到 Agent。
     *
     * 当 CAPABILITY_ENABLED=false 或 Capability fail-open 回退时走此路径。
     * 通过 McpFactory::tools() 加载声明 server 的全部 Tool，再 Agent::addTool()。
     *
     * agentOptions 键：
     *   mcpServers / mcp   — Server 名列表
     *   mcpOnly / mcpExclude — 按 Server 静态过滤 tool
     *   tenantId           — 显式租户；缺省时读 FrameworkContext
     */
    private function attachMcpTools(Agent $agent, array $agentOptions): void
    {
        if ($this->mcpFactory === null) {
            return;
        }

        $servers = $agentOptions['mcpServers'] ?? $agentOptions['mcp'] ?? null;
        // 未声明 MCP server 时不挂载任何 Tool
        if (!is_array($servers) || $servers === []) {
            return;
        }

        $only = is_array($agentOptions['mcpOnly'] ?? null) ? $agentOptions['mcpOnly'] : null;
        $exclude = is_array($agentOptions['mcpExclude'] ?? null) ? $agentOptions['mcpExclude'] : null;
        $tenantId = $agentOptions['tenantId'] ?? FrameworkContext::getTenantId();

        // 全量加载：McpFactory 负责 stdio 守卫、URL 守卫、进程限流
        $tools = $this->mcpFactory->tools($servers, $only, $exclude, is_string($tenantId) ? $tenantId : null);
        if ($tools !== []) {
            $agent->addTool($tools);
        }
    }

    /**
     * Tool 挂载总入口：CapabilityCenter 或旧 MCP 全量。
     *
     * 开关解析：agentOptions['capabilityEnabled'] 优先于全局 CAPABILITY_ENABLED。
     *
     * Capability 路径：
     * 1. 组装 ToolResolveContext（query / topK / pinned / mcpServers 等）；
     * 2. CapabilityComponentFactory → CapabilityCenter → resolveTools()；
     * 3. Agent::addTool() 注入命中的 ToolInterface[]。
     *
     * 失败策略：
     * - fail_closed=true → 抛异常，Agent 创建失败；
     * - fail_closed=false → warning 日志 + 回退 attachMcpTools()。
     */
    private function attachTools(Agent $agent, array $agentOptions): void
    {
        $config = $this->config ?? NeuronAiConfig::load();

        // agentOptions 可 per-call 覆盖全局 capability 开关
        $enabled = $this->agentOptionsBool($agentOptions, 'capabilityEnabled', $config->capabilityEnabled());
        if (!$enabled) {
            $this->attachMcpTools($agent, $agentOptions);

            return;
        }

        try {
            $tenantId = $agentOptions['tenantId'] ?? FrameworkContext::getTenantId();
            $mcpServers = $this->normalizeStringList($agentOptions['mcpServers'] ?? $agentOptions['mcp'] ?? []);

            // 优先使用注入的单例 factory（生产推荐 Worker 级复用 Registry）
            /**
             * @see new NeuronFactory 时注入
             * new NeuronFactory(
             *   capabilityFactory: xxxx
             *   config: $config,
             * );
             */
            /**
             * @var $factory CapabilityComponentFactory
             */
            $factory = $this->capabilityFactory ?? new CapabilityComponentFactory($this->mcpFactory, $config);
            // 获取 ToolInterface[],动态筛选出可能满足的 MCP Tool
            $tools = $factory
                ->capabilityCenter($mcpServers, is_string($tenantId) ? $tenantId : null)
                ->resolveTools($this->toolResolveContext($agent, $agentOptions, $config));

            if ($tools !== []) {
                $agent->addTool($tools);
            }
        } catch (Throwable $e) {
            SupportLog::warning('capability', 'CapabilityCenter attach failed', [
                'agent' => $agent::class,
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'failClosed' => $config->capabilityFailClosed(),
            ]);

            // 严格模式：Capability 失败即中断 Agent 创建
            if ($config->capabilityFailClosed()) {
                throw $e;
            }

            // fail-open：回退旧 MCP 全量挂载，保证 Agent 仍可用
            $this->attachMcpTools($agent, $agentOptions);
        }
    }

    /**
     * 从 agentOptions / FrameworkContext 组装 Capability 解析上下文。
     *
     * 该对象是请求态，携带 query、tenant、roles、pinned、mcpServers 等运行时信息，
     * 供 CompositeToolResolver 做 policy 过滤与 tag 匹配。
     */
    private function toolResolveContext(Agent $agent, array $agentOptions, NeuronAiConfig $config): ToolResolveContext
    {
        $tenantId = $agentOptions['tenantId'] ?? FrameworkContext::getTenantId();

        return new ToolResolveContext(
            query: $this->resolveCapabilityQuery($agentOptions),
            agentId: (string) ($agentOptions['agentId'] ?? $agent::class),
            tenantId: is_string($tenantId) ? $tenantId : null,
            userId: FrameworkContext::getUserId(),
            roles: $this->normalizeStringList($agentOptions['roles'] ?? $agentOptions['userRoles'] ?? []),
            pinnedToolIds: $this->normalizeStringList($agentOptions['pinnedTools'] ?? $agentOptions['pinnedToolIds'] ?? []),
            mcpServers: $this->normalizeStringList($agentOptions['mcpServers'] ?? $agentOptions['mcp'] ?? []),
            capabilityProfile: isset($agentOptions['capabilityProfile']) && is_string($agentOptions['capabilityProfile'])
                ? $agentOptions['capabilityProfile']
                : null,
            profileTags: $this->normalizeStringList($agentOptions['profileTags'] ?? $agentOptions['capabilityTags'] ?? []),
            // agentOptions 可 per-call 覆盖 default_top_k
            topK: isset($agentOptions['capabilityTopK']) ? max(0, (int) $agentOptions['capabilityTopK']) : $config->capabilityDefaultTopK(),
            // mcpOnly/mcpExclude 下沉为 Policy 阶段静态限制
            mcpOnly: $this->normalizeToolMap($agentOptions['mcpOnly'] ?? []),
            mcpExclude: $this->normalizeToolMap($agentOptions['mcpExclude'] ?? []),
        );
    }

    /**
     * 解析 Capability tag 匹配用的 query 文本。
     *
     * 优先级：capabilityQuery → message → prompt → _stateMessage（来自 WorkflowState）。
     * 全部缺失时返回空字符串（Tag 匹配保留 score=0 的兜底行为）。
     */
    private function resolveCapabilityQuery(array $agentOptions): string
    {
        foreach (['capabilityQuery', 'message', 'prompt', '_stateMessage'] as $key) {
            $value = $agentOptions[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * 将 WorkflowState 中的 message/prompt 合并进 agentOptions。
     *
     * 供 create() 调用：boot 前注入 _stateMessage，使 Capability 能拿到用户 query。
     * 若 agentOptions 已有 _stateMessage 则不覆盖。
     */
    private function agentOptionsWithState(array $agentOptions, WorkflowState $state): array
    {
        if (!isset($agentOptions['_stateMessage'])) {
            $message = $state->get('message') ?? $state->get('prompt') ?? null;
            if (is_string($message) && $message !== '') {
                $agentOptions['_stateMessage'] = $message;
            }
        }

        return $agentOptions;
    }

    /**
     * 读取 agentOptions 中的布尔值。
     *
     * agentOptions 未设置该 key 时返回 $default（通常来自 NeuronAiConfig 全局配置）。
     */
    private function agentOptionsBool(array $agentOptions, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $agentOptions)) {
            return $default;
        }

        return filter_var($agentOptions[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 将配置值归一化为去重 string list。
     *
     * 支持单个字符串或字符串数组；过滤空串与非字符串项。
     *
     * @param mixed $raw 原始配置值
     *
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $value) {
            if (is_string($value) && $value !== '' && !in_array($value, $list, true)) {
                $list[] = $value;
            }
        }

        return $list;
    }

    /**
     * 将 mcpOnly / mcpExclude 归一化为 server => toolNames 映射。
     *
     * 格式：['github' => ['search_code', 'list_repos'], ...]
     *
     * @param mixed $raw 原始 agentOptions 值
     *
     * @return array<string, list<string>>
     */
    private function normalizeToolMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $map = [];
        foreach ($raw as $server => $tools) {
            if (!is_string($server) || $server === '') {
                continue;
            }
            $normalized = $this->normalizeStringList($tools);
            if ($normalized !== []) {
                $map[$server] = $normalized;
            }
        }

        return $map;
    }
}
