<?php

/**
 * Kafka 协程组件入口（由 SystemEnv::loadComponents() include 本文件）。
 *
 * 目录约定：
 *   kafka/producer/*.php  — 每个 Topic 一个文件，注册 Producer 组件（如 kafka_demo_topic_producer）
 *   kafka/consumer/*.php  — 每个 Topic 一个文件；同一 Topic 的多个消费组写在同一文件内
 *
 * Topic 名、group.id 写在对应文件顶部局部变量中；librdkafka 公共默认见 Config\KafkaConfig。
 * Broker 列表来自 Config/dc.php 的 kafka_broker_list（.env KAFKA_BROKER_LIST）。
 *
 * 每个业务 php 文件 return [ '组件名' => function () { ... }, ... ]。
 * 组件名即 Application::getApp()->get('组件名') 的 key，勿与其它 component/*.php 重复。
 *
 * 注意：
 * - 聚合变量必须用 $kafkaComponents，不能命名为 $components（会覆盖 loadComponents 外层变量）。
 * - 勿在组件文件中使用全局 const/define 定义 topic，否则 reloadGlobalConf 重复 include 会报错。
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
