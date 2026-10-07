<?php

/**
 * Kafka 组件入口：合并 kafka/producer、kafka/consumer 下各 Topic 文件。
 *
 * 新增 Topic：在 producer/、consumer/ 各增加 php 文件，Topic / group.id 写在对应文件中；公共 librdkafka 默认值见 KafkaConfig。
 */

declare(strict_types=1);

// 勿用 $components：include 时会覆盖 SystemEnv::loadComponents() 中的同名变量
$kafkaComponents = [];

$loadDir = static function (string $dir) use (&$kafkaComponents): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $part = require $file;
        if (!is_array($part)) {
            throw new \RuntimeException('Kafka component file must return array: ' . $file);
        }
        $intersect = array_intersect_key($kafkaComponents, $part);
        if ($intersect !== []) {
            throw new \RuntimeException(
                'Duplicate Kafka component keys: ' . implode(',', array_keys($intersect)) . ' in ' . $file,
            );
        }
        $kafkaComponents = array_merge($kafkaComponents, $part);
    }
};

$base = __DIR__ . '/kafka';
$loadDir($base . '/consumer');
$loadDir($base . '/producer');

return $kafkaComponents;
