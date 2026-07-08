<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

/**
 * CapabilityCenter 相关环境变量（从 .env 经 env() 读取）。
 */
final class NeuronAiCapabilityEnv
{
    /** 总开关；false 时 NeuronFactory 保持旧 McpFactory::tools() 全量挂载逻辑。 */
    public const ENABLED = 'CAPABILITY_ENABLED';

    /** 每轮动态筛选的普通候选数量；pinnedTools 不占该 quota。 */
    public const DEFAULT_TOP_K = 'CAPABILITY_DEFAULT_TOP_K';

    /** Resolver 阶段组合；Phase 3 默认 policy,tag，embedding / pgvector 为后续阶段。 */
    public const RESOLVER = 'CAPABILITY_RESOLVER';

    /** 元数据索引存储类型；Phase 3 固定 memory，后续可切 pgvector。 */
    public const INDEX_STORE = 'CAPABILITY_INDEX_STORE';

    /** Agent boot 时是否从 MCP server 同步轻量 tool descriptor 到 Registry。 */
    public const MCP_SYNC_ON_BOOT = 'CAPABILITY_MCP_SYNC_ON_BOOT';

    /** 注入给 LLM schema 的最大工具数兜底，防止异常配置导致 token 暴涨。 */
    public const MAX_SCHEMA_TOOLS = 'CAPABILITY_MAX_SCHEMA_TOOLS';

    /** 是否输出 capability.resolve / materialize 等调试日志。 */
    public const DEBUG = 'CAPABILITY_DEBUG';

    /** false=Capability 出错时 fail-open 回退旧 MCP 链路；true=直接抛错（严格生产策略）。 */
    public const FAIL_CLOSED = 'CAPABILITY_FAIL_CLOSED';
}
