<?php

declare(strict_types=1);

namespace Test\Module\Agent;

use Swoolefy\Support\CapabilityCenter\CapabilityComponentFactory;
use Swoolefy\Support\CapabilityCenter\CapabilityDescriptor;
use Swoolefy\Support\CapabilityCenter\CapabilitySource;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronFactory;
use Test\Module\Agent\Tool\WeatherTools;

/**
 * CapabilityCenter 天气 Tool 演示装配。
 *
 * 将 Native 天气 Tool 注册为 Capability descriptor，并提供可注入 NeuronFactory 的
 * CapabilityComponentFactory，供 resolve / chat 两个测试接口复用。
 */
final class CapabilityWeatherDemo
{
    /**
     * 构建带 Capability 配置的 NeuronFactory。
     *
     * CapabilityToolAgent 自身不在 tools() 中全量挂载 Tool；
     * NeuronFactory::boot() 会根据 agentOptions 走 CapabilityCenter 解析并 addTool()。
     */
    public static function neuronFactory(int $topK = 12, bool $debug = false): NeuronFactory
    {
        $config = self::capabilityConfig($topK, $debug);

        return new NeuronFactory(
            capabilityFactory: self::capabilityComponentFactory($config),
            config: $config,
        );
    }

    public static function capabilityComponentFactory(?NeuronAiConfig $config = null): CapabilityComponentFactory
    {
        $config ??= self::capabilityConfig();

        return new CapabilityComponentFactory(
            config: $config,
            nativeFactories: self::nativeFactories(),
            nativeDescriptors: self::descriptors(),
        );
    }

    public static function capabilityConfig(int $topK = 12, bool $debug = false): NeuronAiConfig
    {
        $base = NeuronAiConfig::load();

        return NeuronAiConfig::fromArray([
            'neuron' => $base->neuronSection(),
            'capability' => [
                // 全局默认仍关闭；具体请求通过 agentOptions['capabilityEnabled']=true 开启。
                'enabled' => false,
                'default_top_k' => $topK,
                'max_schema_tools' => 20,
                'mcp_sync_on_boot' => false,
                'debug' => $debug,
                'fail_closed' => false,
            ],
        ]);
    }

    /**
     * 组装 Capability 解析所需的 agentOptions。
     *
     * @param list<string>         $pinnedTools
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function agentOptions(
        string $message,
        int $topK = 1,
        array $pinnedTools = [],
        array $extra = [],
    ): array {
        return array_merge([
            'message' => $message,
            'capabilityEnabled' => true,
            'capabilityTopK' => $topK,
            'capabilityProfile' => 'weather',
            'profileTags' => ['weather'],
            'pinnedTools' => $pinnedTools,
        ], $extra);
    }

    /** @return list<CapabilityDescriptor> */
    public static function descriptors(): array
    {
        return [
            new CapabilityDescriptor(
                id: 'native:weather:get_date',
                name: 'get_date',
                description: 'Get the current date in Asia/Shanghai timezone.',
                source: CapabilitySource::Native,
                tags: ['weather', 'date', 'time'],
                executorRef: 'native:weather:get_date',
            ),
            new CapabilityDescriptor(
                id: 'native:weather:get_weather',
                name: 'get_weather',
                description: 'Get weather of a location for a given date.',
                source: CapabilitySource::Native,
                tags: ['weather', 'city', 'forecast'],
                executorRef: 'native:weather:get_weather',
            ),
        ];
    }

    /** @return array<string, callable(): \NeuronAI\Tools\ToolInterface> */
    private static function nativeFactories(): array
    {
        return [
            'native:weather:get_date' => static fn () => WeatherTools::getDate(),
            'native:weather:get_weather' => static fn () => WeatherTools::getWeather(),
        ];
    }
}
