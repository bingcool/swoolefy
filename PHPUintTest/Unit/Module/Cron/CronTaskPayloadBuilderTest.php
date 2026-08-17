<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\TestCase;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskPayloadDto;
use Test\Module\Cron\Service\CronTaskPayloadBuilder;

/**
 * PayloadBuilder：retry 入 DTO、表达式走引擎校验。
 */
final class CronTaskPayloadBuilderTest extends TestCase
{
    public function testCreatePutsRetryAndDefaultsZero(): void
    {
        $builder = new CronTaskPayloadBuilder();
        $ok = $builder->build([
            'name' => 'demo',
            'expression' => '15',
            'command' => '/bin/echo',
            'exec_type' => CronTaskPayloadDto::EXEC_TYPE_SHELL,
            'node_id' => 1,
            'retry' => 2,
        ], true);

        $this->assertFalse($ok->hasError());
        $entity = $ok->getPayload()?->toEntityArray() ?? [];
        $this->assertSame(2, $entity['retry']);
        $this->assertSame('demo', $entity['cron_name'] ?? null);
        $this->assertArrayNotHasKey('name', $entity);
    }

    public function testCreateDefaultRetryIsZero(): void
    {
        $ok = (new CronTaskPayloadBuilder())->build([
            'name' => 'demo',
            'expression' => '15',
            'command' => '/bin/echo',
            'exec_type' => 1,
            'node_id' => 1,
        ], true);

        $this->assertSame(0, $ok->getPayload()?->toEntityArray()['retry'] ?? null);
    }

    public function testNegativeRetryFails(): void
    {
        $fail = (new CronTaskPayloadBuilder())->build([
            'name' => 'demo',
            'expression' => '15',
            'command' => '/bin/echo',
            'exec_type' => 1,
            'node_id' => 1,
            'retry' => -1,
        ], true);

        $this->assertTrue($fail->hasError());
        $this->assertStringContainsString('retry', (string)$fail->getError());
    }

    public function testInvalidExpressionFails(): void
    {
        $fail = (new CronTaskPayloadBuilder())->build([
            'name' => 'demo',
            'expression' => 'not-a-cron',
            'command' => '/bin/echo',
            'exec_type' => 1,
            'node_id' => 1,
        ], true);

        $this->assertTrue($fail->hasError());
        $this->assertStringContainsString('expression', (string)$fail->getError());
    }

    public function testIntervalBelowEngineMinimumFails(): void
    {
        $fail = (new CronTaskPayloadBuilder())->build([
            'name' => 'demo',
            'expression' => '3',
            'command' => '/bin/echo',
            'exec_type' => 1,
            'node_id' => 1,
        ], true);

        $this->assertTrue($fail->hasError());
    }

    public function testLinuxCronAccepted(): void
    {
        $ok = (new CronTaskPayloadBuilder())->build([
            'name' => 'demo',
            'expression' => '*/5 * * * *',
            'command' => 'https://example.com',
            'exec_type' => 2,
            'node_id' => 1,
        ], true);

        $this->assertFalse($ok->hasError(), (string)$ok->getError());
        $this->assertSame('*/5 * * * *', $ok->getPayload()?->toEntityArray()['expression'] ?? null);
    }

    public function testPartialUpdateCanSetRetryOnly(): void
    {
        $ok = (new CronTaskPayloadBuilder())->build(['retry' => 4], false);
        $this->assertFalse($ok->hasError());
        $this->assertSame(4, $ok->getPayload()?->toEntityArray()['retry'] ?? null);
    }
}
