<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 创建/更新定时任务的原始字段入参 DTO。
 *
 * **职责**：承载 HTTP 请求体经 Request 规范化后的 snake_case 关联数组，作为 Service 写库前的中间载体。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController} 在 createTask / updateTask
 * 中调用 {@see static::fromPayloadArray}，数据来源于 Request::toPayloadArray()。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::createTask} 与
 * {@see \Test\Module\Cron\Service\CronTaskManagerService::updateTask} 取出数组后交给
 * {@see \Test\Module\Cron\Service\CronTaskPayloadBuilder} 校验并转为 {@see CronTaskPayloadDto}。
 *
 * **关键字段语义**：payload 键名与数据库列对齐（name、expression、command、exec_type、node_id 等），
 * 具体校验规则由 PayloadBuilder 按创建/更新态分别处理。
 */
class TaskPayloadInputDto extends AbstractDto
{
    /**
     * @var array<string, mixed>
     */
    #[ApiProperty(description: '原始请求字段数组（snake_case）')]
    protected array $payload = [];

    /**
     * 从 Request::toPayloadArray() 结果构造入参 DTO。
     *
     * @param array<string, mixed> $payload 已规范化的请求体字段
     */
    public static function fromPayloadArray(array $payload): self
    {
        $dto = new self();
        $dto->payload = $payload;

        return $dto;
    }

    /**
     * 取出原始 payload 数组，供 PayloadBuilder 消费。
     *
     * @return array<string, mixed>
     */
    public function toPayloadArray(): array
    {
        return $this->payload;
    }
}
