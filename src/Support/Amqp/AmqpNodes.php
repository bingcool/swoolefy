<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

use Swoolefy\Core\SystemEnv;

/**
 * 连接节点来自 Config/dc.php 的 amqp_connection，对应 .env 的 AMQP_*。
 */
final class AmqpNodes
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function hosts(): array
    {
        $dc = SystemEnv::loadDcEnv()['amqp_connection'] ?? null;
        if (!is_array($dc)) {
            throw new AmqpException('amqp_connection is missing');
        }
        $options = is_array($dc['options'] ?? null) ? $dc['options'] : [];
        $heartbeat = (int) ($options['heartbeat'] ?? 10);
        $readTimeout = (float) ($options['read_write_timeout'] ?? 0);
        if ($heartbeat > 0 && $readTimeout < ($heartbeat * 2)) {
            $readTimeout = (float) ($heartbeat * 2);
        }
        if ($readTimeout <= 0) {
            $readTimeout = 3.0;
        }
        $loginResponse = $options['login_response'] ?? null;
        if ($loginResponse === '') {
            $loginResponse = null;
        }
        $shared = [
            'insist' => (bool) ($options['insist'] ?? false),
            'login_method' => (string) ($options['login_method'] ?? 'AMQPLAIN'),
            'login_response' => $loginResponse,
            'locale' => (string) ($options['locale'] ?? 'en_US'),
            'connection_timeout' => (float) ($options['connection_timeout'] ?? 3),
            'read_write_timeout' => $readTimeout,
            'context' => $options['context'] ?? null,
            'keepalive' => (bool) ($options['keepalive'] ?? true),
            'heartbeat' => $heartbeat,
        ];

        $hosts = [];
        $list = $dc['host_list'] ?? [];
        if (!is_array($list)) {
            throw new AmqpException('amqp host list is empty');
        }
        foreach ($list as $node) {
            if (!is_array($node)) {
                continue;
            }
            $host = trim((string) ($node['host'] ?? ''));
            if ($host === '') {
                continue;
            }
            $hosts[] = array_merge($shared, [
                'host' => $host,
                'port' => (int) ($node['port'] ?? 5672),
                'user' => (string) ($node['user'] ?? 'guest'),
                'password' => (string) ($node['password'] ?? 'guest'),
                'vhost' => (string) ($node['vhost'] ?? '/'),
            ]);
        }
        if ($hosts === []) {
            throw new AmqpException('amqp host list is empty');
        }

        return $hosts;
    }
}
