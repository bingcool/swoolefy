<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Memory;

use Swoole\Coroutine\Channel;

class ChannelManager {

    use \Swoolefy\Core\SingletonTrait;

    private $lists = [];

    /**
     * 创建一个数据Channel
     * @param  string  $name
     * @param  int  $capacity
     * @return $this
     * @throws mixed
     */
    public function addChannel(string $name, int $capacity = null) {
        if(!isset($this->lists[$name])) {
            if($capacity) {
                $channel = new Channel($capacity);
            }else {
                $channel = new Channel();
            }
            $this->lists[$name] = $channel;
        }
        return $this;
    }

    /**
     * getChannel
     * @param  string $name
     * @return Channel
     */
    public function getChannel(string $name) {
        return $this->lists[$name] ?? null;
    }
}