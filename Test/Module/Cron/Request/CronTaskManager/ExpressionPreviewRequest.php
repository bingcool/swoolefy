<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

/**
 * 表达式预览请求。
 */
class ExpressionPreviewRequest extends BaseRequest
{
    #[ApiProperty(description: 'Cron 表达式：纯数字秒级 Interval，或五段 Linux Cron')]
    #[ValidationRule(rule: 'required|string', message: 'expression 不能为空')]
    protected string $expression = '';

    public function getExpression(): string
    {
        return trim($this->expression);
    }

    public function setExpression(string $expression): static
    {
        $this->expression = $expression;

        return $this;
    }
}
