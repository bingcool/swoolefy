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

class UdpPacket
{
    /**
     * @var string
     */
    private string $raw;

    /**
     * @var string
     */
    private string $endpoint;

    /**
     * @var array
     */
    private array $params;

    /**
     * @var array{address?:string,port?:int,server_socket?:int}
     */
    private array $clientInfo;

    /**
     * @param string $raw
     * @param string $endpoint
     * @param array $params
     * @param array $clientInfo
     */
    public function __construct(string $raw, string $endpoint, array $params, array $clientInfo)
    {
        $this->raw = $raw;
        $this->endpoint = $endpoint;
        $this->params = $params;
        $this->clientInfo = $clientInfo;
    }

    /**
     * @param string $raw
     * @param array $clientInfo
     * @param string $delimiter
     * @return static
     */
    public static function parse(string $raw, array $clientInfo, string $delimiter = SWOOLEFY_EOF_FLAG): self
    {
        $dataGramItems = explode($delimiter, $raw, 2);
        if (count($dataGramItems) === 2) {
            [$endpoint, $params] = $dataGramItems;
            if (is_string($params)) {
                $decoded = json_decode($params, true);
                if (!is_array($decoded)) {
                    throw new \InvalidArgumentException('Udp params must be valid json string');
                }
                $params = $decoded;
            } elseif (!is_array($params)) {
                throw new \InvalidArgumentException('Udp params must be array');
            }
        } elseif (count($dataGramItems) === 1) {
            $endpoint = (string) current($dataGramItems);
            $params = [];
        } else {
            throw new \InvalidArgumentException('Udp payload parse error');
        }

        $endpoint = trim(str_replace('\\', DIRECTORY_SEPARATOR, $endpoint), DIRECTORY_SEPARATOR);
        if ($endpoint === '') {
            throw new \InvalidArgumentException('Udp endpoint is required');
        }

        return new self($raw, $endpoint, $params, $clientInfo);
    }

    public function getRaw(): string
    {
        return $this->raw;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getClientInfo(): array
    {
        return $this->clientInfo;
    }

    public function getAddress(): string
    {
        return (string) ($this->clientInfo['address'] ?? '');
    }

    public function getPort(): int
    {
        return (int) ($this->clientInfo['port'] ?? 0);
    }

    public function getServerSocket(): int
    {
        return (int) ($this->clientInfo['server_socket'] ?? -1);
    }
}
