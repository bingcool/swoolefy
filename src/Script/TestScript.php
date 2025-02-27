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

    public function handle()
    {
        file_put_contents(START_DIR_ROOT.'/test.log', date('Y-m-d H:i:s').PHP_EOL, FILE_APPEND);
        echo "this is a test script\n";
    }
}