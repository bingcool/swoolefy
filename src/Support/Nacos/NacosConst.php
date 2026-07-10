<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

/**
 * Nacos 相关环境变量名常量。
 */
final class NacosConst
{
    /** nacos.yaml 完整路径（cli.php 注入为 PHP 常量 NACOS_FILE_PATH） */
    public const ENV_FILE_PATH = 'NACOS_FILE_PATH';

    // nacos.yaml → 服务器连接
    public const ENV_NACOS_HOST = 'NACOS_HOST';
    public const ENV_NACOS_PORT = 'NACOS_PORT';
    public const ENV_NACOS_USERNAME = 'NACOS_USERNAME';
    public const ENV_NACOS_PASSWORD = 'NACOS_PASSWORD';
    public const ENV_NACOS_AUTHORIZATION_BEARER = 'NACOS_AUTHORIZATION_BEARER';

    // application.yaml → nacos.service_config
    // 租户|命名空间：dev|test|prod不同环境
    public const ENV_NACOS_TENANT = 'NACOS_TENANT';

    // application.yaml → nacos.service_register
    public const ENV_SERVICE_REGISTER_HOST = 'NACOS_SERVICE_REGISTER_HOST';
    public const ENV_SERVICE_REGISTER_PORT = 'NACOS_SERVICE_REGISTER_PORT';
    public const ENV_POD_IP = 'POD_IP';
    public const ENV_SERVICE_NAME = 'NACOS_SERVICE_NAME';
    public const ENV_SERVICE_NAMESPACE_ID = 'NACOS_SERVICE_NAMESPACE_ID';
    public const ENV_SERVICE_GROUP_NAME = 'NACOS_SERVICE_GROUP_NAME';
    public const ENV_SERVICE_WEIGHT = 'NACOS_SERVICE_WEIGHT';
    public const ENV_SERVICE_HEARTBEAT_INTERVAL = 'NACOS_SERVICE_HEARTBEAT_INTERVAL';
    public const ENV_INNER_EXTERNAL_BASE_URI = 'INNER_EXTERNAL_BASE_URI';

    // Nacos instance metadata keys
    public const METADATA_INNER_EXTERNAL_BASE_URI = 'inner_external_base_uri';

    // application.yaml → nacos.discovery_service_client
    public const ENV_DISCOVERY_CACHE_TTL = 'NACOS_DISCOVERY_CACHE_TTL';
    public const ENV_DISCOVERY_LOAD_BALANCER = 'NACOS_DISCOVERY_LOAD_BALANCER';
    public const ENV_DISCOVERY_HEALTHY_ONLY = 'NACOS_DISCOVERY_HEALTHY_ONLY';
    public const ENV_DISCOVERY_CLUSTERS = 'NACOS_DISCOVERY_CLUSTERS';

    /** 本地开发：当前分组无可用实例时，自动回退到 application.yaml → nacos.service_register.group_name */
    public const ENV_LOCAL_NACOS_SERVICE_AUTO_SWITCH = 'LOCAL_NACOS_SERVICE_AUTO_SWITCH';

    // application.yaml → nacos.monitor_config_change
    public const ENV_ENV_FILE = 'NACOS_ENV_FILE';
    public const ENV_RELOAD_LOCK = 'NACOS_RELOAD_LOCK';
    public const ENV_LISTENER_TIMEOUT_MS = 'NACOS_LISTENER_TIMEOUT_MS';
    public const ENV_LISTENER_FAILED_MS = 'NACOS_LISTENER_FAILED_MS';

}
