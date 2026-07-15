<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Cron 允许/跳过执行时间段单项 DTO。
 *
 * **职责**：描述一个时间窗口的 start/end 边界，用于 cron_between（允许执行）或
 * cron_skip（跳过执行）列表中的单个元素。
 *
 * **生产者**：由 {@see \Test\Module\Cron\Request\CronTaskManager\CronTaskCreateRequest} 与
 * {@see \Test\Module\Cron\Request\CronTaskManager\CronTaskUpdateRequest} 在请求校验阶段自动反序列化。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskPayloadBuilder} 规范化时间段列表后
 * 写入 {@see CronTaskPayloadDto}；最终以 JSON 存入 cron_task 表。
 *
 * **关键字段语义**：
 * - start / end：时间字符串（如 HH:mm 或完整日期时间），二者均必填
 */
class CronTimeRangeDto extends AbstractDto
{
    #[ApiProperty(description: '时间段开始')]
    #[ValidationRule(rule: 'required|string', message: 'start 不能为空')]
    protected string $start = '';

    #[ApiProperty(description: '时间段结束')]
    #[ValidationRule(rule: 'required|string', message: 'end 不能为空')]
    protected string $end = '';

    /** 获取时间段开始 */
    public function getStart(): string
    {
        return $this->start;
    }

    /** 设置时间段开始 */
    public function setStart(string $start): static
    {
        $this->start = $start;

        return $this;
    }

    /** 获取时间段结束 */
    public function getEnd(): string
    {
        return $this->end;
    }

    /** 设置时间段结束 */
    public function setEnd(string $end): static
    {
        $this->end = $end;

        return $this;
    }
}
