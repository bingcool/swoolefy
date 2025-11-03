<?php
namespace Test\Process\UdpTestProcess;

use Swoolefy\Core\Process\AbstractProcess;
class Udp extends AbstractProcess {
    public function run()
    {
        goTick(2000, function() {
            // UDP服务器地址和端口
            $serverIp = '127.0.0.1';
            $serverPort = 9503;

            // 要发送的数据,指定endPoint
            $endPoint = "Service/Demo/ReportMsg";

            $data = ["msg" => "[" .date('Y-m-d H:i:s')."]"." Hello UDP Server!"];

            $message = join('::', [$endPoint, json_encode($data, JSON_UNESCAPED_UNICODE)]);

            // 创建UDP套接字
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (!$socket) {
                die("Socket创建失败: " . socket_strerror(socket_last_error()));
            }

            // 发送数据到服务器（无需绑定本地端口）
            socket_sendto($socket, $message, strlen($message), 0, $serverIp, $serverPort);
            echo "已发送数据: $message\n";

            // 接收服务器响应（可选）
            $buffer = '';
            socket_recvfrom($socket, $buffer, 1024, 0, $fromIp, $fromPort);
            echo "收到服务器响应: $buffer\n";

            // 关闭套接字
            socket_close($socket);
        });

    }
}