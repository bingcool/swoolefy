<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Swoolefy\Exception\NacosDiscoveryException;
use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Support\Nacos\Discovery\DiscoveryClient;
use Swoolefy\Support\Nacos\Discovery\DiscoveryConfig;
use Swoolefy\Support\Nacos\Discovery\Model\ServiceInstance;
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Support\Nacos\NacosConst;
use Swoolefy\Support\Nacos\NacosLogger;

/**
 * SDK 侧 Nacos 服务发现薄封装，委托框架 DiscoveryClient。
 *
 * - 按 serviceName + groupName 缓存 DiscoveryClient，负载均衡状态可延续
 * - LOCAL_NACOS_SERVICE_AUTO_SWITCH=1 时，本地分组无实例则回退到 yaml service_register.group_name（只针对dev环境）
 * - nacos.yaml：常量 NACOS_FILE_PATH（未设置时 APP_PATH/nacos.yaml）
 * - application.yaml：常量 APP_PATH
 */
final class SdkNacosServiceDiscovery
{
    /** @var array<string, DiscoveryClient> */
    private static array $discoveryClients = [];

    /**
     * 发现可用实例并返回 Guzzle base_uri（末尾带 /）。
     *
     * @throws SdkClientException
     */
    public static function resolveBaseUri(string $serviceName): string
    {
        return self::resolveInstance($serviceName)['base_uri'];
    }

    /**
     * 发现可用实例，并返回 base_uri 与 metadata。
     *
     * @return array{base_uri: string, metadata: array<string, mixed>}
     *
     * @throws SdkClientException
     */
    public static function resolveInstance(string $serviceName): array
    {
        if ('' === trim($serviceName)) {
            throw new SdkClientException('Nacos service name is empty');
        }

        try {
            $discoveryConfig = DiscoveryConfig::load();
            $instance = self::chooseInstance($serviceName, $discoveryConfig);
            $useInnerExternalBaseUri = false;

            // 本地分组（如 bingcool）无实例时，回退到 yaml 中 定义的 部署分组（dev环境
            // 同时确保本地开发环境能够调通dev部署的环境，即本地开发环境能够访问dev环境各个服务注册到nacos的ip
            if (null === $instance && self::isLocalAutoSwitchEnabled()) {
                $fallbackGroup = self::resolveYamlRegisterGroupName();
                $currentGroup = $discoveryConfig->groupName;
                if ('' !== $fallbackGroup && $fallbackGroup !== $currentGroup) {
                    NacosLogger::get()->info(sprintf(
                        'nacos local auto switch: service=%s, group %s -> %s',
                        $serviceName,
                        $currentGroup,
                        $fallbackGroup,
                    ));
                    $instance = self::chooseInstance(
                        $serviceName,
                        self::discoveryConfigWithGroup($discoveryConfig, $fallbackGroup),
                    );
                    $useInnerExternalBaseUri = $instance instanceof ServiceInstance;

                    if (function_exists('fmtPrintNote')) {
                        if ($instance instanceof ServiceInstance) {
                            fmtPrintNote(sprintf(
                                "调用nacos注册的服务【%s】自动切换到其开发环境调用uri=%s",
                                $serviceName,
                                self::resolveBaseUriFromInstance($instance, $useInnerExternalBaseUri),
                            ));
                        } else {
                            fmtPrintNote(sprintf("调用nacos注册的服务【%s】自动切换到其开发环境, 无法获取其host+ip", $serviceName));
                        }
                    }
                }
            }
        } catch (NacosDiscoveryException $e) {
            throw new SdkClientException($e->getMessage());
        } catch (\Throwable $e) {
            throw new SdkClientException('Nacos discovery failed: ' . $e->getMessage());
        }

        if (!$instance instanceof ServiceInstance) {
            throw new SdkClientException(sprintf(
                'No available Nacos instance for service [%s]',
                $serviceName,
            ));
        }

        return [
            'base_uri' => self::resolveBaseUriFromInstance($instance, $useInnerExternalBaseUri),
            'metadata' => $instance->getMetadata(),
        ];
    }

    private static function resolveBaseUriFromInstance(ServiceInstance $instance, bool $useInnerExternalBaseUri): string
    {
        if ($useInnerExternalBaseUri) {
            $metadata = $instance->getMetadata();
            $innerExternalBaseUri = (string) ($metadata[NacosConst::METADATA_INNER_EXTERNAL_BASE_URI] ?? '');
            if ('' !== $innerExternalBaseUri) {
                return rtrim($innerExternalBaseUri, '/') . '/';
            }
        }

        return rtrim($instance->getUri('http'), '/') . '/';
    }

    private static function chooseInstance(string $serviceName, DiscoveryConfig $discoveryConfig): ?ServiceInstance
    {
        $client = self::getDiscoveryClient($serviceName, $discoveryConfig);
        $instance = $client->choose();
        if (!$instance instanceof ServiceInstance) {
            $instance = $client->refresh() !== [] ? $client->choose() : null;
        }

        return $instance;
    }

    private static function isLocalAutoSwitchEnabled(): bool
    {
        $env = getenv(NacosConst::ENV_LOCAL_NACOS_SERVICE_AUTO_SWITCH);
        if (false === $env || '' === $env) {
            return false;
        }

        return filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }

    /** application.yaml → nacos.service_register.group_name（yaml 优先，即 dev 部署分组） */
    private static function resolveYamlRegisterGroupName(): string
    {
        $section = ApplicationConfig::load()->nacosSection('service_register');

        return ApplicationConfig::pickString($section, 'group_name', NacosConst::ENV_SERVICE_GROUP_NAME, '');
    }

    private static function discoveryConfigWithGroup(DiscoveryConfig $base, string $groupName): DiscoveryConfig
    {
        return new DiscoveryConfig(
            $base->cacheTtl,
            $base->loadBalancer,
            $base->healthyOnly,
            $base->clusters,
            $groupName,
            $base->namespaceId,
        );
    }

    private static function getDiscoveryClient(string $serviceName, DiscoveryConfig $discoveryConfig): DiscoveryClient
    {
        $cacheKey = $serviceName . '@' . $discoveryConfig->groupName;
        if (isset(self::$discoveryClients[$cacheKey])) {
            return self::$discoveryClients[$cacheKey];
        }

        self::$discoveryClients[$cacheKey] = DiscoveryClient::create(
            $serviceName,
            NacosConfig::load(),
            $discoveryConfig,
        );

        return self::$discoveryClients[$cacheKey];
    }
}
