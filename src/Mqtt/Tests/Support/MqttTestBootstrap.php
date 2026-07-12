<?php

declare(strict_types=1);

/**
 * MQTT 模块单测 bootstrap —— 加载常量与源码，不依赖完整 Swoolefy 应用启动。
 */

$mqttRoot = dirname(__DIR__, 2);

if (!\defined('MQTT_PROTOCOL_LEVEL3')) {
    \define('MQTT_PROTOCOL_LEVEL3', 4);
}
if (!\defined('MQTT_PROTOCOL_LEVEL5')) {
    \define('MQTT_PROTOCOL_LEVEL5', 5);
}

require_once $mqttRoot . '/MqttProtocolException.php';
require_once $mqttRoot . '/MqttTopicMatcher.php';
require_once $mqttRoot . '/MqttSession.php';
require_once $mqttRoot . '/MqttSessionManager.php';
