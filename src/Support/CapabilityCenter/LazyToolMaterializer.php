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

use NeuronAI\Tools\ToolInterface;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\SupportLog;
use Throwable;

/**
 * 将命中的 descriptor 懒加载为真实 Neuron Tool。
 *
 * Materializer 有意放在 Resolver 之后，只对 Top-K + pinned 候选实例化：
 * - MCP descriptor 只会触发命中的 server/tool 加载；
 * - Native descriptor 通过显式工厂 / callable / class 实例化；
 * - 单个 Tool materialize 失败只记录 warning 并跳过，不拖垮整个 Agent。
 */
final class LazyToolMaterializer
{
    /**
     * @param McpFactory|null                                                     $mcpFactory      MCP 懒加载依赖
     * @param array<string, callable|ToolInterface|class-string<ToolInterface>> $nativeFactories key 可为 id / executorRef / name
     */
    public function __construct(
        private readonly ?McpFactory $mcpFactory = null,
        private readonly array $nativeFactories = [],
    ) {
    }

    /**
     * 将单个 descriptor materialize 为 Neuron ToolInterface。
     *
     * @param CapabilityDescriptor $descriptor 待实例化的元数据
     * @param bool                 $pinned     是否为 pinned 工具（失败时日志更醒目）
     *
     * @return ToolInterface|null 成功返回 Tool；失败返回 null 并记 warning
     */
    public function materialize(CapabilityDescriptor $descriptor, bool $pinned = false): ?ToolInterface
    {
        try {
            // 按来源类型分发到不同 materialize 策略
            $tool = match ($descriptor->source) {
                CapabilitySource::Mcp => $this->materializeMcp($descriptor),
                CapabilitySource::Native => $this->materializeNative($descriptor),
                default => null, // Api / Db / Workflow 等 Phase 5 来源暂未实现
            };

            if (!$tool instanceof ToolInterface) {
                SupportLog::warning('capability', 'capability.materialize returned null', [
                    'capabilityId' => $descriptor->id,
                    'source' => $descriptor->source->value,
                    'pinned' => $pinned,
                ]);
            }

            return $tool;
        } catch (Throwable $e) {
            // 单个失败不抛异常，保证其它候选 Tool 仍可注入
            SupportLog::error('capability', 'Failed to materialize capability tool', [
                'capabilityId' => $descriptor->id,
                'source' => $descriptor->source->value,
                'pinned' => $pinned,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * 懒加载 MCP Tool。
     *
     * 复用 McpFactory::tools() 以保持 stdio 守卫、URL 守卫、runner 限流单一实现。
     * 通过 only 过滤确保本次只加载命中的单个 Tool schema，避免全量 tools/list。
     */
    private function materializeMcp(CapabilityDescriptor $descriptor): ?ToolInterface
    {
        // 前置校验：必须有 McpFactory、server 名、tool 名
        if ($this->mcpFactory === null || $descriptor->mcpServer === null || $descriptor->name === '') {
            return null;
        }

        // only 过滤：[$server => [$toolName]]，只拉取命中的单个 MCP Tool
        $tools = $this->mcpFactory->tools(
            [$descriptor->mcpServer],
            [$descriptor->mcpServer => [$descriptor->name]],
            null,
            $descriptor->tenantId,
        );

        return $tools[0] ?? null;
    }

    /**
     * 实例化 Native Tool。
     *
     * 工厂查找优先级：descriptor.id → executorRef → name。
     * 支持三种 factory 形态：ToolInterface 实例、class-string、callable。
     */
    private function materializeNative(CapabilityDescriptor $descriptor): ?ToolInterface
    {
        // 按 id → executorRef → name 顺序查找工厂
        $factory = $this->nativeFactories[$descriptor->id]
            ?? $this->nativeFactories[$descriptor->executorRef]
            ?? $this->nativeFactories[$descriptor->name]
            ?? null;

        // 已是 ToolInterface 实例，直接返回
        if ($factory instanceof ToolInterface) {
            return $factory;
        }

        // class-string：无参构造，须实现 ToolInterface
        if (is_string($factory) && class_exists($factory)) {
            $tool = new $factory();

            return $tool instanceof ToolInterface ? $tool : null;
        }

        // callable：传入 descriptor，返回 ToolInterface
        if (is_callable($factory)) {
            $tool = $factory($descriptor);

            return $tool instanceof ToolInterface ? $tool : null;
        }

        return null;
    }
}
