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

namespace Swoolefy\Script;

class TestScript extends MainCliScript {
    /**
     * @var string
     */
    const command = "test:script";

    public function handle() {
        file_put_contents(APP_PATH.'/test1.txt', date('Y-m-d H:i:s'));
        echo "this is a test script\n";
    }
}