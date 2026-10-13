<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Kafka;

use PHPUintTest\TestCase;
use Swoolefy\Support\Kafka\KafkaCommitMode;
use Swoolefy\Support\Kafka\KafkaException;

final class KafkaCommitModeTest extends TestCase
{
    public function testKnownModesPass(): void
    {
        KafkaCommitMode::assertValid(KafkaCommitMode::AFTER_HANDLED);
        KafkaCommitMode::assertValid(KafkaCommitMode::AFTER_HANDLED_ASYNC);
        KafkaCommitMode::assertValid(KafkaCommitMode::BEFORE_HANDLE);
        $this->addToAssertionCount(3);
    }

    public function testUnknownModeFails(): void
    {
        $this->expectException(KafkaException::class);
        $this->expectExceptionMessage('kafka commit mode is invalid: auto');
        KafkaCommitMode::assertValid('auto');
    }
}
