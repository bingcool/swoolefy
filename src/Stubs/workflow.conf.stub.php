<?php

declare(strict_types=1);

use Swoolefy\Support\Workflow\WorkflowRunStoreName;

/**
 * Workflow 引擎配置（create 命令从 Stubs 复制到 APP_PATH/Config/workflow.php）
 *
 * RAG / MCP / Neuron 见 neuron_ai.php
 *
 * RunStore：
 *   default_run_store — 默认别名（env WORKFLOW_RUN_STORE 可覆盖）
 *   run_stores[alias] — 连接参数；可选 driver（缺省时别名即驱动）
 *
 * 驱动常量：{@see WorkflowRunStoreName::MEMORY|REDIS|DB}
 *
 * 生产建议：
 *   - 默认 default_run_store=DB（跨 Worker HITL/resume）；本地单测可 env WORKFLOW_RUN_STORE=memory
 *   - 使用 DB 前预执行 src/Support/Workflow/Schema/workflow_runs.sql
 *   - component 指向 Config/component/database.php / cache.php 中已配置的高可用组件
 */
return [
    'workflow' => [
        // 默认 RunStore 别名（env WORKFLOW_RUN_STORE 可覆盖；本地单测可设为 memory）
        'default_run_store' => env('WORKFLOW_RUN_STORE', WorkflowRunStoreName::DB),
        // 条件边求值器：symfony | jsonlogic
        'condition_evaluator' => env('WORKFLOW_CONDITION_EVALUATOR', 'symfony'),
        // 节点默认超时秒数（0=不限制；生产建议 120）
        'default_node_timeout_seconds' => (int) env('WORKFLOW_DEFAULT_NODE_TIMEOUT', 120),
        'run_stores' => [
            // 进程内存：单测 / 单 Worker 演示（不跨进程）
            WorkflowRunStoreName::MEMORY => [],

            // Redis：跨 Worker，低延迟 resume / HITL
            WorkflowRunStoreName::REDIS => [
                // 对应 Config/component/cache.php 组件别名
                'component' => env('WORKFLOW_REDIS_COMPONENT', 'redis'),
                'prefix' => env('WORKFLOW_REDIS_PREFIX', 'workflow:run:'),
                'ttl' => (int) env('WORKFLOW_REDIS_TTL', 86400),
            ],

            // DB：跨 Worker，可按 status/assignee 查询，适合审计与高可用主从库
            // 表结构见 Schema/workflow_runs.sql（须预先建表）
            WorkflowRunStoreName::DB => [
                // 对应 Config/component/database.php 组件别名
                'component' => env('WORKFLOW_DB_COMPONENT', 'db'),
                'table' => env('WORKFLOW_DB_TABLE', 'workflow_runs'),
            ],
        ],
        // HITL API 鉴权（resume / cancel / pause/tasks）
        'hitl' => [
            'auth_enabled' => filter_var(env('WORKFLOW_HITL_AUTH_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN),
            'api_key' => env('WORKFLOW_HITL_API_KEY', ''),
            'role_header' => env('WORKFLOW_HITL_ROLE_HEADER', 'X-Workflow-Role'),
            'allowed_roles' => ['operator', 'admin'],
            'require_assignee_match' => filter_var(env('WORKFLOW_HITL_REQUIRE_ASSIGNEE_MATCH', '1'), FILTER_VALIDATE_BOOLEAN),
        ],
    ],
];
