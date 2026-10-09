<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Core\Sidecar\SidecarSpec;

/**
 * rocketmq Sidecar 已启用时，为每个消费组 + Topic 注册一个 PHP 消费进程。
 */
final class RocketmqConsumerRegistrar
{
    private const NAME = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * @param list<SidecarSpec> $specs
     */
    public static function register(array $specs): void
    {
        foreach (self::rows($specs) as $row) {
            $topic = $row['sidecar_rocketmq_consume_topics'];
            $group = $row['sidecar_rocketmq_consumer_group'];
            ProcessManager::getInstance()->addProcess(
                'swoolefy_rocketmq_consumer_' . $group . '_' . $topic,
                RocketmqConsumerProcess::class,
                true,
                [],
                $row
            );
        }
    }

    /**
     * @param list<SidecarSpec> $specs
     * @return list<array{sidecar_rocketmq_consume_topics: string, sidecar_rocketmq_consumer_group: string, sidecar_rocketmq_consumer_tag: string, sidecar_rocketmq_consumer_concurrency: int}>
     */
    public static function rows(array $specs): array
    {
        $conf = BaseServer::getConf();
        $raw = $conf['rocketmq_consumers'] ?? [];
        if ($raw === null) {
            $raw = [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new SidecarException('rocketmq_consumers must be a list');
        }
        if ($raw === []) {
            return [];
        }
        if (!self::rocketmqEnabled($specs)) {
            throw new SidecarException('rocketmq_consumers require an enabled rocketmq sidecar');
        }

        $seen = [];
        $rows = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                throw new SidecarException('rocketmq_consumers item must be an array');
            }
            foreach ($item as $key => $_) {
                if (!is_string($key) || !in_array($key, [
                    'sidecar_rocketmq_consume_topics',
                    'sidecar_rocketmq_consumer_group',
                    'sidecar_rocketmq_consumer_tag',
                    'sidecar_rocketmq_consumer_concurrency',
                ], true)) {
                    throw new SidecarException('unknown rocketmq consumer key');
                }
            }
            $topic = $item['sidecar_rocketmq_consume_topics'] ?? null;
            $group = $item['sidecar_rocketmq_consumer_group'] ?? null;
            if (!is_string($topic) || !preg_match(self::NAME, $topic)) {
                throw new SidecarException('sidecar_rocketmq_consume_topics is invalid');
            }
            if (!is_string($group) || !preg_match(self::NAME, $group)) {
                throw new SidecarException('sidecar_rocketmq_consumer_group is invalid');
            }
            $pair = $group . "\0" . $topic;
            if (isset($seen[$pair])) {
                throw new SidecarException('duplicate rocketmq consumer: ' . $group . ' ' . $topic);
            }
            $seen[$pair] = true;
            $tag = $item['sidecar_rocketmq_consumer_tag'] ?? '*';
            if (!is_string($tag)) {
                throw new SidecarException('sidecar_rocketmq_consumer_tag must be a string');
            }
            $concurrency = $item['sidecar_rocketmq_consumer_concurrency'] ?? 1;
            if (!is_int($concurrency) || $concurrency < 1 || $concurrency > 32) {
                throw new SidecarException('sidecar_rocketmq_consumer_concurrency out of range');
            }
            $rows[] = [
                'sidecar_rocketmq_consume_topics' => $topic,
                'sidecar_rocketmq_consumer_group' => $group,
                'sidecar_rocketmq_consumer_tag' => $tag,
                'sidecar_rocketmq_consumer_concurrency' => $concurrency,
            ];
        }

        return $rows;
    }

    /**
     * @param list<SidecarSpec> $specs
     */
    private static function rocketmqEnabled(array $specs): bool
    {
        foreach ($specs as $spec) {
            if (in_array('rocketmq', explode(',', $spec->plugins), true)) {
                return true;
            }
        }

        return false;
    }
}
