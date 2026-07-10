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

namespace Swoolefy\Support\CapabilityCenter;

/**
 * Capability 元数据描述（Registry 与 Resolver 之间的唯一契约）。
 *
 * Descriptor 只保存“可检索、可过滤、可懒加载”的轻量信息，不持有真实 Tool 对象：
 * - Registry 可以 Worker 级缓存这些元数据；
 * - 每次请求通过 Resolver 选出少量 Descriptor；
 * - Materializer 最后再把 Descriptor 转成 Neuron ToolInterface。
 *
 * 这样可以避免 100+ MCP Tool 在每轮 Agent 调用前全部实例化、全部送入 LLM schema。
 *
 * ID 规则示例：
 * - MCP：mcp:github:search_code，executorRef = mcp:github
 * - Native：native:rag:query_internal_kb，executorRef = class:... 或 factory id
 */
final class CapabilityDescriptor
{
    /**
     * @param string               $id            全局唯一 ID，Registry 主键
     * @param string               $name          Tool 名称（MCP tool name 或 Native tool name）
     * @param string               $description   用于 tag 匹配与 LLM 感知的描述
     * @param CapabilitySource     $source        来源类型，决定 materialize 策略
     * @param list<string>         $tags          用于 profile / query 的轻量匹配标签
     * @param string               $riskLevel     风险等级：low / medium / high / critical
     * @param string|null          $tenantId      非 null 时仅该租户可见
     * @param list<string>         $requiredRoles 访问该工具需要的角色；为空表示不限制
     * @param string               $executorRef   懒加载引用：mcp:{server} 或 factory id
     * @param string|null          $mcpServer     MCP 来源时的 server 名
     * @param bool                 $enabled       false 时 Policy 阶段直接过滤
     * @param array<string, mixed> $metadata      扩展信息，如 schemaSummary、factoryId
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly CapabilitySource $source,
        public readonly array $tags = [],
        public readonly string $riskLevel = 'low',
        public readonly ?string $tenantId = null,
        public readonly array $requiredRoles = [],
        public readonly string $executorRef = '',
        public readonly ?string $mcpServer = null,
        public readonly bool $enabled = true,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * 生成用于 tag / query 匹配的合并文本。
     *
     * 将 name、description、tags 拼成单一字符串，供 TagToolMatcher 分词命中，
     * 避免 Resolver 了解每个字段的匹配细节。
     */
    public function toIndexContent(): string
    {
        // 换行分隔 name 与 description，tags 空格拼接，便于 str_contains 命中
        return trim($this->name . "\n" . $this->description . "\n" . implode(' ', $this->tags));
    }
}
