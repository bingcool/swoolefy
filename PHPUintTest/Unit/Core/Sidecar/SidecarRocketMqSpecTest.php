<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core\Sidecar;

use PHPUintTest\TestCase;
use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Core\Sidecar\Plugin\SidecarRocketMqSpec;

final class SidecarRocketMqSpecTest extends TestCase
{
    public function testEmptyEndpointThrows(): void
    {
        $this->expectException(SidecarException::class);
        $this->expectExceptionMessage('must be host:port');
        SidecarRocketMqSpec::fields([
            'sidecar_name' => 'rocketmq',
            'sidecar_rocketmq_proxy_endpoint' => '',
            'sidecar_rocketmq_access_key' => '',
            'sidecar_rocketmq_secret_key' => '',
            'sidecar_rocketmq_allowed_topics' => '',
        ]);
    }

    public function testValidEndpointReturnsOnlyPluginKeys(): void
    {
        $fields = SidecarRocketMqSpec::fields([
            'sidecar_name' => 'rocketmq',
            'sidecar_rocketmq_proxy_endpoint' => '127.0.0.1:8081',
            'sidecar_rocketmq_access_key' => '',
            'sidecar_rocketmq_secret_key' => '',
            'sidecar_rocketmq_allowed_topics' => '*',
        ]);

        $this->assertSame([
            'sidecar_rocketmq_proxy_endpoint' => '127.0.0.1:8081',
            'sidecar_rocketmq_access_key' => '',
            'sidecar_rocketmq_secret_key' => '',
            'sidecar_rocketmq_allowed_topics' => '*',
        ], $fields);
    }

    public function testTopicsListIsTrimmed(): void
    {
        $fields = SidecarRocketMqSpec::fields([
            'sidecar_name' => 'rocketmq',
            'sidecar_rocketmq_proxy_endpoint' => '127.0.0.1:8081',
            'sidecar_rocketmq_allowed_topics' => 'order, pay',
        ]);

        $this->assertSame('order,pay', $fields['sidecar_rocketmq_allowed_topics']);
    }

    public function testWildcardInsideTopicListFails(): void
    {
        $this->expectException(SidecarException::class);
        $this->expectExceptionMessage('sidecar_rocketmq_allowed_topics is invalid');
        SidecarRocketMqSpec::fields([
            'sidecar_name' => 'rocketmq',
            'sidecar_rocketmq_proxy_endpoint' => '127.0.0.1:8081',
            'sidecar_rocketmq_allowed_topics' => 'order,*',
        ]);
    }
}
