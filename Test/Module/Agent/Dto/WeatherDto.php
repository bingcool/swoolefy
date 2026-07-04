<?php

declare(strict_types=1);

namespace Test\Module\Agent\Dto;

use NeuronAI\StructuredOutput\SchemaProperty;
use Swoolefy\Core\Dto\ArrayDto;

/**
 * 天气结构化输出 DTO（四个字段）。
 */
final class WeatherDto extends ArrayDto
{
    #[SchemaProperty(description: '天气状况，如晴、多云、小雨', required: true)]
    public string $weather;

    #[SchemaProperty(description: '城市名称', required: true)]
    public string $city;

    #[SchemaProperty(description: '今天的日期，格式 YYYY-MM-DD，必须是当前日期', required: true)]
    public string $date;

    #[SchemaProperty(description: '气温，如 26°C', required: true)]
    public string $temperature;
}
