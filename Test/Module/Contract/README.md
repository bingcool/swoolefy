# Contract 模块 — 法务 HITL 工作流

本模块通过 `ContractWorkflowService` 独立装配 Registry / Engine，
与 Order/Outdoor **同一模式**。无专用 Demo 控制器时，经统一 API 启动：

`POST /api/v1/workflow/run`，`workflowId=contract_review`。

本地回归：`composer test:contract-workflow`。
