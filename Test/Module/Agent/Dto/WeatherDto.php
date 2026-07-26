<?php

declare(strict_types=1);

namespace Test\Module\Agent\Dto;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\ArrayDto;

/**
 * 天气结构化输出 DTO（四个字段）。
 */
final class WeatherDto extends ArrayDto
{
    #[ApiProperty(description: '天气状况，如晴、多云、小雨')]
    public string $weather;

    #[ApiProperty(description: '城市名称')]
    public string $city;

    #[ApiProperty(description: '今天的日期，格式 YYYY-MM-DD，必须是当前日期')]
    public string $date;

    #[ApiProperty(description: '气温，如 26°C')]
    public string $temperature;
}
