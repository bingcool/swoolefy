<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Rocketmq;

use PHPUintTest\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoolefy\Support\Rocketmq\Dto\HealthCheckResponseDto;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;
use Swoolefy\Support\Rocketmq\Dto\ReceiveMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\SendMessageRequestDto;

final class RocketmqDtoTest extends TestCase
{
    public function testSendWithoutNewFieldsKeepsBaseShape(): void
    {
        $request = new SendMessageRequestDto();
        $request->setTopic('order');
        $request->setBodyBase64('aGk=');
        $request->setTag('paid');

        $this->assertSame(
            '{"topic":"order","bodyBase64":"aGk=","tag":"paid","delayMs":0}',
            json_encode($request->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function testReceiveRequestOmitsEmptySql(): void
    {
        $request = new ReceiveMessageRequestDto();
        $request->setTopic('order');
        $request->setConsumerGroup('g');
        $this->assertSame(
            '{"topic":"order","consumerGroup":"g","consumerTag":"*","maxNum":2,"invisibleMs":30000}',
            json_encode($request->toArray(), JSON_THROW_ON_ERROR),
        );

        $request->setConsumerSql("  eventType = 'created' ");
        $this->assertSame("eventType = 'created'", $request->toArray()['consumerSql']);
        $request->setConsumerSql('   ');
        $this->assertArrayNotHasKey('consumerSql', $request->toArray());
    }

    public function testSendSerializesNewFieldsOnlyWhenSet(): void
    {
        $request = new SendMessageRequestDto();
        $request->setTopic('order');
        $request->setBodyBase64('aGk=');
        $request->setKeys(['ORDER_1', 'USER_2', 'ORDER_1', '123']);
        $request->setProperties(['traceId' => 't-1', '42' => 'n']);
        $request->setDeliverAtMs(1792049100000);

        $data = json_decode(json_encode($request->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['ORDER_1', 'USER_2', '123'], $data['keys']);
        $this->assertSame(['traceId' => 't-1', '42' => 'n'], $data['properties']);
        $this->assertSame(1792049100000, $data['deliverAtMs']);
        $this->assertArrayNotHasKey('messageGroup', $data);
        $this->assertArrayNotHasKey('key', $data);
        $this->assertStringContainsString('"properties":{', json_encode($request->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array{callable(SendMessageRequestDto): void}>
     */
    public static function invalidSendProvider(): array
    {
        return [
            'too many keys' => [static fn (SendMessageRequestDto $r) => $r->setKeys(array_map(static fn (int $i): string => 'k' . $i, range(0, 32)))],
            'empty key' => [static fn (SendMessageRequestDto $r) => $r->setKeys([''])],
            'key with space' => [static fn (SendMessageRequestDto $r) => $r->setKeys(['a b'])],
            'key too long' => [static fn (SendMessageRequestDto $r) => $r->setKeys([str_repeat('k', 129)])],
            'property list' => [static fn (SendMessageRequestDto $r) => $r->setProperties(['a', 'b'])],
            'property key illegal' => [static fn (SendMessageRequestDto $r) => $r->setProperties(['a/b' => 'v'])],
            'property key system' => [static fn (SendMessageRequestDto $r) => $r->setProperties(['__TAG' => 'v'])],
            'property value not string' => [static fn (SendMessageRequestDto $r) => $r->setProperties(['a' => 1])],
            'property value too long' => [static fn (SendMessageRequestDto $r) => $r->setProperties(['a' => str_repeat('v', 1025)])],
            'negative delay' => [static fn (SendMessageRequestDto $r) => $r->setDelayMs(-1)],
            'deliverAt in seconds' => [static fn (SendMessageRequestDto $r) => $r->setDeliverAtMs(1792049100)],
            'group with space' => [static fn (SendMessageRequestDto $r) => $r->setMessageGroup('a b')],
            'group too long' => [static fn (SendMessageRequestDto $r) => $r->setMessageGroup(str_repeat('g', 65))],
        ];
    }

    #[DataProvider('invalidSendProvider')]
    public function testSendSetterRejectsInvalidValue(callable $mutate): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $mutate(new SendMessageRequestDto());
    }

    public function testDelayAndDeliverAtAreExclusive(): void
    {
        $request = new SendMessageRequestDto();
        $request->setDelayMs(1000);
        $request->setDeliverAtMs(1792049100000);
        $this->expectException(\InvalidArgumentException::class);
        $request->validate();
    }

    public function testMessageGroupCannotBeDelayed(): void
    {
        $request = new SendMessageRequestDto();
        $request->setMessageGroup('ORDER_1');
        $request->setDelayMs(1000);
        $this->expectException(\InvalidArgumentException::class);
        $request->validate();
    }

    public function testReceivedMessageReadsMetadata(): void
    {
        $message = ReceivedMessageDto::fromArray([
            'messageId' => 'm1',
            'topic' => 'order',
            'bodyBase64' => 'aGk=',
            'tag' => 'paid',
            'keys' => ['ORDER_1', 'a,b'],
            'properties' => ['traceId' => 't-1'],
            'messageGroup' => 'ORDER_1',
            'bornTimestampMs' => 1792049000000,
            'deliveryTimestampMs' => 1792049100000,
            'receiptHandle' => 'rh',
            'deliveryAttempt' => 2,
        ]);

        $this->assertSame(['ORDER_1', 'a,b'], $message->getKeys());
        $this->assertSame('t-1', $message->getProperty('traceId'));
        $this->assertNull($message->getProperty('missing'));
        $this->assertSame('ORDER_1', $message->getMessageGroup());
        $this->assertSame(1792049000000, $message->getBornTimestampMs());
        $this->assertSame(1792049100000, $message->getDeliveryTimestampMs());
        $this->assertSame(2, $message->getDeliveryAttempt());
    }

    public function testReceivedMessageDefaultsWhenFieldsAbsent(): void
    {
        $message = ReceivedMessageDto::fromArray([
            'messageId' => 'm1',
            'topic' => 'order',
            'bodyBase64' => 'aGk=',
            'receiptHandle' => 'rh',
            'deliveryAttempt' => 1,
        ]);

        $this->assertSame([], $message->getKeys());
        $this->assertSame([], $message->getProperties());
        $this->assertSame('', $message->getMessageGroup());
        $this->assertSame(0, $message->getBornTimestampMs());
        $this->assertSame(0, $message->getDeliveryTimestampMs());
    }

    public function testHealthCapabilities(): void
    {
        $old = HealthCheckResponseDto::fromArray(['version' => '1.0.0']);
        $this->assertFalse($old->hasCapabilities());
        $this->assertSame([], $old->getCapabilities());

        $new = HealthCheckResponseDto::fromArray(['version' => '1.0.1', 'capabilities' => ['send.keys', 'consumer.changeInvisible']]);
        $this->assertTrue($new->hasCapabilities());
        $this->assertContains('consumer.changeInvisible', $new->getCapabilities());
    }
}
