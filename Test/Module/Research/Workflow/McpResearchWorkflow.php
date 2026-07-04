<?php

declare(strict_types=1);

namespace Test\Module\Research\Workflow;

use Swoolefy\Support\AI\Builder\AINodeBuilder;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Test\Module\Research\Agent\CodingResearchAgent;
use Test\Module\Research\Dto\ResearchSummaryDto;

/**
 * MCP 研究工作流（workflowId: mcp_research，version: 1.0.0）。
 *
 * 演示 Phase 3：研究节点声明 MCP 工具（github / brave_search），摘要后按紧急程度分支。
 * 默认内置 executor stub，不依赖真实 MCP 进程，便于本地演示；也可关闭 stub 走真实 Agent。
 *
 * DAG（条件边按声明顺序求值，首个为 true 的分支获胜）：
 *
 *   research（AINode + MCP 声明）
 *      │  默认 stub：写入 content / mcpToolsUsed
 *      ▼
 *   summarize（结构化 ResearchSummaryDto → state.summary）
 *      │
 *      ├── summary.urgent == true  ──► notify（紧急通知）
 *      └── summary.urgent == false ──► archive（归档）
 *      （无分支命中时的 default）──► archive
 *
 * 默认紧急判定：query 字符串（不区分大小写）包含 "urgent" 则 urgent=true。
 * 可通过 $mockSummary 覆盖 summary 字段，强制走 notify / archive。
 *
 * 各节点写入的 state：
 *   - research:   content、mcpToolsUsed（stub）或 Agent 原始输出
 *   - summarize:  summary{summary, urgent, source}（ResearchSummaryDto）
 *   - notify:     notified=true
 *   - archive:    archived=true
 *
 * 用法示例：
 *   // 默认 stub（query 含 urgent → notify，否则 archive）
 *   $def = McpResearchWorkflow::definition($neuronFactory);
 *
 *   // 强制紧急分支
 *   $def = McpResearchWorkflow::definition($neuronFactory, mockSummary: [
 *       'urgent' => true,
 *       'summary' => 'forced urgent',
 *       'source' => 'demo',
 *   ]);
 *
 * @see Test\Module\Research\README.md
 * @see Swoolefy\Support\AI\Builder\AINodeBuilder::mcp()
 */
final class McpResearchWorkflow
{
    /**
     * 构建纯工作流定义（仅描述 DAG，不启动引擎）。
     *
     * @param NeuronFactory         $neuronFactory      注入 MCP / Provider 的 Neuron 工厂
     * @param array<string, mixed>|null $mockSummary    可选，覆盖 summarize 输出：
     *                                                  urgent (bool)、summary (string)、source (string)
     * @param bool                  $useRealResearchAgent 为 true 时 research 节点不注入 stub，
     *                                                    走 CodingResearchAgent（需 Provider / MCP 环境）
     */
    public static function definition(
        NeuronFactory $neuronFactory,
        ?array $mockSummary = null,
        bool $useRealResearchAgent = false,
    ): WorkflowDefinition {
        // --- research：声明 MCP 工具，默认 stub 执行 --------------------------------
        // mcp(['github','brave_search'])：声明本节点可用的 MCP server id（配置见 McpFactory）。
        // promptKey('query')：从 state.query 读取研究主题。
        $researchBuilder = AINodeBuilder::make('research')
            ->agent(CodingResearchAgent::class)
            ->promptKey('query')
            ->mcp(['github', 'brave_search']);

        // 默认 stub：不启动真实 MCP 进程，仅模拟「研究完成」结果，保证演示可离线运行。
        if (!$useRealResearchAgent) {
            $researchBuilder->executor(static function ($ctx, $state): array {
                unset($ctx);

                return [
                    'content' => 'Research completed for: ' . (string) $state->get('query', ''),
                    'mcpToolsUsed' => [],
                ];
            });
        }

        // --- summarize：结构化摘要，决定 notify / archive 分支 ----------------------
        $summarizeBuilder = AINodeBuilder::make('summarize')
            ->structured(ResearchSummaryDto::class, outputKey: 'summary')
            ->executor(static function ($ctx, $state) use ($mockSummary): ResearchSummaryDto {
                unset($ctx);
                $dto = new ResearchSummaryDto();

                // HTTP 演示可注入 mockSummary，强制 urgent 分支，无需改 query 文案。
                if (is_array($mockSummary) && $mockSummary !== []) {
                    $dto->summary = (string) ($mockSummary['summary'] ?? 'mock summary');
                    $dto->urgent = (bool) ($mockSummary['urgent'] ?? false);
                    $dto->source = (string) ($mockSummary['source'] ?? 'mcp_research_mock');

                    return $dto;
                }

                // 默认策略：query 含 "urgent"（忽略大小写）视为紧急。
                $query = (string) $state->get('query', '');
                $dto->summary = 'Analysis of ' . $query;
                $dto->urgent = str_contains(strtolower($query), 'urgent');
                $dto->source = 'mcp_research';

                return $dto;
            });

        return WorkflowDefinition::create('mcp_research', '1.0.0')
            ->metadata([
                'owner' => 'research-team',
                'description' => 'MCP research then urgent notify / archive routing',
            ])
            // 将 state["summary"] 绑定到 ResearchSummaryDto。
            ->registerSchema('summary', ResearchSummaryDto::class)
            ->addNode('research', $researchBuilder->build(neuronFactory: $neuronFactory))
            ->addNode('summarize', $summarizeBuilder->build())
            // 紧急：通知；非紧急：归档。
            ->addNode('notify', new ClosureNode('notify', static fn () => NodeExecutionResult::success([
                'notified' => true,
            ])))
            ->addNode('archive', new ClosureNode('archive', static fn () => NodeExecutionResult::success([
                'archived' => true,
            ])))
            ->addEdge('research', 'summarize')
            // 表达式基于 state.data：data['summary']['urgent'] 对应 outputKey=summary。
            ->addConditionalEdges('summarize', [
                'notify' => EdgeCondition::when("data['summary']['urgent'] == true"),
                'archive' => EdgeCondition::when("data['summary']['urgent'] == false"),
            ], default: 'archive');
    }
}
