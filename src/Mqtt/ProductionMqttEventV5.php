<?php

declare(strict_types=1);

namespace Swoolefy\Mqtt;

use Swoolefy\Core\Swfy;

/**
 * 开箱即用的 MQTT 5.0 生产 Handler（鉴权逻辑同 V3）。
 */
class ProductionMqttEventV5 extends MqttEventV5
{
    public function verify(
        $username,
        $password,
        $authentication_method,
        $authentication_data,
    ): bool {
        unset($authentication_method, $authentication_data);

        $conf = (array) (Swfy::getConf()['mqtt'] ?? []);
        $expectedUser = (string) ($conf['username'] ?? '');
        $expectedPass = (string) ($conf['password'] ?? '');

        if ($expectedUser === '' && $expectedPass === '') {
            return true;
        }

        return hash_equals($expectedUser, (string) $username)
            && hash_equals($expectedPass, (string) $password);
    }

    public function auth($code, array $properties)
    {
        // 增强认证占位：可按 authentication_method 实现 Challenge/Response
        unset($code, $properties);
    }

    public function connect(
        $protocol_name,
        $protocol_level,
        $username,
        $password,
        $client_id,
        $keep_alive,
        $properties,
        $clean_session,
        array $will = [],
    ): bool {
        unset($protocol_name, $protocol_level, $password, $properties);

        $this->sessions()->bind(
            $this->fd,
            (string) $client_id,
            (string) $username,
            (int) $keep_alive,
            MQTT_PROTOCOL_LEVEL5,
            (bool) $clean_session,
            $will,
        );

        return true;
    }

    public function disconnect()
    {
        // 主动 DISCONNECT 或 Dispatcher close 时清理会话
        $this->sessions()->remove($this->fd);
    }
}
