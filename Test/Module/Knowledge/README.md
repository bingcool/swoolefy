# Knowledge 模块 — 知识库问答工作流

本模块通过 `KnowledgeWorkflowService` 独立装配 Registry / RagFactory / Engine，
与 Order/Outdoor **同一模式**。经统一 API 启动：

`POST /api/v1/workflow/run`，`workflowId=knowledge_qa`。

种子数据：`Support/KnowledgeSeeder` + `KnowledgeWorkflowService::ragFactory()`。

本地回归：`composer test:knowledge-workflow`。
