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

namespace Swoolefy\Rpc;

class BaseParse
{

    /**
     * json
     */
    const DECODE_JSON = 1;

    /**
     * php serialize
     */
    const DECODE_PHP = 2;

    /**
     * 定义序列化的方式
     */
    const SERIALIZE_TYPE = [
        'json' => 1,
        'serialize' => 2,
    ];

    /**
     * error header
     */
    const ERR_HEADER = 9001;

    /**
     * pack body too big
     */
    const ERR_TOOBIG = 9002;

    /**
     * server busy
     */
    const ERR_SERVER_BUSY = 9003;

    /**
     * parse error
     */
    const ERR_PARSE_BODY = 9004;

    /**
     * $server
     * @var null
     */
    protected $server = null;

    /**
     * __construct
     * @param \Swoole\Server $server
     */
    public function __construct(\Swoole\Server $server)
    {
        $this->server = $server;
    }
}
