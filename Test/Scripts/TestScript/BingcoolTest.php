<?php
namespace Test\Scripts\TestScript;

use Swoolefy\Exception\SystemException;
use Swoolefy\Script\MainCliScript;

/**
 * use this command:
 *           php script.php start Test --c=bingcool:test --a=testOrder
 */
class BingcoolTest extends MainCliScript
{
    const command = 'bingcool:test';
    public function handle()
    {
        // 获取测试脚本类需要执行的方法
        $a = $this->getOption('a');
        if (!method_exists($this, $a)) {
            throw new SystemException('method '.$a.' not exists in class='.get_class($this));
        }
        $this->$a();
    }

    /**
     * testOrder 测试方法
     */
    public function testOrder()
    {
        echo "test order\n";
    }
}