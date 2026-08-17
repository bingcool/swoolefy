<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\Support\HttpRequestHarness;
use PHPUintTest\TestCase;
use Swoolefy\Http\RequestValidate;
use Test\Module\Cron\CronTaskEntity;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskRowDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskIdDto;
use Test\Module\Cron\Exception\CronTaskException;
use Test\Module\Cron\Request\CronTaskManager\CronTaskIdRequest;
use Test\Module\Cron\Service\CronTaskManagerService;

/**
 * 复制后删除：列表 id 即 delete 入参；软删行不得再出现在列表查询里。
 */
final class CronTaskDeleteTest extends TestCase
{
    public function testAllocateCopyNameAppendsCopySuffix(): void
    {
        $this->assertSame(
            'demo-shell-copy',
            CronTaskManagerService::allocateCopyName('demo-shell', static fn() => false),
        );
        $this->assertSame(
            'demo-shell-copy-2',
            CronTaskManagerService::allocateCopyName('demo-shell', static fn(string $n) => $n === 'demo-shell-copy'),
        );
    }

    public function testDuplicateThenDeleteUsesListedRowId(): void
    {
        $original = CronTaskRowDto::fromEntityRow([
            'id' => 5,
            'node_id' => 1,
            'cron_name' => 'demo-shell',
            'expression' => '15',
            'command' => 'x',
            'exec_type' => 1,
        ]);
        $copyName = CronTaskManagerService::allocateCopyName($original->getName(), static fn() => false);
        $copy = CronTaskRowDto::fromEntityRow([
            'id' => 9,
            'node_id' => 1,
            'cron_name' => $copyName,
            'expression' => '15',
            'command' => 'x',
            'exec_type' => 1,
        ]);

        $this->assertSame('demo-shell-copy', $copy->getName());
        $this->assertSame(9, $copy->getId());
        $this->assertSame(9, TaskIdDto::of($copy->getId())->getId());
        $this->assertNotSame($original->getId(), $copy->getId());
    }

    public function testDeleteIdFromJsonBodyCoercesString(): void
    {
        $params = ['id' => '9'];
        $validate = new RequestValidate(HttpRequestHarness::requestInput(
            'DELETE',
            '/api/v1/tasks',
            [],
            [],
            ['content-type' => 'application/json'],
            json_encode(['id' => '9'], JSON_THROW_ON_ERROR),
        ));
        $validate->applyStringToIntCoercion($params, CronTaskIdRequest::class);

        $this->assertSame(9, $params['id']);
        $request = (new CronTaskIdRequest())->setId($params['id']);
        $this->assertSame(9, TaskIdDto::of($request->getId())->getId());
    }

    public function testDeleteIdFromQueryString(): void
    {
        $input = HttpRequestHarness::requestInput(
            'DELETE',
            '/api/v1/tasks',
            ['id' => '9'],
        );
        $this->assertSame('9', $input->input('id'));

        $params = ['id' => $input->input('id')];
        $validate = new RequestValidate($input);
        $validate->applyStringToIntCoercion($params, CronTaskIdRequest::class);
        $this->assertSame(9, $params['id']);
    }

    public function testDeleteRejectsEmptyIdWithoutHittingLoadById(): void
    {
        $this->expectException(CronTaskException::class);
        $this->expectExceptionMessage('id不能为空');
        (new CronTaskManagerService())->deleteTask(TaskIdDto::of(0));
    }

    public function testQueryNotDeletedUsesSoftDeleteField(): void
    {
        $this->assertSame('deleted_at', CronTaskEntity::getSoftDeleteField());
        $this->assertTrue((new CronTaskEntity())->isSoftDelete());
    }
}
