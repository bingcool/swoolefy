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

namespace Swoolefy;

// 定义当前core的根路径
defined('SWOOLEFY_CORE_ROOT_PATH') or define('SWOOLEFY_CORE_ROOT_PATH', __DIR__ . '/Core');

// 定义打包检查类型
defined('SWOOLEFY_PACK_CHECK_LENGTH') or define('SWOOLEFY_PACK_CHECK_LENGTH', 'length');
defined('SWOOLEFY_PACK_CHECK_EOF') or define('SWOOLEFY_PACK_CHECK_EOF', 'eof');

// 定义服务协议常量
defined('SWOOLEFY_HTTP') or define('SWOOLEFY_HTTP', 'http');
defined('SWOOLEFY_WEBSOCKET') or define('SWOOLEFY_WEBSOCKET', 'websocket');
defined('SWOOLEFY_TCP') or define('SWOOLEFY_TCP', 'tcp');
defined('SWOOLEFY_UDP') or define('SWOOLEFY_UDP', 'udp');
defined('SWOOLEFY_MQTT') or define('SWOOLEFY_MQTT', 'mqtt');

// 定义组件可以选择使用的属性key
defined('SWOOLEFY_COM_IS_DELAY') or define('SWOOLEFY_COM_IS_DELAY', 'is_delay');
defined('SWOOLEFY_COM_IS_DESTROY') or define('SWOOLEFY_COM_IS_DESTROY', 'is_destroy');
defined('SWOOLEFY_COM_FUNC') or define('SWOOLEFY_COM_FUNC', 'func');
defined('SWOOLEFY_ENABLE_POOLS') or define('SWOOLEFY_ENABLE_POOLS', 'enable_pools');
defined('SWOOLEFY_POOLS_NUM') or define('SWOOLEFY_POOLS_NUM', 'pools_num');

// sysCollector
defined('SWOOLEFY_SYS_COLLECTOR_UDP') or define('SWOOLEFY_SYS_COLLECTOR_UDP', 'sys_collector_udp');
defined('SWOOLEFY_SYS_COLLECTOR_SWOOLEREDIS') or define('SWOOLEFY_SYS_COLLECTOR_SWOOLEREDIS', 'sys_collector_swoole_redis');
defined('SWOOLEFY_SYS_COLLECTOR_PHPREDIS') or define('SWOOLEFY_SYS_COLLECTOR_PHPREDIS', 'sys_collector_phpredis');
defined('SWOOLEFY_SYS_COLLECTOR_FILE') or define('SWOOLEFY_SYS_COLLECTOR_FILE', 'sys_collector_file');
defined('SWOOLEFY_SYS_COLLECTOR_CHANNEL') or define('SWOOLEFY_SYS_COLLECTOR_CHANNEL', 'sys_collector_channel');

defined('ROUTE_MODEL_PATHINFO') or define('ROUTE_MODEL_PATHINFO', 1);
defined('ROUTE_MODEL_QUERY_PARAMS') or define('ROUTE_MODEL_QUERY_PARAMS', 2);

defined('MQTT_PROTOCOL_LEVEL3') or define('MQTT_PROTOCOL_LEVEL3', 4);
defined('MQTT_PROTOCOL_LEVEL5') or define('MQTT_PROTOCOL_LEVEL5', 5);

defined('SWOOLEFY_VERSION') or define('SWOOLEFY_VERSION', '5.1.6');
defined('SWOOLEFY_EOF_FLAG') or define('SWOOLEFY_EOF_FLAG', '::');

defined('WORKER_CLI_START') or define('WORKER_CLI_START', 'start');
defined('WORKER_CLI_STOP') or define('WORKER_CLI_STOP', 'stop');
defined('WORKER_CLI_STATUS') or define('WORKER_CLI_STATUS', 'status');
defined('WORKER_CLI_RESTART') or define('WORKER_CLI_RESTART', 'restart');
defined('WORKER_CLI_SEND_MSG') or define('WORKER_CLI_SEND_MSG', 'send');

class Constants
{

    /**
     * swoolefy version
     */
    const SWOOLEFY_VERSION = SWOOLEFY_VERSION;

    /**
     * swoolefy env
     */
    const SWOOLEFY_ENV = SWOOLEFY_ENV;

}