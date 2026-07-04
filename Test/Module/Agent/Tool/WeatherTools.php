<?php

declare(strict_types=1);

namespace Test\Module\Agent\Tool;

use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;

/**
 * 演示用天气相关 Tools（mock 数据，非真实气象 API）。
 *
 * @see https://docs.neuron-ai.dev/agent/streaming
 */
final class WeatherTools
{
    /** @var array<string, array{weather: string, temperature: string}> */
    private const CITY_WEATHER = [
        '深圳' => ['weather' => '多云', 'temperature' => '28°C'],
        'shenzhen' => ['weather' => 'Cloudy', 'temperature' => '28°C'],
        '杭州' => ['weather' => '阴', 'temperature' => '22°C'],
        'hangzhou' => ['weather' => 'Overcast', 'temperature' => '22°C'],
        '广州' => ['weather' => '晴', 'temperature' => '30°C'],
        'guangzhou' => ['weather' => 'Sunny', 'temperature' => '30°C'],
        '北京' => ['weather' => '晴', 'temperature' => '18°C'],
        'beijing' => ['weather' => 'Sunny', 'temperature' => '18°C'],
        '上海' => ['weather' => '小雨', 'temperature' => '20°C'],
        'shanghai' => ['weather' => 'Light rain', 'temperature' => '20°C'],
    ];

    /** 获取当前日期（Asia/Shanghai）。 */
    public static function getDate(): ToolInterface
    {
        return Tool::make(
            'get_date',
            'Get the current date in Asia/Shanghai timezone (YYYY-MM-DD).',
        )->setCallable(static function (): string {
            return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        });
    }

    /** 按城市与日期查询天气（mock）。 */
    public static function getWeather(): ToolInterface
    {
        return Tool::make(
            'get_weather',
            'Get weather of a location for a given date. User should supply location (city) and date (YYYY-MM-DD).',
        )
            ->addProperty(ToolProperty::make(
                'location',
                PropertyType::STRING,
                'The city name, e.g. 深圳 or Shenzhen',
                true,
            ))
            ->addProperty(ToolProperty::make(
                'date',
                PropertyType::STRING,
                'The date in format YYYY-MM-DD',
                true,
            ))
            ->setCallable(static function (string $location, string $date): string {
                $key = mb_strtolower(trim($location));
                $data = self::CITY_WEATHER[$key]
                    ?? self::CITY_WEATHER[trim($location)]
                    ?? ['weather' => '多云', 'temperature' => '25°C'];

                return json_encode([
                    'location' => $location,
                    'date' => $date,
                    'weather' => $data['weather'],
                    'temperature' => $data['temperature'],
                    'source' => 'mock',
                ], JSON_UNESCAPED_UNICODE);
            });
    }

    /**
     * @return list<ToolInterface>
     */
    public static function all(): array
    {
        return [
            self::getDate(),
            self::getWeather(),
        ];
    }
}
