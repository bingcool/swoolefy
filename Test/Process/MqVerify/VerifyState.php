<?php

declare(strict_types=1);

namespace Test\Process\MqVerify;

/**
 * 真实环境验证用的状态文件。每个场景一个 jsonl 事件文件，进程被 kill 重启后接着写。
 * 目录：/tmp/mq-verify/{run}/，run 由 Event::onInit() 每次启动生成一次。
 */
final class VerifyState
{
    public const ROOT = '/tmp/mq-verify';

    public static function dir(string $run): string
    {
        $dir = self::ROOT . '/' . $run;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir;
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function record(string $run, string $scenario, array $event): void
    {
        $event['t'] = round(microtime(true), 3);
        $event['pid'] = getmypid();
        file_put_contents(
            self::dir($run) . '/' . $scenario . '.jsonl',
            json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function events(string $run, string $scenario): array
    {
        $file = self::dir($run) . '/' . $scenario . '.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $events = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * 只有第一次调用返回 true。用来保证“只生产一次”“只 kill 一次”跨进程重启成立。
     */
    public static function once(string $run, string $scenario, string $flag): bool
    {
        $path = self::dir($run) . '/' . $scenario . '.' . $flag;
        if (file_exists($path)) {
            return false;
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return false;
        }
        fclose($handle);

        return true;
    }

    /**
     * daemon 模式下 stdout 不一定可见，同时写一份到 /tmp/mq-verify/verify.log。
     */
    public static function log(string $message): void
    {
        $line = '[MQ-VERIFY] ' . date('H:i:s') . ' ' . $message . PHP_EOL;
        echo $line;
        if (!is_dir(self::ROOT)) {
            @mkdir(self::ROOT, 0777, true);
        }
        file_put_contents(self::ROOT . '/verify.log', $line, FILE_APPEND | LOCK_EX);
    }
}
