<?php

declare(strict_types=1);

namespace Swoolefy\Support\Kafka;

use Swoolefy\Core\SystemEnv;

/**
 * Broker 列表来自 Config/dc.php 的 kafka_broker_list，对应 .env KAFKA_BROKER_LIST。
 */
final class KafkaBrokers
{
    public static function resolve(string $override = ''): string
    {
        $brokers = trim($override);
        if ($brokers === '') {
            $list = SystemEnv::loadDcEnv()['kafka_broker_list'] ?? '';
            if (is_array($list)) {
                $brokers = implode(',', $list);
            } else {
                $brokers = (string) $list;
            }
            $brokers = trim($brokers);
        }
        if ($brokers === '') {
            throw new KafkaException('kafka broker list is empty');
        }

        return $brokers;
    }
}
