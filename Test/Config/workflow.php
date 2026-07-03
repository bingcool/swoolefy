<?php

declare(strict_types=1);

/**
 * Workflow 引擎配置（复制到 APP_PATH/config/workflow.php）
 * RAG / MCP / Neuron 见 neuron_ai.php
 */
return [
    'workflow' => [
        // memory | redis（redis 时使用 component 指向 cache.php 中的组件别名）
        'run_store' => 'memory',
        'condition_evaluator' => 'symfony', // symfony | jsonlogic
        'redis' => [
            'component' => 'redis', // 对应 Config/component/cache.php 的 redis | predis
            'prefix' => 'workflow:run:',
            'ttl' => 86400,
        ],
    ],
];
