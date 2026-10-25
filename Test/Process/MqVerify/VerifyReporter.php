<?php

declare(strict_types=1);

namespace Test\Process\MqVerify;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Kafka\KafkaBrokers;

/**
 * 每 15 秒按 VerifyScenarios::evaluate() 判定一次，全部 PASS 或到 FINAL_AT 秒时输出最终结果。
 * Kafka 场景额外查询消费组在 partition 0 上已提交的 offset，期望是 3。
 */
class VerifyReporter extends AbstractProcess
{
    private const FIRST_AT = 45;

    private const INTERVAL = 15;

    private const FINAL_AT = 180;

    public function run()
    {
        $run = (string) $this->getArgs()['run'];
        $started = time();
        VerifyState::log('run=' . $run . ' scenarios=' . count(VerifyScenarios::all()) . ' state=' . VerifyState::dir($run));
        \Swoole\Coroutine::sleep(self::FIRST_AT);
        while (true) {
            $elapsed = time() - $started;
            $final = $elapsed >= self::FINAL_AT;
            $results = [];
            foreach (VerifyScenarios::all() as $scenario) {
                $committed = $scenario['broker'] === 'kafka'
                    ? $this->kafkaCommitted(VerifyScenarios::kafkaTopic($run, $scenario['name']))
                    : null;
                $results[$scenario['name']] = VerifyScenarios::evaluate(
                    $scenario,
                    VerifyState::events($run, $scenario['name']),
                    $committed,
                );
            }
            $passed = count(array_filter($results, static fn ($r) => $r[0]));
            $total = count($results);
            if ($passed === $total || $final) {
                VerifyState::log(sprintf('==================== RESULT run=%s elapsed=%ds pass=%d/%d', $run, $elapsed, $passed, $total));
                foreach ($results as $name => [$ok, $detail]) {
                    VerifyState::log(sprintf('%-4s %-38s %s', $ok ? 'PASS' : 'FAIL', $name, $detail));
                }
                VerifyState::log('==================== END');
                // 自定义进程退出会被 Manager 重新拉起，这里停住，避免重复输出
                while (true) {
                    \Swoole\Coroutine::sleep(3600);
                }
            }
            VerifyState::log(sprintf('progress elapsed=%ds pass=%d/%d waiting: %s', $elapsed, $passed, $total,
                implode(',', array_keys(array_filter($results, static fn ($r) => !$r[0])))));
            \Swoole\Coroutine::sleep(self::INTERVAL);
        }
    }

    private function kafkaCommitted(string $topic): ?int
    {
        try {
            $conf = new \RdKafka\Conf();
            $conf->set('metadata.broker.list', KafkaBrokers::resolve());
            $conf->set('group.id', $topic);
            $conf->set('enable.auto.commit', 'false');
            $consumer = new \RdKafka\KafkaConsumer($conf);
            $partitions = $consumer->getCommittedOffsets([new \RdKafka\TopicPartition($topic, 0)], 5000);
            $consumer->close();

            return (int) $partitions[0]->getOffset();
        } catch (\Throwable $e) {
            VerifyState::log('kafka committed query failed topic=' . $topic . ': ' . $e->getMessage());
            return null;
        }
    }
}
