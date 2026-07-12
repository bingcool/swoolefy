<?php

declare(strict_types=1);

namespace Swoolefy\Mqtt;

use Swoolefy\Core\Swfy;

/**
 * 开箱即用的 MQTT 3.x 生产 Handler。
 *
 * conf.mqtt 配置项：
 * - username / password 为空：不校验（仅限内网/开发）
 * - 非空：hash_equals 恒定时间比较
 *
 * connect() 自动 sessions()->bind()；disconnect() 自动 remove()。
 *
 * 配置示例：
 *   'mqtt_event_handler' => \Swoolefy\Mqtt\ProductionMqttEventV3::class,
 */
class ProductionMqttEventV3 extends MqttEventV3
{
    public function verify($username, $password): bool
    {
        $conf = (array) (Swfy::getConf()['mqtt'] ?? []);
        $expectedUser = (string) ($conf['username'] ?? '');
        $expectedPass = (string) ($conf['password'] ?? '');

        // 未配置账号密码：跳过鉴权（仅限内网/开发环境）
        if ($expectedUser === '' && $expectedPass === '') {
            return true;
        }

        // hash_equals 防止时序攻击
        return hash_equals($expectedUser, (string) $username)
            && hash_equals($expectedPass, (string) $password);
    }

    public function connect(
        $protocol_name,
        $protocol_level,
        $username,
        $password,
        $client_id,
        $keep_alive,
        $clean_session,
        array $will = [],
    ): bool {
        unset($protocol_level, $password);

        if ((string) $protocol_name !== '' && (string) $protocol_name !== 'MQTT') {
            return false;
        }

        // CONNECT 成功：注册会话（含 client_id 踢旧、will retain、clean session）
        $this->sessions()->bind(
            $this->fd,
            (string) $client_id,
            (string) $username,
            (int) $keep_alive,
            MQTT_PROTOCOL_LEVEL3,
            (bool) $clean_session,
            $will,
        );

        return true;
    }

    public function disconnect()
    {
        // 客户端主动 DISCONNECT 时清理 Worker 内会话
        $this->sessions()->remove($this->fd);
    }
}
