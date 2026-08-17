<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response\CronTaskManager;

use Swoolefy\Http\BaseResponse;
use Test\Module\Cron\Dto\CronTaskManager\ExpressionPreviewResultDto;

class ExpressionPreviewResponse extends BaseResponse
{
    protected ExpressionPreviewResultDto $result;

    public function __construct(ExpressionPreviewResultDto $result)
    {
        $this->result = $result;
    }

    public function getData(): array
    {
        return $this->result->toDeepArray();
    }
}
