<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\Support\HttpRequestHarness;
use PHPUintTest\TestCase;
use Swoolefy\Http\RequestValidate;
use Test\Module\Cron\Dto\CronTaskManager\CreateNodeDto;
use Test\Module\Cron\Dto\CronTaskManager\CreateNodeGroupDto;
use Test\Module\Cron\Dto\CronTaskManager\CronAgentNodeGroupRowDto;
use Test\Module\Cron\Dto\CronTaskManager\CronAgentNodeRowDto;
use Test\Module\Cron\Dto\CronTaskManager\NodeGroupIdDto;
use Test\Module\Cron\Dto\CronTaskManager\UpdateNodeGroupDto;
use Test\Module\Cron\Entity\CronAgentNodeGroupEntity;
use Test\Module\Cron\Exception\CronTaskException;
use Test\Module\Cron\Request\CronTaskManager\CronNodeCreateRequest;
use Test\Module\Cron\Request\CronTaskManager\CronNodeGroupCreateRequest;
use Test\Module\Cron\Request\CronTaskManager\CronNodeGroupIdRequest;
use Test\Module\Cron\Request\CronTaskManager\CronNodeGroupUpdateRequest;
use Test\Module\Cron\Response\CronTaskManager\CronNodeGroupListResponse;
use Test\Module\Cron\Response\CronTaskManager\CronNodeListResponse;
use Test\Module\Cron\Service\CronTaskManagerService;

/**
 * 节点分组：CRUD 校验、删分组有节点时拒绝、节点列表含 group 字段、Request/DTO 映射。
 */
final class CronNodeGroupTest extends TestCase
{
    public function testAssertGroupNameRejectsBlank(): void
    {
        $this->expectException(CronTaskException::class);
        $this->expectExceptionMessage('分组名称不能为空');
        CronTaskManagerService::assertGroupName('  ');
    }

    public function testAssertGroupNameTrims(): void
    {
        $this->assertSame('php', CronTaskManagerService::assertGroupName(' php '));
    }

    public function testCreateGroupDtoRejectsBlankNameWithoutDb(): void
    {
        $this->expectException(CronTaskException::class);
        $this->expectExceptionMessage('分组名称不能为空');
        (new CronTaskManagerService())->createNodeGroup(
            (new CreateNodeGroupDto())->setGroupName('')->setRemark('x'),
        );
    }

    public function testAssertUniqueGroupName(): void
    {
        CronTaskManagerService::assertUniqueGroupName(false);
        $this->expectException(CronTaskException::class);
        $this->expectExceptionMessage('分组名称已存在');
        CronTaskManagerService::assertUniqueGroupName(true);
    }

    public function testAssertGroupDeletableRejectsWhenHasNodes(): void
    {
        CronTaskManagerService::assertGroupDeletable(0);
        $this->expectException(CronTaskException::class);
        $this->expectExceptionMessage('分组下仍有节点，无法删除');
        CronTaskManagerService::assertGroupDeletable(2);
    }

    public function testDeleteGroupRejectsEmptyIdWithoutDb(): void
    {
        $this->expectException(CronTaskException::class);
        $this->expectExceptionMessage('id不能为空');
        (new CronTaskManagerService())->deleteNodeGroup(NodeGroupIdDto::of(0));
    }

    public function testCreateNodeRequiresGroupIdWithoutDb(): void
    {
        $this->expectException(CronTaskException::class);
        $this->expectExceptionMessage('请选择所属分组');
        (new CronTaskManagerService())->createNode(
            (new CreateNodeDto())
                ->setNodeName('agent-1')
                ->setNodeIp('127.0.0.1')
                ->setGroupId(0),
        );
    }

    public function testNodeRowMapsGroupFields(): void
    {
        $dto = CronAgentNodeRowDto::fromEntityRow([
            'id' => 3,
            'group_id' => 9,
            'group_name' => 'php',
            'node_name' => 'n1',
            'node_ip' => '127.0.0.1',
        ]);
        $this->assertSame(9, $dto->getGroupId());
        $this->assertSame('php', $dto->getGroupName());

        $ungrouped = CronAgentNodeRowDto::fromEntityRow([
            'id' => 4,
            'node_name' => 'legacy',
            'node_ip' => '10.0.0.1',
        ]);
        $this->assertSame(0, $ungrouped->getGroupId());
        $this->assertSame('', $ungrouped->getGroupName());
    }

    public function testNodeListResponseIncludesGroupFields(): void
    {
        $resp = new CronNodeListResponse([
            [
                'id' => 1,
                'group_id' => 2,
                'group_name' => 'python',
                'node_name' => 'py-1',
                'node_ip' => '127.0.0.1',
            ],
        ]);
        $data = $resp->getData();
        $this->assertSame(1, $data['total']);
        $this->assertSame(2, $data['list'][0]['groupId']);
        $this->assertSame('python', $data['list'][0]['groupName']);
    }

    public function testGroupRowMapsNodeCount(): void
    {
        $dto = CronAgentNodeGroupRowDto::fromEntityRow([
            'id' => 5,
            'group_name' => 'php',
            'remark' => 'php agents',
            'node_count' => 3,
            'created_at' => '2026-08-26 10:00:00',
            'updated_at' => '2026-08-26 10:01:00',
        ]);
        $this->assertSame(5, $dto->getId());
        $this->assertSame(5, $dto->getGroupId());
        $this->assertSame('php', $dto->getGroupName());
        $this->assertSame('php agents', $dto->getRemark());
        $this->assertSame(3, $dto->getNodeCount());

        $list = new CronNodeGroupListResponse([
            ['id' => 5, 'group_name' => 'php', 'node_count' => 3],
        ]);
        $data = $list->getData();
        $this->assertSame(1, $data['total']);
        $this->assertSame(5, $data['list'][0]['id']);
        $this->assertSame(5, $data['list'][0]['groupId']);
        $this->assertSame(3, $data['list'][0]['nodeCount']);
    }

    public function testGroupRowMapsGroupIdFromIdNotGroupIdColumn(): void
    {
        $dto = CronAgentNodeGroupRowDto::fromEntityRow([
            'id' => 5,
            'group_id' => 99,
            'group_name' => 'php',
        ]);
        $this->assertSame(5, $dto->getId());
        $this->assertSame(5, $dto->getGroupId());
    }

    public function testGroupTableQueriesDoNotSelectGroupIdColumn(): void
    {
        $entitySrc = (string) file_get_contents(
            (new \ReflectionClass(CronAgentNodeGroupEntity::class))->getFileName()
        );
        $this->assertStringContainsString("protected \$pk = 'id';", $entitySrc);
        $this->assertDoesNotMatchRegularExpression('/\$group_id\b/', $entitySrc);

        $dtoSrc = (string) file_get_contents(
            (new \ReflectionClass(CronAgentNodeGroupRowDto::class))->getFileName()
        );
        $this->assertStringContainsString("\$id = (int)(\$row['id'] ?? 0);", $dtoSrc);
        $this->assertStringContainsString('$dto->setGroupId($id);', $dtoSrc);
        $this->assertStringNotContainsString("\$row['group_id']", $dtoSrc);

        $svcSrc = (string) file_get_contents(
            (new \ReflectionClass(CronTaskManagerService::class))->getFileName()
        );
        $this->assertStringContainsString(
            "->field(['id', 'group_name', 'remark', 'created_at', 'updated_at'])",
            $svcSrc,
        );
        $this->assertStringContainsString("->whereIn('group_id', \$groupIds)", $svcSrc);
        $this->assertStringContainsString("->group('group_id')", $svcSrc);
        $this->assertStringContainsString("->field(['id', 'group_name'])", $svcSrc);
        $this->assertDoesNotMatchRegularExpression(
            '/CronAgentNodeGroupEntity::query\(\)[\s\S]{0,400}[\'"]group_id[\'"]/',
            $svcSrc,
        );
    }

    public function testCreateGroupRequestMapsToDto(): void
    {
        $request = (new CronNodeGroupCreateRequest())
            ->setGroupName('  php  ')
            ->setRemark('  agents ');
        $dto = (new CreateNodeGroupDto())
            ->setGroupName($request->getGroupName())
            ->setRemark($request->getRemark());

        $this->assertSame('php', $request->getGroupName());
        $this->assertSame('agents', $request->getRemark());
        $this->assertSame('php', $dto->getGroupName());
        $this->assertSame('agents', $dto->getRemark());
    }

    public function testUpdateGroupRequestMapsToDto(): void
    {
        $request = (new CronNodeGroupUpdateRequest())
            ->setId(8)
            ->setGroupName(' python ')
            ->setRemark(null);
        $dto = (new UpdateNodeGroupDto())
            ->setId($request->getId())
            ->setGroupName($request->getGroupName())
            ->setRemark($request->getRemark());

        $this->assertSame(8, $dto->getId());
        $this->assertSame('python', $dto->getGroupName());
        $this->assertSame('', $dto->getRemark());
    }

    public function testCreateNodeRequestMapsGroupId(): void
    {
        $params = [
            'nodeName' => 'agent-1',
            'nodeIp' => '127.0.0.1',
            'groupId' => '12',
        ];
        $validate = new RequestValidate(HttpRequestHarness::requestInput(
            'POST',
            '/api/v1/nodes',
            $params,
        ));
        $validate->applyStringToIntCoercion($params, CronNodeCreateRequest::class);
        $this->assertSame(12, $params['groupId']);
        $this->assertIsInt($params['groupId']);

        $request = (new CronNodeCreateRequest())
            ->setNodeName($params['nodeName'])
            ->setNodeIp($params['nodeIp'])
            ->setGroupId($params['groupId']);
        $dto = (new CreateNodeDto())
            ->setNodeName($request->getNodeName())
            ->setNodeIp($request->getNodeIp())
            ->setGroupId($request->getGroupId());

        $this->assertSame(12, $dto->getGroupId());
        $this->assertSame('agent-1', $dto->getNodeName());
    }

    public function testGroupIdRequestStringToInt(): void
    {
        $params = ['id' => '7'];
        $validate = new RequestValidate(HttpRequestHarness::requestInput(
            'GET',
            '/api/v1/node-groups/detail',
            $params,
        ));
        $validate->applyStringToIntCoercion($params, CronNodeGroupIdRequest::class);
        $this->assertSame(7, $params['id']);
        $this->assertSame(7, (new CronNodeGroupIdRequest())->setId($params['id'])->getId());
    }
}
