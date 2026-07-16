# Order 模块 — 工作流演示

本模块演示订单处理（AI 风控三分支）与 Saga 补偿两类工作流。演示入口为 `Controller/OrderWorkflowDemoController.php`。

本模块通过 `OrderWorkflowService` 独立装配 Registry / NeuronFactory / Engine，
**不依赖** `Test\Module\Workflow\WorkflowService`。统一目录 API 仍可列出 `order_*`。

默认 API 前缀：`/api`（以实际 `Test` 应用路由配置为准）。下文假设服务监听 `http://127.0.0.1:9501`。

本地回归：`composer test:order-workflow`

## 目录结构

```
Order/
├── OrderWorkflowService.php          # 本模块独立 Registry / Engine / Neuron
├── Agent/OrderDecisionAgent.php      # AI 风控决策 Agent
├── Controller/
│   ├── OrderWorkflowDemoController.php  # 工作流演示（本文档）
│   ├── UserOrderController.php
│   └── LogOrderController.php
├── Dto/OrderDecisionDto.php
├── Node/                             # 流程节点
│   ├── ValidateNode.php
│   ├── PaymentNode.php
│   ├── ManualReviewNode.php
│   ├── RejectNode.php
│   ├── CompleteNode.php
│   ├── ReserveInventoryNode.php
│   └── FailAfterPaymentNode.php
├── Tests/OrderWorkflowModuleTest.php
└── Workflow/
    ├── OrderProcessingWorkflow.php   # order_processing
    └── OrderSagaWorkflow.php         # order_saga
```

## 节点约定

各节点统一维护：

| 字段 | 说明 |
|------|------|
| `order` | 订单快照（orderId、userId、amount、currency、items、status） |
| `orderStatus` | 生命周期：`validated` → `paid` / `rejected` / `completed` 等 |

| 节点 | 职责 |
|------|------|
| `ValidateNode` | 校验 `orderId`，规范化入参，写 `order` / `orderStatus=validated` |
| `PaymentNode` | 模拟支付，写 `payment` / `paymentStatus`，支持 Saga 补偿（退款） |
| `ManualReviewNode` | 低置信度人工复核；默认自动通过，`pauseForHuman=true` 时 HITL 暂停 |
| `RejectNode` | 拒绝分支，写 `rejectReason` |
| `CompleteNode` | 支付成功后收尾，`orderStatus=completed` |
| `ReserveInventoryNode` | 预留库存（Saga），失败时释放 |
| `FailAfterPaymentNode` | 支付后故意失败，触发 Saga 补偿演示 |

---

## 流程一：订单处理（order_processing）

**workflowId:** `order_processing` · **version:** `1.1.0`

```
validate → ai_decision ─┬─ approved && confidence ≥ 0.8 → payment → complete
                        ├─ approved && confidence < 0.8  → manual_review → payment → complete
                        └─ rejected                      → reject
```

- 默认使用 `OrderDecisionAgent`（无 Provider 时 Fake 回退）。
- 请求体可传 `mockDecision`，注入固定决策，便于演示三条分支。

### API

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/v1/order/workflow/process` | 启动订单处理 |
| GET  | `/api/v1/order/workflow/status?runId=` | 查询 Run |
| POST | `/api/v1/order/workflow/resume` | HITL 恢复（见下文） |

### 请求字段

| 字段 | 必填 | 说明 |
|------|------|------|
| `orderId` | 是 | 订单号 |
| `userId` | 否 | 默认 `demo-user` |
| `sessionId` | 否 | 默认 `sess-{orderId}` |
| `amount` | 否 | 默认 `99` |
| `currency` | 否 | 默认 `CNY` |
| `items` | 否 | 默认 `[{"sku":"DEMO-SKU","qty":1}]` |
| `mockDecision` | 否 | `{approved, confidence, reason}`，注入 mock AI |
| `pauseForHumanReview` | 否 | 默认 `false`；为 `true` 时低置信度在 `manual_review` 暂停（`WAITING`），需 resume |

### curl：高置信度直付

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/process' \
  -H 'Content-Type: application/json' \
  -d '{
    "orderId": "ORD-10001",
    "userId": "u1",
    "amount": 199,
    "currency": "CNY",
    "items": [{"sku": "SKU-1", "qty": 1}],
    "mockDecision": {
      "approved": true,
      "confidence": 0.95,
      "reason": "high confidence auto pay"
    }
  }' | jq .
```

预期：`status=completed`，`orderStatus=completed`，`paymentStatus` 有值，路径经 `payment` → `complete`。

### curl：低置信度人工复核（自动通过）

演示控制器默认 `pauseForHumanReview=false`，`manual_review` 节点会自动通过并继续支付。

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/process' \
  -H 'Content-Type: application/json' \
  -d '{
    "orderId": "ORD-10002",
    "userId": "u1",
    "amount": 50,
    "mockDecision": {
      "approved": true,
      "confidence": 0.6,
      "reason": "need manual review"
    }
  }' | jq .
```

预期：`manualReview` 有记录，最终仍 `completed`。

### curl：拒绝

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/process' \
  -H 'Content-Type: application/json' \
  -d '{
    "orderId": "ORD-10003",
    "amount": 10,
    "mockDecision": {
      "approved": false,
      "confidence": 0.9,
      "reason": "risk reject"
    }
  }' | jq .
```

预期：`orderStatus=rejected`，`rejectReason` 有值，无支付。

### curl：真实 Agent（不传 mock）

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/process' \
  -H 'Content-Type: application/json' \
  -d '{
    "orderId": "ORD-10004",
    "userId": "u1",
    "amount": 128.5
  }' | jq .
```

走 `OrderDecisionAgent`；未配置 Provider 时 Fake 回退（通常高置信度进支付分支）。

### curl：查询状态

将上一步响应中的 `runId` 代入：

```bash
curl -s 'http://127.0.0.1:9501/api/v1/order/workflow/status?runId=<runId>' | jq .
```

### HITL 恢复（pauseForHumanReview）

请求体传 `"pauseForHumanReview": true`，配合低置信度 `mockDecision`，流程会在 `manual_review` 进入 `WAITING`：

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/process' \
  -H 'Content-Type: application/json' \
  -d '{
    "orderId": "ORD-10005",
    "amount": 50,
    "pauseForHumanReview": true,
    "mockDecision": {
      "approved": true,
      "confidence": 0.6,
      "reason": "need manual review"
    }
  }' | jq .
```

预期：`status=waiting`，`pauseNodeId=manual_review`，`runId` 形如 `run_YYYYMMDD_xxx`。再 resume：

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/resume' \
  -H 'Content-Type: application/json' \
  -d '{
    "runId": "<runId>",
    "feedback": {
      "approved": true,
      "reason": "manual ok"
    }
  }' | jq .
```

---

## 流程二：Saga 补偿（order_saga）

**workflowId:** `order_saga` · **version:** `1.1.0`

```
validate → reserve → payment → notify_fail(FAILED)
compensate 逆序：payment 退款 → reserve 释库存
```

支付成功后 `notify_fail` 故意失败，引擎按 Saga 逆序补偿。

### API

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/v1/order/workflow/saga` | 启动 Saga 演示 |

### curl

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/saga' \
  -H 'Content-Type: application/json' \
  -d '{
    "orderId": "ORD-SAGA-1",
    "userId": "u1",
    "amount": 50,
    "items": [{"sku": "SKU-SAGA", "qty": 2}]
  }' | jq .
```

预期：`status` 为失败/补偿完成态，`compensatedNodes` 含支付与库存相关节点，`inventoryReserved` 最终为释放态。

查询同一 `runId`：

```bash
curl -s 'http://127.0.0.1:9501/api/v1/order/workflow/status?runId=<runId>' | jq .
```

---

## 响应字段说明

演示接口统一返回结构（节选）：

| 字段 | 说明 |
|------|------|
| `runId` | 运行实例 ID（`run_YYYYMMDD_xxx`） |
| `workflowId` | `order_processing` / `order_saga` |
| `version` | 定义版本 |
| `status` | `completed` / `failed` / `waiting` 等 |
| `waiting` | 是否 HITL 等待中 |
| `createdAt` / `updatedAt` | Run 创建/更新时间（DATETIME，与 DB `workflow_runs` 一致） |
| `pauseNodeId` | WAITING 时暂停节点 ID |
| `orderStatus` | 业务订单状态 |
| `order` | 订单快照 |
| `decision` | AI 决策 DTO（approved / confidence / reason） |
| `payment` / `paymentStatus` | 支付结果 |
| `inventoryReserved` | 库存预留状态（Saga） |
| `manualReview` | 人工复核记录 |
| `rejectReason` | 拒绝原因 |
| `compensatedNodes` | 已补偿节点列表（Saga） |
| `error` | 失败信息 |
| `data` | 完整 state 数据 |

---

## 代码用法（非 HTTP）

```php
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;
use Test\Module\Order\Workflow\OrderSagaWorkflow;

// 主流程（可注入 mock executor）
$definition = OrderProcessingWorkflow::definition(
    static function ($ctx, $state) {
        $dto = new \Test\Module\Order\Dto\OrderDecisionDto();
        $dto->approved = true;
        $dto->confidence = 0.95;
        $dto->reason = 'unit test';
        return $dto;
    },
);

$compiled = WorkflowBootstrap::compiler()->compile($definition);
$engine = WorkflowBootstrap::engine();
$runId = $engine->start($compiled, [
    'orderId' => 'ORD-CODE-1',
    'userId' => 'u1',
    'amount' => 99.0,
]);
$run = $engine->getRun($runId);

// Saga
$saga = OrderSagaWorkflow::definition();
$runId = $engine->start(
    WorkflowBootstrap::compiler()->compile($saga),
    ['orderId' => 'ORD-SAGA-CODE', 'amount' => 50.0],
);
```

---

## 相关文档

- 工作流总览：`docs/SwoolefyAI.md`（order_processing 示例）
- 通用工作流控制器：`Test/Module/Workflow/Controller/WorkflowController.php`
- 单测：`src/Support/Workflow/Tests/WorkflowPhase1Test.php`、`WorkflowPhase4Test.php`
)
