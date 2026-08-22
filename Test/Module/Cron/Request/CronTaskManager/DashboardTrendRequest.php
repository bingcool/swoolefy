<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseRequest;

/**
 * 执行趋势查询：range=24h / 7d / 15d。
 */
class DashboardTrendRequest extends BaseRequest
{
    #[ApiProperty(description: '时间范围：24h / 7d / 15d')]
    protected string $range = '24h';

    public function getRange(): string
    {
        $range = strtolower(trim($this->range));

        return in_array($range, ['24h', '7d', '15d'], true) ? $range : '24h';
    }

    public function setRange(string $range): static
    {
        $this->range = $range;

        return $this;
    }
}
