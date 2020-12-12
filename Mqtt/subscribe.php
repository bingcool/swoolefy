<?php
/**
 * This file is part of Simps
 *
 * @link     https://github.com/simps/mqtt
 * @contact  Lu Fei <lufei@simps.io>
 *
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code
 */

include __DIR__ . '/../vendor/autoload.php';

use Swoole\Coroutine;
use Simps\MQTT\Client;

$config = [
    'host' => '127.0.0.1',
    'port' => 1883,
    'time_out' => 5,
    'user_name' => 'user001',
    'password' => 'hLXQ9ubnZGzkzf',
    'client_id' => 'd812edc1-18da-2085-0edf-a4a588c296d1'.rand(1,1000),
    'keep_alive' => 20,
];

Coroutine\run(function () use ($config) {
    $client = new Client($config, ['open_mqtt_protocol' => true, 'package_max_length' => 2 * 1024 * 1024]);
    $will = [
        'topic' => 'simps-mqtt/user001/update',
        'qos' => 1,
        'retain' => 0,
        'message' => '' . time(),
    ];
    while (! $client->connect(false, $will)) {
        \Swoole\Coroutine::sleep(3);
        $client->connect(true, $will);
    }
    $topics['simps-mqtt/user001/get'] = 0;
    $topics['simps-mqtt/user001/update'] = 1;
    $timeSincePing = time();
    $client->subscribe($topics);
    while (true) {
        $buffer = $client->recv();
        var_dump($buffer);
        if ($buffer && $buffer !== true) {
            $timeSincePing = time();
        }
        if (isset($config['keep_alive']) && $timeSincePing < (time() - $config['keep_alive'])) {
            $buffer = $client->ping();
            if ($buffer) {
                echo 'send ping success' . PHP_EOL;
                $timeSincePing = time();
            } else {
                $client->close();
                break;
            }
        }
    }
});
