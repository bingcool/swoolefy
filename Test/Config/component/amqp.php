<?php

/**
 * AMQP 协程组件入口（由 SystemEnv::loadComponents() include 本文件）。
 *
 * 目录约定：
 *   amqp/connection.php     — 连接 amqpConnection（读 dc.php 的 amqp_connection）
 *   amqp/direct/*.php       — Direct 交换机：精准路由，发布与消费可写在同一文件
 *   amqp/fanout/*.php       — Fanout 广播：忽略 routing_key，多队列各收一份
 *   amqp/topic/*.php        — Topic 通配 binding_key，发布时在消息上指定 routing_key
 *
 * 每个业务 php 文件 return [ '组件名' => function() { ... }, ... ]。
 * 组件名即 Application::getApp()->get('组件名') 的 key，勿与其它 component/*.php 重复。
 *
 * 注意：
 * - 聚合变量必须用 $amqpComponents，不能命名为 $components（会覆盖 loadComponents 外层变量）。
 * - 文件内用局部变量定义 exchange/queue，勿用全局 const，否则 reloadGlobalConf 重复 include 会报错。
 * - 公共队列默认值见 Test\Config\AmqpConfig::queueProperty()；差异项在各自文件中覆盖。
 */

declare(strict_types=1);

$amqpComponents = [];

$mergePart = static function (array $part, string $source) use (&$amqpComponents): void {
    $intersect = array_intersect_key($amqpComponents, $part);
    if ($intersect !== []) {
        throw new \RuntimeException(
            'Duplicate AMQP component keys: ' . implode(',', array_keys($intersect)) . ' in ' . $source,
        );
    }
    $amqpComponents = array_merge($amqpComponents, $part);
};

$loadFile = static function (string $file) use ($mergePart): void {
    if (!is_readable($file)) {
        return;
    }
    $part = require $file;
    if (!is_array($part)) {
        throw new \RuntimeException('AMQP component file must return array: ' . $file);
    }
    $mergePart($part, $file);
};

$loadDir = static function (string $dir) use ($loadFile): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $loadFile($file);
    }
};

$base = __DIR__ . '/amqp';
$loadFile($base . '/connection.php');
$loadDir($base . '/direct');
$loadDir($base . '/fanout');
$loadDir($base . '/topic');

return $amqpComponents;
