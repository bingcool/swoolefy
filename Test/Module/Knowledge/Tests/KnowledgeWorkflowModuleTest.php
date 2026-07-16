<?php

declare(strict_types=1);

/**
 * Knowledge 模块工作流独立性回归（与 Order/Outdoor 同一装配模式）。
 *
 * ```bash
 * php Test/Module/Knowledge/Tests/KnowledgeWorkflowModuleTest.php
 * ```
 */

use Swoolefy\Support\Workflow\Engine\RunStatus;
use Test\Module\Knowledge\KnowledgeWorkflowService;
use Test\Module\Knowledge\Support\KnowledgeSeeder;
use Test\Module\Workflow\WorkflowService;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testKnowledgeRegistryIndependent(): void
{
    KnowledgeWorkflowService::reset();
    WorkflowService::reset();

    $knowledge = KnowledgeWorkflowService::registry();
    $central = WorkflowService::registry();

    assertTrue($knowledge !== $central, 'Knowledge registry must be distinct');
    assertTrue($knowledge->has('knowledge_qa'), 'has knowledge_qa');
    assertTrue($knowledge->ids() === ['knowledge_qa'], 'only knowledge workflows');
    assertTrue(
        WorkflowService::registryFor('knowledge_qa') === $knowledge,
        'federation routes to Knowledge',
    );
}

function testKnowledgeQaViaModuleEngine(): void
{
    KnowledgeWorkflowService::reset();
    (new KnowledgeSeeder(KnowledgeWorkflowService::ragFactory()))->seedProductKb();

    $engine = KnowledgeWorkflowService::engine();
    $runId = $engine->start(
        KnowledgeWorkflowService::registry()->compiled('knowledge_qa'),
        [
            'question' => '门框尺寸是多少？',
            'sessionId' => 's-kb-mod-1',
        ],
    );
    $run = $engine->getRun($runId);

    assertTrue($run->status === RunStatus::COMPLETED, 'knowledge_qa should complete');
    assertTrue(is_array($run->state->get('retrievedDocs')), 'retrievedDocs');
    assertTrue(is_string($run->state->get('answer')), 'answer');
}

$tests = [
    'knowledge registry independent' => 'testKnowledgeRegistryIndependent',
    'knowledge qa via module engine' => 'testKnowledgeQaViaModuleEngine',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Knowledge workflow module tests passed.\n";
