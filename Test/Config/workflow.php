<?php

declare(strict_types=1);

use Swoolefy\Support\Workflow\WorkflowRunStoreName;

/**
 * Workflow 引擎配置（模版：src/Stubs/workflow.conf.stub.php；create 命令自动复制到 Config/）
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
 *   - default_run_store=WorkflowRunStoreName::DB 或 REDIS
 *   - 使用 DB 前预执行 src/Support/Workflow/Schema/workflow_runs.sql
 *   - component 指向 database.php / cache.php 中已配置的高可用组件
 */
return [
    'workflow' => [
        // 默认 RunStore 别名（env WORKFLOW_RUN_STORE 可覆盖）
        'default_run_store' => env('WORKFLOW_RUN_STORE', WorkflowRunStoreName::MEMORY),
        // 条件边求值器：symfony | jsonlogic
        'condition_evaluator' => env('WORKFLOW_CONDITION_EVALUATOR', 'symfony'),
        'default_node_timeout_seconds' => 120,
        'run_stores' => [
            // 进程内存：单测 / 单 Worker 演示（不跨进程）
            WorkflowRunStoreName::MEMORY => [],

            // Redis：跨 Worker，低延迟 resume / HITL
            WorkflowRunStoreName::REDIS => [
                // 对应 Config/component/cache.php 组件别名
                'component' => env('WORKFLOW_REDIS_COMPONENT', 'redis'),
                'prefix' => env('WORKFLOW_REDIS_PREFIX', 'workflow:run:'),
                // 非 WAITING 的过期秒数；WAITING（HITL）写入时不设 TTL，避免长暂停丢任务
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
        'hitl' => [
            'auth_enabled' => false,
            'api_key' => 'test-hitl-key',
            'allowed_roles' => ['operator', 'admin'],
            'require_assignee_match' => true,
        ],
    ],
];
