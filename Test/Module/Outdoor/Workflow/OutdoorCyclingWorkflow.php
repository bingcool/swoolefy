<?php

declare(strict_types=1);

namespace Test\Module\Outdoor\Workflow;

use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\StaticRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Test\Module\Outdoor\Agent\BikePrepAgent;
use Test\Module\Outdoor\Agent\RouteMapAgent;
use Test\Module\Outdoor\Agent\WeatherOutdoorAgent;

/**
 * 户外骑行准备工作流（workflowId: outdoor_cycling，version: 1.0.0）。
 *
 * 演示：多个专业 Agent **并行**完成出发前准备，再按「天气是否合适」做条件分支。
 * 业务故事：AgentA 看天气、AgentB 规划路线、AgentC 准备自行车；
 * 若天气好，则骑自行车出发户外游玩，否则暂缓。
 *
 * ---------------------------------------------------------------------------
 * DAG（并行扇出 + 条件边）
 * ---------------------------------------------------------------------------
 *
 *   parallel_prepare（AgentParallelNode）
 *        │  StaticRouter 固定选中 weather + route + bike
 *        │  三个 Agent 在协程中并行执行
 *        │  各自返回值写入 state.agentOutputs[agentId]
 *        ▼
 *     decide（ClosureNode）
 *        │  读取 agentOutputs，汇总为 data.plan
 *        │  把 weather.weatherGood 提升为 data.weatherGood（供条件边读取）
 *        ├── weatherGood === true ──► go_cycling（出发骑行）
 *        └── default（天气不佳）────► stay_home（暂缓）
 *        ▼
 *     （终态 completed）
 *
 * ---------------------------------------------------------------------------
 * 各阶段写入的 state
 * ---------------------------------------------------------------------------
 *
 * 入参（engine->start 的 input，进入 WorkflowState.data）：
 *   - destination   string  目的地，如「深圳湾公园」
 *   - weatherHint   string  mock 模式下控制天气：sunny→出发；rainy/rain/storm/bad→留家
 *   - userId / sessionId    可选，演示会话标识
 *
 * parallel_prepare 之后（agentOutputs 是 WorkflowState 独立属性，不在 data 里）：
 *   - agentOutputs['weather'] = { topic, weatherGood: bool, content }
 *   - agentOutputs['route']   = { topic, content }
 *   - agentOutputs['bike']    = { topic, content }
 *
 * decide 之后（NodeExecutionResult::success 合并进 data）：
 *   - data.weatherGood  bool
 *   - data.plan         { destination, weather, route, bike, weatherGood }
 *
 * go_cycling / stay_home 之后：
 *   - data.decision  'go_cycling' | 'stay_home'
 *   - data.message   给人看的结论文案
 *   - data.trip      行程详情（mode / route / 取消原因等）
 *
 * ---------------------------------------------------------------------------
 * 用法示例
 * ---------------------------------------------------------------------------
 *
 *   // HTTP 演示（默认）：跳过 LLM，按 weatherHint 返回确定性结果
 *   $def = OutdoorCyclingWorkflow::definition($scheduler, useMockAgents: true);
 *
 *   // 真实 / Fake Provider Agent（无可用 API Key 时各 Agent 内置 Fake 回退）
 *   $def = OutdoorCyclingWorkflow::definition($scheduler, useMockAgents: false);
 *
 *   $compiled = WorkflowComponentFactory::compiler()->compile($def);
 *   $runId = $engine->start($compiled, [
 *       'destination' => '深圳湾公园',
 *       'weatherHint' => 'sunny', // 或 rainy
 *   ]);
 *
 * @see \Test\Module\Outdoor\README.md
 * @see \Test\Module\Outdoor\Controller\OutdoorWorkflowDemoController
 * @see \Swoolefy\Support\AI\Node\AgentParallelNode
 * @see \Swoolefy\Support\Agent\Router\StaticRouter
 */
final class OutdoorCyclingWorkflow
{
    /**
     * 构建纯工作流定义（只描述 DAG，不启动引擎）。
     *
     * 运行时入口：compile() 之后调用 WorkflowEngine::start($compiled, $input)。
     *
     * @param AgentScheduler $scheduler     多 Agent 调度器（内部持有 NeuronFactory，负责协程并行）
     * @param bool           $useMockAgents true：不调 LLM，按 weatherHint 返回确定性 mock（演示 / CI）
     *                                      false：经 NeuronFactory 创建真实 Agent（可 Fake 回退）
     */
    public static function definition(
        AgentScheduler $scheduler,
        bool $useMockAgents = false,
    ): WorkflowDefinition {
        return WorkflowDefinition::create('outdoor_cycling', '1.0.0')
            // 可选元数据：注册中心 / 运维看板展示用，引擎调度不依赖。
            ->metadata([
                'owner' => 'outdoor-team',
                'description' => 'Parallel weather + route + bike prep, then cycle if weather is good',
            ])

            // -----------------------------------------------------------------
            // 1) 并行准备节点（AgentParallelNode）
            // -----------------------------------------------------------------
            // addAgentParallel 会创建 AgentParallelNode：
            //   - router：决定本轮跑哪些 agentId（此处 StaticRouter 固定三者全跑）
            //   - agents：agentId => callable(RouterContext, NeuronFactory): mixed
            //   - scheduler：在 Swoole 协程中并行调用上述 callable
            // 每个 callable 的返回值写入 state.agentOutputs[agentId]。
            // 注意：三个任务互不依赖，适合并行；决策必须等三者都完成后再做。
            ->addAgentParallel('parallel_prepare', [
                'scheduler' => $scheduler,
                // 固定选中三个 Agent；若换成 RuleRouter / LLMRouter，可按 state 动态裁剪。
                'router' => new StaticRouter(['weather', 'route', 'bike']),
                'agents' => [
                    // AgentA：天气研判（唯一影响后续条件边的关键输出：weatherGood）
                    'weather' => self::weatherHandler($useMockAgents),
                    // AgentB：地图路线（并行产出，供出发文案展示；不参与分支条件）
                    'route' => self::routeHandler($useMockAgents),
                    // AgentC：备车清单（同上，并行产出）
                    'bike' => self::bikeHandler($useMockAgents),
                ],
            ])

            // -----------------------------------------------------------------
            // 2) 决策节点：汇总并行结果，写出条件边可读的 weatherGood
            // -----------------------------------------------------------------
            // 条件边只能方便地读 WorkflowState.data（get/dto），而 agentOutputs 是独立属性。
            // 因此在这里把 weatherGood「提升」到 data，并拼一份 plan 给下游节点复用。
            ->addNode('decide', new ClosureNode('decide', static function ($ctx, WorkflowState $state): NodeExecutionResult {
                // RunContext 本演示不需要（无 runId / 插件侧车数据依赖）。
                unset($ctx);

                // agentOutputs 由 AgentParallelNode 写入；键名与 StaticRouter / agents 一致。
                $outputs = $state->agentOutputs;
                $weather = is_array($outputs['weather'] ?? null) ? $outputs['weather'] : [];
                $route = is_array($outputs['route'] ?? null) ? $outputs['route'] : [];
                $bike = is_array($outputs['bike'] ?? null) ? $outputs['bike'] : [];

                // 缺省 false：天气 Agent 异常或未返回 weatherGood 时，走 stay_home（偏安全）。
                $weatherGood = (bool) ($weather['weatherGood'] ?? false);
                $destination = (string) $state->get('destination', '附近公园');

                // success([...]) 的数组会 merge 进 state.data。
                return NodeExecutionResult::success([
                    // 条件边直接读这个字段（见下方 addConditionalEdges）。
                    'weatherGood' => $weatherGood,
                    // 下游 go_cycling / stay_home 展示用的汇总快照。
                    'plan' => [
                        'destination' => $destination,
                        'weather' => $weather['content'] ?? '',
                        'route' => $route['content'] ?? '',
                        'bike' => $bike['content'] ?? '',
                        'weatherGood' => $weatherGood,
                    ],
                ]);
            }))

            // -----------------------------------------------------------------
            // 3a) 好天气分支：确认出发骑行
            // -----------------------------------------------------------------
            ->addNode('go_cycling', new ClosureNode('go_cycling', static function ($ctx, WorkflowState $state): NodeExecutionResult {
                unset($ctx);
                $plan = $state->get('plan');
                $plan = is_array($plan) ? $plan : [];

                return NodeExecutionResult::success([
                    'decision' => 'go_cycling',
                    'message' => sprintf(
                        '天气不错，骑自行车出发去「%s」户外游玩！',
                        (string) ($plan['destination'] ?? $state->get('destination', '户外')),
                    ),
                    // trip：结构化行程，便于 API 响应 / 前端展示。
                    'trip' => [
                        'mode' => 'bicycle',
                        'destination' => $plan['destination'] ?? $state->get('destination'),
                        'route' => $plan['route'] ?? '',
                        'bikeReady' => $plan['bike'] ?? '',
                        'weather' => $plan['weather'] ?? '',
                    ],
                ]);
            }))

            // -----------------------------------------------------------------
            // 3b) 坏天气 / 默认分支：暂缓出发（路线与备车结果仍保留在 trip 里备查）
            // -----------------------------------------------------------------
            ->addNode('stay_home', new ClosureNode('stay_home', static function ($ctx, WorkflowState $state): NodeExecutionResult {
                unset($ctx);
                $plan = $state->get('plan');
                $plan = is_array($plan) ? $plan : [];

                return NodeExecutionResult::success([
                    'decision' => 'stay_home',
                    'message' => '天气不佳，暂缓骑行，改日再出发户外游玩。',
                    'trip' => [
                        'mode' => 'cancelled',
                        'reason' => $plan['weather'] ?? 'weather not good',
                        // 即使取消，并行阶段已算好的路线 / 备车仍可返回，避免白算。
                        'preparedRoute' => $plan['route'] ?? '',
                        'preparedBike' => $plan['bike'] ?? '',
                    ],
                ]);
            }))

            // -----------------------------------------------------------------
            // 边：并行完成后必进 decide；decide 后按天气条件路由
            // -----------------------------------------------------------------
            // 固定边：parallel_prepare 成功 → decide（无分支）。
            ->addEdge('parallel_prepare', 'decide')
            // 条件边：按声明顺序求值，首个为 true 的 target 获胜；
            // 全部为 false 时走 default（stay_home）。
            // 同一 from 不能既有无条件 addEdge 又有 addConditionalEdges（编译期报错）。
            ->addConditionalEdges('decide', [
                'go_cycling' => EdgeCondition::fromCallable(
                    // 读取 decide 写入的 data.weatherGood。
                    static fn (WorkflowState $s): bool => (bool) $s->get('weatherGood', false),
                ),
            ], default: 'stay_home');
    }

    /**
     * AgentA — 天气研判回调。
     *
     * 返回结构约定（写入 agentOutputs['weather']）：
     *   - topic: 'weather'
     *   - weatherGood: bool   ← decide / 条件边依赖此字段
     *   - content: string     ← 给人看的说明
     *
     * mock：根据 state.weatherHint 判定；
     *   负面 hint（rain / rainy / storm / bad / 阴雨 / 雨）→ false，其余 → true。
     * 真实：调用 WeatherOutdoorAgent，再 parseWeatherGood() 解析模型文案。
     *
     * @return callable(RouterContext, NeuronFactory): array<string, mixed>
     */
    private static function weatherHandler(bool $useMockAgents): callable
    {
        if ($useMockAgents) {
            return static function (RouterContext $ctx, NeuronFactory $factory): array {
                // mock 路径不需要 NeuronFactory。
                unset($factory);
                $hint = strtolower(trim((string) $ctx->state->get('weatherHint', 'sunny')));
                $destination = (string) $ctx->state->get('destination', '附近公园');
                // 显式负面 hint 才判坏天气；缺省 sunny 视为可骑行。
                $good = !in_array($hint, ['rain', 'rainy', 'storm', 'bad', '阴雨', '雨'], true);

                return [
                    'topic' => 'weather',
                    'weatherGood' => $good,
                    'content' => $good
                        ? "（mock）目的地「{$destination}」天气晴好，适合骑行。"
                        : "（mock）目的地「{$destination}」天气不佳（{$hint}），不建议骑行。",
                ];
            };
        }

        return static function (RouterContext $ctx, NeuronFactory $factory): array {
            $destination = (string) $ctx->state->get('destination', '附近公园');
            $hint = (string) $ctx->state->get('weatherHint', '');
            // 要求模型在回复中带 weatherGood=true|false，便于稳定解析。
            $prompt = sprintf(
                '请判断去「%s」户外骑自行车的天气是否合适。%s请用中文简短回答，并包含 weatherGood=true 或 weatherGood=false。',
                $destination,
                $hint !== '' ? "参考提示：{$hint}。" : '',
            );

            // capabilityEnabled=false：本演示不走 CapabilityCenter，避免无关 Tool 注入。
            $agent = $factory->create(WeatherOutdoorAgent::class, $ctx->state, [
                'capabilityEnabled' => false,
            ]);
            $content = (string) $agent->chat(new UserMessage($prompt))->getMessage()->getContent();

            return [
                'topic' => 'weather',
                'weatherGood' => self::parseWeatherGood($content),
                'content' => $content,
            ];
        };
    }

    /**
     * AgentB — 地图路线回调。
     *
     * 返回：{ topic: 'route', content: string }
     * 不参与条件边；结果经 decide.plan.route 进入最终 trip。
     *
     * @return callable(RouterContext, NeuronFactory): array<string, mixed>
     */
    private static function routeHandler(bool $useMockAgents): callable
    {
        if ($useMockAgents) {
            return static function (RouterContext $ctx, NeuronFactory $factory): array {
                unset($factory);
                $destination = (string) $ctx->state->get('destination', '附近公园');

                return [
                    'topic' => 'route',
                    'content' => "（mock）前往「{$destination}」：滨海绿道约 12km，预计 45 分钟。",
                ];
            };
        }

        return static function (RouterContext $ctx, NeuronFactory $factory): array {
            $destination = (string) $ctx->state->get('destination', '附近公园');
            $prompt = "请为骑自行车去「{$destination}」规划一条简短路线（中文）。";

            $agent = $factory->create(RouteMapAgent::class, $ctx->state, [
                'capabilityEnabled' => false,
            ]);
            $content = (string) $agent->chat(new UserMessage($prompt))->getMessage()->getContent();

            return ['topic' => 'route', 'content' => $content];
        };
    }

    /**
     * AgentC — 准备自行车回调。
     *
     * 返回：{ topic: 'bike', content: string }
     * 不参与条件边；结果经 decide.plan.bike 进入最终 trip。
     *
     * @return callable(RouterContext, NeuronFactory): array<string, mixed>
     */
    private static function bikeHandler(bool $useMockAgents): callable
    {
        if ($useMockAgents) {
            return static function (RouterContext $ctx, NeuronFactory $factory): array {
                // mock 备车清单与目的地无关，ctx 仅满足回调签名。
                unset($factory, $ctx);

                return [
                    'topic' => 'bike',
                    'content' => '（mock）胎压、刹车、头盔、水壶已检查完毕，自行车可出发。',
                ];
            };
        }

        return static function (RouterContext $ctx, NeuronFactory $factory): array {
            $agent = $factory->create(BikePrepAgent::class, $ctx->state, [
                'capabilityEnabled' => false,
            ]);
            $content = (string) $agent->chat(new UserMessage('请给出出发前自行车准备清单（中文，简短）。'))
                ->getMessage()
                ->getContent();

            return ['topic' => 'bike', 'content' => $content];
        };
    }

    /**
     * 从天气 Agent 自然语言回复中解析 weatherGood。
     *
     * 优先级：
     *   1. 显式标记 weatherGood=true / false（提示词已要求模型输出）
     *   2. 文案含常见负面词（雨 / 暴雨 / 台风 / 不适合 …）→ false
     *   3. 其余默认 true（偏「可出门」，与 mock 缺省 sunny 一致）
     */
    private static function parseWeatherGood(string $content): bool
    {
        if (preg_match('/weatherGood\s*=\s*true/i', $content) === 1) {
            return true;
        }
        if (preg_match('/weatherGood\s*=\s*false/i', $content) === 1) {
            return false;
        }

        // 无显式标记时，按常见负面词兜底。
        foreach (['雨', '暴雨', '台风', '不适合', '不宜', '恶劣'] as $bad) {
            if (str_contains($content, $bad)) {
                return false;
            }
        }

        return true;
    }
}
