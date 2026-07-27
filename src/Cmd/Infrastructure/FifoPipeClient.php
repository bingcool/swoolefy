<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

/**
 * FIFO 命名管道的客户端封装。
 *
 * 职责：创建响应管道、通过 Swoole Event 异步监听 Worker 回复、超时后自动退出。
 * 典型使用场景：SendCmd、StatusCmd 向 Worker 发送消息后等待响应。
 *
 * 工作流程：
 * 1. 创建 FIFO 响应管道（posix_mkfifo）
 * 2. 使用 Swoole\Event::add 非阻塞监听管道数据
 * 3. 收到消息或超时后自动清理管道并退出事件循环
 */
final class FifoPipeClient
{
    /**
     * 创建响应管道，监听 Worker 回复，超时后自动退出。
     *
     * 使用 Swoole 事件循环实现异步监听，不会阻塞主进程。
     * 回调函数接收到 Worker 写入的响应消息后，事件循环自动退出。
     *
     * @param string   $responsePipe  响应 FIFO 管道文件路径
     * @param int      $timeoutMs     超时毫秒数（超过后自动退出）
     * @param callable $onMessage     收到消息回调 fn(string $msg): void
     */
    public static function listenResponse(string $responsePipe, int $timeoutMs, callable $onMessage): void
    {
        // 清理旧管道文件，避免残留
        if (file_exists($responsePipe)) {
            unlink($responsePipe);
        }

        // 创建 FIFO 命名管道
        posix_mkfifo($responsePipe, 0777);

        // 以非阻塞模式打开管道
        $ctlPipe = fopen($responsePipe, 'w+');
        stream_set_blocking($ctlPipe, false);

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

        // 阻塞等待事件循环退出
        \Swoole\Event::wait();

        // 清理资源
        fclose($ctlPipe);
        @unlink($responsePipe);
    }
}
