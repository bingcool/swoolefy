<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Cron 允许/跳过执行时间段单项（start/end 时间字符串）。
 */
class CronTimeRangeDto extends AbstractDto
{
    #[ApiProperty(description: '时间段开始')]
    #[ValidationRule(rule: 'required|string', message: 'start 不能为空')]
    protected string $start = '';

    #[ApiProperty(description: '时间段结束')]
    #[ValidationRule(rule: 'required|string', message: 'end 不能为空')]
    protected string $end = '';

    public function getStart(): string
    {
        return $this->start;
    }

    public function setStart(string $start): self
    {
        $this->start = $start;

        return $this;
    }

    public function getEnd(): string
    {
        return $this->end;
    }

    public function setEnd(string $end): self
    {
        $this->end = $end;

        return $this;
    }
}
