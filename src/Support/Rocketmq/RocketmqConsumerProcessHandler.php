<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;

/**
 * Swoole 自定义进程里的消费。一个进程拉一个消费组 + Topic。
 * 业务进程继承后实现 onMessage()，并写上 $topic、$consumerGroup。
 * onMessage() 抛异常时不确认，不可见时间结束后由 RocketMQ 再投。
 * 抛 RocketmqRetryLaterException 时不确认，按异常里的毫秒数重投；长任务里调用 extendInvisible() 续租。
 *
 * 一次最多拉 $maxNum 条，按顺序处理。每条开始前检查本批的不可见时间：
 * 距离到期不足 $ackSafetyMarginMs 时，本批剩下的消息不再处理、也不确认，等 RocketMQ 再投。
 * 否则 RocketMQ 已经把它重新投给别的消费者，这里再处理就会重复。
 * 单条处理慢时调大 $invisibleMs 或调小 $maxNum，保证 $maxNum × 单条耗时 < $invisibleMs - $ackSafetyMarginMs。
 */
abstract class RocketmqConsumerProcessHandler extends AbstractProcess
{
    /**
     * 要消费的 Topic，必填。
     */
    protected string $topic = '';

    /**
     * 消费组，必填。同一个消费组 + Topic 只能由一个进程拉取。
     */
    protected string $consumerGroup = '';

    /**
     * 要订阅的 Tag，例如 ['paid', 'cancel']，收到其中任意一个 Tag 的消息。
     * 包含 '*' 或为空数组时订阅全部。发给 sidecar 前php側转成 SDK 的表达式 "paid || cancel"。
     *
     * @var list<string>
     */
    protected array $consumerTag = ['*'];

    /**
     * SQL92 属性过滤，按生产端 properties 在 broker 上过滤，例如 eventType IN ('created','cancelled')。
     * 空字符串表示不用。非空时 $consumerTag 保持 ['*']（Tag 条件写进 SQL：TAGS IN ('paid')）。
     * 需要 broker 开启 enablePropertyFilter；消息上没有引用的属性时视为不匹配。
     * 同一消费组的所有实例必须用同一条件，条件不同就换消费组；修改后要重启进程。
     */
    protected string $consumerSql = '';

    /**
     * 一次最多拉取条数，1–32。
     */
    protected int $maxNum = 8;

    /**
     * 拉到后的不可见时间（毫秒），10000–300000。本批消息要在这段时间内处理完并 ack，否则会重投。
     */
    protected int $invisibleMs = 30000;

    /**
     * 本批不可见时间到期前预留的毫秒数，留给最后一条的 onMessage() 和 ack。
     */
    protected int $ackSafetyMarginMs = 2000;

    /**
     * 进程启动后第一次 receive 前等待的秒数，让 go-sidecar 先开始监听，避免启动时报 connect failed。
     * 默认 2 秒，最大 5 秒（超过按 5 秒），0 表示不等。
     */
    protected float $sidecarStartupWaitSeconds = 2.0;

    /**
     * 收到退出信号后置为 false，当前这条处理完就退出循环。
     */
    private bool $running = true;

    /**
     * 本进程的消费会话，run() 开始时创建。
     */
    private RocketmqConsumeSession $session;

    /**
     * 处理一条消息。正常返回就 ack；抛 RocketmqRetryLaterException 按指定时间重投；抛其他异常不 ack，交给 onHandleException()。
     */
    abstract public function onMessage(ReceivedMessageDto $message): void;

    /**
     * 进程收到退出信号时调用：停止拉取新批次，当前这条处理完后 run() 返回。
     */
    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
    }

    /**
     * 消费主循环：等 sidecar 就绪后反复 receive → 逐条 dispatch。receive 失败交给 onHandleException() 并退避 0.5 秒。
     */
    public function run()
    {
        $session = $this->openSession();
        $wait = RocketmqConsumeSession::sidecarStartupWaitSeconds($this->sidecarStartupWaitSeconds);
        if ($wait > 0) {
            \Swoole\Coroutine::sleep($wait);
        }
        while ($this->running) {
            try {
                $messages = $session->receive();
            } catch (\Throwable $exception) {
                $this->onHandleException($exception);
                \Swoole\Coroutine::sleep(0.5);
                continue;
            }
            if ($messages === []) {
                \Swoole\Coroutine::sleep(0.2);
                continue;
            }
            $deadlineNs = $session->handleDeadlineNs(hrtime(true), $this->ackSafetyMarginMs);
            foreach ($messages as $message) {
                if (!$this->running || hrtime(true) >= $deadlineNs) {
                    break;
                }
                $session->dispatch(
                    $message,
                    fn (ReceivedMessageDto $item) => $this->onMessage($item),
                    fn (\Throwable $exception) => $this->onHandleException($exception),
                );
            }
        }
    }

    /**
     * 在 onMessage() 里给当前消息续租：从现在起再隐藏 $invisibleMs 毫秒（10000–300000）。
     * 只延长这一条，本批其余消息的截止时间不变；长任务建议 $maxNum = 1。
     * RocketMQ 5.3.x 的 proxy 有缺陷（apache/rocketmq#9583）：续租后的 ack 可能不生效，见 RocketmqConsumeSession::extendCurrent()。
     *
     * @return bool 是否成功。失败已记日志，消息可能被重投
     */
    protected function extendInvisible(int $invisibleMs): bool
    {
        return isset($this->session) && $this->session->extendCurrent($invisibleMs);
    }

    /**
     * 按 Handler 字段创建消费会话。
     *
     * @throws SidecarException 没有填 $topic 或 $consumerGroup
     * @throws \InvalidArgumentException Tag 不合法，或 Tag 与 SQL 同时设置
     */
    private function openSession(): RocketmqConsumeSession
    {
        if ($this->topic === '' || $this->consumerGroup === '') {
            throw new SidecarException('rocketmq consumer topic and group are required');
        }

        return $this->session = new RocketmqConsumeSession(
            $this->topic,
            $this->consumerGroup,
            $this->consumerTag,
            $this->maxNum,
            $this->invisibleMs,
            $this->consumerSql,
        );
    }
}
