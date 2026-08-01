<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support;

use PHPUintTest\Support\HttpRequestHarness;
use PHPUintTest\TestCase;

/**
 * P6：进程内 RequestInput 装配可被 Controller 单测复用。
 */
final class HttpRequestHarnessTest extends TestCase
{
    /**
     * 验证：GET query 与 POST 字段经 RequestInput::input 可读。
     */
    public function testBuildsRequestInputFromQueryAndPost(): void
    {
        $input = HttpRequestHarness::requestInput(
            'POST',
            '/api/v1/order/workflow/process',
            query: ['trace' => '1'],
            post: ['orderId' => 'ORD-HARNESS-1', 'amount' => 9.9],
            headers: ['accept' => 'application/json'],
        );

        $this->assertTrue($input->isPost());
        $this->assertSame('ORD-HARNESS-1', $input->input('orderId'));
        $this->assertSame('1', $input->input('trace'));
        $this->assertSame(9.9, $input->input('amount'));
    }

    /**
     * 验证：jsonBody 字段合并进 input（无真实 socket rawContent）。
     */
    public function testJsonBodyMergedIntoInput(): void
    {
        $input = HttpRequestHarness::requestInput(
            'POST',
            '/api/v1/workflow/run',
            jsonBody: json_encode([
                'workflowId' => 'contract_review',
                'input' => ['contractBrief' => 'harness'],
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertSame('contract_review', $input->input('workflowId'));
        $payload = $input->input('input');
        $this->assertIsArray($payload);
        $this->assertSame('harness', $payload['contractBrief'] ?? null);
    }
}
