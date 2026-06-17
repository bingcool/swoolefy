<?php
namespace Test\Process\UdpTestProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Udp\UdpClient;

class Udp extends AbstractProcess
{
    public function run()
    {
        goTick(5000, function () {
            $client = new UdpClient('127.0.0.1', 9507, 3.0);

            try {
                $ping = $client->request('Service/Demo/Ping', []);
                echo '[UDP Ping] ' . json_encode($ping, JSON_UNESCAPED_UNICODE) . PHP_EOL;

                $report = $client->request('Service/Demo/ReportMsg', [
                    'msg' => '[' . date('Y-m-d H:i:s') . '] Hello UDP Server!',
                ]);
                echo '[UDP ReportMsg] ' . json_encode($report, JSON_UNESCAPED_UNICODE) . PHP_EOL;

                $invalid = $client->request('Service/Not/Exists', []);
                echo '[UDP Invalid] ' . json_encode($invalid, JSON_UNESCAPED_UNICODE) . PHP_EOL;
            } catch (\Throwable $throwable) {
                echo '[UDP Error] ' . $throwable->getMessage() . PHP_EOL;
            }
        });
    }
}
