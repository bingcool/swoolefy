<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

/**
 * FIFO 命名管道的客户端封装。
 *
 * 职责：创建响应管道、通过 Swoole Event 异步监听 Worker 回复、超时后自动退出。
 * 典型使用场景：SendCmd、StatusCmd 向 Worker 发送消息后等待响应。
 *
 * ===== 时序严格约束，绝对不能乱 =====
 *
 * 必须严格按照以下三步顺序执行，任何调换都会导致通信失败：
 *
 *   Step 1: prepareResponsePipe()  → 创建响应 FIFO + 注册 Event 监听（非阻塞，立即返回）
 *   Step 2: fwrite(发送消息)        → 通过 CLI→Worker 管道发送请求给 Worker
 *   Step 3: waitForResponse()      → 进入 Event::wait() 阻塞等待 Worker 的响应
 *
 * 为什么不能先发信息再 prepare？
 *   Worker 收到消息后立即处理并尝试写入响应 FIFO，若此时 FIFO 尚未创建，
 *   Worker 的 fopen 会失败或阻塞，响应永远无法送达，CLI 端将无输出。
 *
 * 为什么不能把 prepare + wait 合并成一个方法？
 *   Event::wait() 是阻塞调用，一旦进入就无法返回。如果 prepare 和 wait
 *   在同一个方法中，fwrite 将被安排在 wait 之后执行，Worker 永远收不到请求。
 */
final class FifoPipeClient
{
    /** @var resource|null 响应管道文件句柄 */
    private $pipe = null;

    /** @var string 响应 FIFO 管道路径 */
    private string $responsePipe;

    /**
     * Step 1：创建响应管道并注册事件监听（非阻塞，立即返回）。
     *
     * !! 必须在发送消息给 Worker 之前调用 !!
     * 确保 Worker 处理完消息后写入响应时，FIFO 已就绪且有 reader 在监听。
     * 若调用顺序颠倒（先发消息再 prepare），响应将丢失，CLI 无输出。
     *
     * @param string   $responsePipe  响应 FIFO 管道文件路径
     * @param int      $timeoutMs     超时毫秒数（超过后自动退出事件循环）
     * @param callable $onMessage     收到消息回调 fn(string $msg): void
     * @return self                   返回实例，用于后续调用 waitForResponse()
     */
    public static function prepareResponsePipe(string $responsePipe, int $timeoutMs, callable $onMessage): self
    {
        $instance = new self();
        $instance->responsePipe = $responsePipe;

        // 清理旧管道文件，避免残留
        if (file_exists($responsePipe)) {
            unlink($responsePipe);
        }

        // 创建 FIFO 命名管道
        posix_mkfifo($responsePipe, 0777);

        // 以非阻塞模式打开管道
        $ctlPipe = fopen($responsePipe, 'w+');
        stream_set_blocking($ctlPipe, false);
        $instance->pipe = $ctlPipe;

        // 超时定时器：超时后退出事件循环
        \Swoole\Timer::after($timeoutMs, function () {
            \Swoole\Event::exit();
        });

        // 监听管道可读事件
        \Swoole\Event::add($ctlPipe, function () use ($ctlPipe, $onMessage) {
            $msg = fread($ctlPipe, 8192);
            $onMessage($msg ?: '');
            \Swoole\Event::exit();
        });

        return $instance;
    }

    /**
     * Step 3：阻塞等待 Worker 响应（进入事件循环）。
     *
     * !! 必须在 prepareResponsePipe() 和 fwrite 发送消息之后调用 !!
     * Event::wait() 是阻塞调用，一旦进入就无法返回，后续代码不会执行。
     * 事件循环会在收到响应或超时后自动退出。
     */
    public function waitForResponse(): void
    {
        // 阻塞等待事件循环退出
        \Swoole\Event::wait();

        // 清理资源
        if ($this->pipe !== null) {
            fclose($this->pipe);
            $this->pipe = null;
        }
        @unlink($this->responsePipe);
    }
}
