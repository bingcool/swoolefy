<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Udp;

use Swoole\Coroutine\Client;
use Swoolefy\Exception\SystemException;

class UdpClient
{
    /**
     * @var string
     */
    private string $host;

    /**
     * @var int
     */
    private int $port;

    /**
     * @var float
     */
    private float $timeout;

    /**
     * @param string $host
     * @param int $port
     * @param float $timeout seconds
     */
    public function __construct(string $host, int $port, float $timeout = 3.0)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    /**
     * @param string $endpoint
     * @param array $params
     * @return array
     */
    public function request(string $endpoint, array $params = []): array
    {
        $client = new Client(SWOOLE_SOCK_UDP);
        $client->set([
            'open_length_check' => false,
            'timeout' => $this->timeout,
        ]);

        if (!$client->connect($this->host, $this->port)) {
            throw new SystemException(sprintf(
                'UdpClient connect failed: %s',
                $client->errMsg ?: (string) $client->errCode
            ));
        }

        $message = implode(SWOOLEFY_EOF_FLAG, [
            $endpoint,
            json_encode($params, JSON_UNESCAPED_UNICODE),
        ]);

        if ($client->send($message) === false) {
            $client->close();
            throw new SystemException('UdpClient send failed');
        }

        $response = $client->recv();
        $client->close();

        if ($response === false || $response === '') {
            throw new SystemException('UdpClient recv timeout or empty response');
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new SystemException('UdpClient invalid json response: ' . $response);
        }

        return $decoded;
    }
}
