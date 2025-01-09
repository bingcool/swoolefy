<?php
namespace Test\Scripts\User;

use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Coroutine\Parallel;
use Test\Logger\RunLog;
use Swoolefy\Script\MainCliScript;

class FixedUser extends MainCliScript
{
    const command = 'fixed:user:name';

    public function init()
    {
        var_dump($this->getOption('name'));
        parent::init();
        Context::set('name', 'kkkkkkkkkkkkkkkkkkkkkkkk');
        Context::set('db_debug', true);
        goApp(function () {
            goApp(function () {
                goApp(function () {
                    $arrayCopy = \Swoolefy\Core\Coroutine\Context::getContext()->getArrayCopy();
                    sleep(2);
                    var_dump($arrayCopy);
                });
            });
        });
        var_dump('fixed:user:name');
        RunLog::info("FixedUser");
        //sleep(10);
//        goApp(function () {
//                $client = new \Common\Library\HttpClient\CurlHttpClient();
//                $response = $client->get('http://127.0.0.1:9501/test-curl');
//                var_dump($response->getDecodeBody());
//        });
        var_dump('curl next');
        goAfter(5000, function () {
            var_dump('sleep 5s name='.Context::get('name'));
        });
        sleep(20);
        date_default_timezone_set('Asia/Shanghai');
        file_put_contents(
            '/home/wwwroot/swoolefy/Test/WorkerCron/ForkOrder/order1.log',
            'date=' . date('Y-m-d H:i:s') . ',pid=' . getmypid() . "\n",
            FILE_APPEND
        );

        $this->test1(name: 'kkkkkkkkkkkkkkkkkkkkkkkk');
    }
    public function test1(string $name)
    {

    }

    public function handle()
    {
        return;
//        $db = App::getDb();
//        $db->newQuery()->table('tbl_users')->field('user_id,user_name')->chunk(10, function ($rows) {
//            foreach ($rows as $row) {
//                var_dump($row);
//            }
//        },'user_id');


//        $users = $db->newQuery()->table('tbl_users')->field('user_id,user_name')->cursor();
//        foreach ($users as $row) {
//            var_dump($row);
//            //\swoole\Coroutine::sleep(0.01);
//        }


//        $list = [
//            [
//                'name' => 1
//            ],
//            [
//                'name' => 2
//            ],
//            [
//                'name' => 3
//            ],
//            [
//                'name' => 4
//            ],
//            [
//                'name' => 5
//            ]
//        ];
//
//        Parallel::run(2, $list, function ($item) {
//            $db2 = App::getDb();
//            var_dump('cid='.\Swoole\Coroutine::getCid().'spl_object_id-22='.spl_object_id($db2));
//            var_dump($item['name']);
//            $result1 = $db2->newQuery()->table('tbl_users')->limit(1)->select()->toArray();
//        }, 0.01);


        $parallel = new Parallel();
        $parallel->add(function () {
            sleep(2);
            return "阿里巴巴";
        }, 'ali');

        $parallel->add(function () {
            sleep(2);
            return "腾讯";
        }, 'tengxu');

        $parallel->add(function () {
            sleep(2);
            return "百度";
        }, 'baidu');

        $parallel->add(function () {
            sleep(5);
            return "字节跳动";
        }, 'zijie');

        RunLog::info("this is script test log");

        //throw new \Exception("vvv");

        $result = $parallel->runWait(10);

//        $parallel = new Parallel(2);
//        foreach ($list as &$item) {
//            $parallel->add(function () use($item) {
//                return $item['name'].'-'.'name';
//            });
//        }
//        $result = $parallel->runWait();


        var_dump($result);

//        try {
//            $name = getenv('name');
//            var_dump("name=".$name);
//            //var_dump('Script test');
//            sleep(2);
//
        //$db1 = App::getDb();
        //var_dump('cid=' . \Swoole\Coroutine::getCid() . 'spl_object_id-11=' . spl_object_id($db1));
        //$result1 = $db1->newQuery()->table('tbl_users')->limit(1)->select()->toArray();
        //var_dump($result1);
//
//            goApp(function () {
//                $db2 = App::getDb();
//                var_dump('cid='.\Swoole\Coroutine::getCid().'spl_object_id-22='.spl_object_id($db2));
//                $result1 = $db2->newQuery()->table('tbl_users')->limit(1)->select()->toArray();
//            });
//
//            goApp(function () {
//                $db3 = App::getDb();
//                var_dump('cid='.\Swoole\Coroutine::getCid().'spl_object_id-33='.spl_object_id($db3));
//                $result1 = $db3->newQuery()->table('tbl_users')->limit(1)->select()->toArray();
//            });
//
//        }catch (\Throwable $exception) {
//            var_dump($exception->getMessage());
//        }
    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        parent::onHandleException($throwable, $context); // TODO: Change the autogenerated stub
        $this->exitAll();
    }

}