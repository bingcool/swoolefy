<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 表达式预览入参 DTO。
 */
class ExpressionPreviewDto extends AbstractDto
{
    #[ApiProperty(description: '待预览的表达式')]
    protected string $expression = '';

    public static function of(string $expression): self
    {
        $dto = new self();
        $dto->expression = trim($expression);

        return $dto;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function setExpression(string $expression): static
    {
        $this->expression = trim($expression);

        return $this;
    }
}
