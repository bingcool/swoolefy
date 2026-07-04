# Agent 多 Agent 路由与调度

在工作流中**并行调度多个 Neuron Agent**，路由策略与执行解耦：`AgentRouterInterface` 决定跑哪些 Agent，`AgentScheduler` 负责协程并发与结果汇聚。

- 架构设计：[swoolefyAI.md](../../../docs/swoolefyAI.md) §4.6
- 快速接入：[docs/AI-WORKFLOW.md](../../../docs/AI-WORKFLOW.md)
- 关联：`Support/AI`（`AgentParallelNode`）、`Support/Neuron`、`Support/Workflow`

---

## 目录结构

```
Agent/
├── AgentRouterInterface.php   # route(RouterContext): list<agentId>
├── AgentScheduler.php         # GoWaitGroup 并行执行 + 写入 state.agentOutputs
├── RouterContext.php          # runId / WorkflowState / availableAgents / timeout
├── Router/
│   ├── StaticRouter.php       # 固定列表
│   ├── RuleRouter.php         # Symfony EL / callable 规则
│   ├── WeightedRouter.php     # 加权随机
│   ├── CostAwareRouter.php    # 预算内最低成本
│   ├── RoundRobinRouter.php   # 轮询负载均衡
│   └── LLMRouter.php          # LLM 决策路由（需 Provider）
└── Tests/
```

---

## 核心原理

```
RouterContext ──route()──► [agentId, ...] ──AgentScheduler──► agentOutputs
                              │                    │
                         策略可插拔            协程外串行 / 协程内并行
```

| 组件 | 职责 |
|------|------|
| **Router** | 只读 `WorkflowState`，返回待执行 agentId 列表 |
| **Scheduler** | 执行 `tasks[agentId]`，异常包装为 `['error' => ...]`，写入 `state.setAgentOutput` |

CLI / 单测无协程时自动串行执行，不依赖 Swoole Worker。

---

## 快速上手

```php
use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\StaticRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Neuron\Memory\MemoryFactory;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\State\WorkflowState;

$scheduler = new AgentScheduler(new NeuronFactory(new MemoryFactory()));
$state = new WorkflowState(data: ['query' => 'hello']);
$ctx = new RouterContext(runId: 'run-1', state: $state, availableAgents: ['a', 'b']);

$results = $scheduler->runParallel($ctx, [
    'a' => fn ($ctx, $factory) => 'answer-a',
    'b' => fn ($ctx, $factory) => 'answer-b',
], new StaticRouter(['a', 'b']));

// $results['a'] === 'answer-a'
// $state->agentOutput('a') === 'answer-a'
```

### 路由策略对照

| 路由 | 适用场景 | 返回 |
|------|----------|------|
| `StaticRouter` | 固定并行集合 | 声明的 agentId |
| `RuleRouter` | 按 state 字段分支 | 命中规则的 agentId |
| `WeightedRouter` | 灰度 / A-B | 按权重随机子集 |
| `CostAwareRouter` | 成本控制 | 预算内最便宜的一个 |
| `RoundRobinRouter` | 负载均衡 | 轮询单个 agentId |
| `LLMRouter` | 复杂意图分流 | LLM 选出的 agentId |

**CostAwareRouter** 读取 `state.estimatedTokens`（优先）或按 `query` 长度估算；单价为每 1k token 美元。

```php
new CostAwareRouter([
    'cheap' => 0.001,
    'premium' => 0.03,
], budgetUsd: 0.01);
```

工作流内更常见的是通过 `AgentParallelNode`（`Support/AI`）挂载 Router，无需手写 Scheduler。

---

## 运行测试

```bash
composer test:agent
# 或
php src/Support/Agent/Tests/AgentModuleTest.php
```
