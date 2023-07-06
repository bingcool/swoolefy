<?php
namespace Test\Process\TickProcess;

use Common\Library\Db\Facade\Db;
use Common\Library\Db\Mysql;
use Common\Library\Db\Query;
use Swoole\Process;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Timer\TickManager;
use Test\Module\Order\OrderEntity;


class Tick extends AbstractProcess {

    public $swooleProcessHander;

    public function run() {
        // 协议层配置
        // $conf = Swfy::getConf();
//        go(function() {
//            $cid = \Swoole\Coroutine::getCid();
//        });
        var_dump('This is process tick, class='.__CLASS__);
        // 创建定时器处理实例
//        TickManager::getInstance()->tickTimer(3000,
//            [TickController::class, 'tickTest'],
//            ['name'=>'swoolefy-tick']
//        );

//        TickManager::getInstance()->tickTimer(3000, function () {
//            $count = Application::getApp()->get('db')->createCommand("select count(1) as total from tbl_users")->count();
//            var_dump($count);
//        });
        /**
         * @var Mysql $db;
         */
        $db = Application::getApp()->get('db');
        while(1) {
            try {

                $data = [
                    'user_name' => '李四ffffff-'.rand(1,9999),
                    'sex' => 0,
                    'birthday' => '1991-07-08',
                    'phone' => 12345678,
                    'gmt_create' =>date('Y-m-d H:i:s'),
                ];

                $query = new \Common\Library\Db\Query($db->getConnection());

                // 插入
               // $query->table('tbl_users')->save($data);

                // 更新
               // $query->table('tbl_users')->where(['user_id' => 615])->save($data);

                // 批量插入
                //$query->table('tbl_users')->insertAll([$data]);

                //$query->table('tbl_users')->insertAll([$data]);

                //$query->table('tbl_users')->where(['user_id' => 615])->delete();

//                $list = $query->table('tbl_order')->select();
//                var_dump($list);

//                $sql1 = $query->table('tbl_users a')
//                    ->field([
//                        'a.user_id',
//                        'a.user_name as name'
//                    ])
//                    ->rightJoin('tbl_order as b','a.user_id=b.user_id')
//                    ->page(1,3)->order('a.user_id', 'desc')
//                    ->buildSql();
//
//                $sql2 = $query->newQuery()->table($sql1)->alias('ab')
//                    ->leftJoin('tbl_order as bc','ab.user_id=bc.user_id')
//                    ->select();
//
//                var_dump($sql2);


               // $this->testOrm($data);


//                $order = (new OrderEntity(10000))->newQuery()->table('tbl_users')->count();
//
//                var_dump($order);
//
//
//                $count = $query->newQuery()->table('tbl_users')->count();
//
//                var_dump($count);
//
//                $count = Db::connect('db')->table('tbl_users')->count();
//
//                var_dump($count);
//
//                $count = Db::connect('db')->query('select count(1) as total from tbl_order');
//                var_dump($count);

                $result = $query->newQuery()->table('tbl_order')->where('order_id',1687344503)->field(['user_id' => 'id'])->cursor();

                foreach ($result as $item) {
                    var_dump($item);
                    break;
                }

//                $order = OrderEntity::where('order_id', '=', 1687344503)->find();
//                var_dump($order);

            }catch (\Throwable $exception) {
                var_dump($exception->getMessage(), $exception->getTraceAsString());
            }
            sleep(5);
        }

        // 创建定时器处理实例
//        TickManager::getInstance()->afterTimer(3000,
//            [TickController::class, 'tickTest'],
//            ['name'=>'swoolefy-tick']
//        );

    }


    protected function testOrm($data)
    {
        $dc = [
            'default'    =>    'mysql',
            'connections'    =>    [
                'mysql'    =>    [
                    'type' => 'mysql',
                    // 服务器地址
                    'hostname'        => '127.0.0.1',
                    // 数据库名
                    'database'        => 'bingcool',
                    // 用户名
                    'username'        => 'root',
                    // 密码
                    'password'        => '123456',
                    // 端口
                    'hostport'        => '3306',
                    // 连接dsn
                    'dsn'             => '',
                    // 数据库连接参数
                    'params'          => [],
                    // 数据库编码默认采用utf8
                    'charset'         => 'utf8mb4',
                    // 数据库表前缀
                    'prefix'          => '',
                    // fetchType
                    'fetch_type' => \PDO::FETCH_ASSOC,
                    // 是否需要断线重连
                    'break_reconnect' => true,
                    // 是否支持事务嵌套
                    'support_savepoint' => false,
                    // sql执行日志条目设置,不能设置太大,适合调试使用,设置为0，则不使用
                    'spend_log_limit' => 30,
                    // 是否开启dubug
                    'debug' => 1
                ],
            ]
        ];

        $manager = new \think\DbManager();
        $manager->setConfig($dc);
        $mysql = $manager->connect('mysql');
        $query1 = new \think\db\Query($mysql);

        //$id = $query1->table('tbl_users')->insert($data, true);

        $result = $query1->table('tbl_users as a')
            ->rightJoin('tbl_order b','a.user_id=b.user_id')
            ->page(1,5)->order('a.user_id', 'desc')
            ->fetchSql()
            ->select();

        var_dump($result);


    }

    public function onReceive($str, ...$args)
    {

    }

    public function onShutDown() {}

    public function __get($name) {
        return Application::getApp()->$name;
    }
}