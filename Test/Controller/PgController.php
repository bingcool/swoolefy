<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Library\Db\Sql;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Test\App;
use Test\Logger\RunLog;
use Test\Module\Order\OrderEntity;
use Test\Module\Order\OrderFormatter;

class PgController extends BController
{
    /**
     * @Api("测试 PostgreSQL 插入订单并触发 CurlProxy 请求")
     *
     * curl 测试：
     * curl "http://127.0.0.1:9501/user/user-order/save-pg-order"
     */
    #[ApiOperation(description: '测试 PostgreSQL 插入订单并触发 CurlProxy 请求')]
    public function savePgOrder(): array
    {
        $userId = 10000;
        /**
         * @var \Swoolefy\Library\Db\Pgsql $pg
         */

        $userId = 10000;
        $receiver_user_name = '李四';
        $receiver_user_phone = '12344556';
        $order_amount =100;
        $address = "广东省深圳xxxxxx";
        $order_product_ids = [1222,345,567,rand(1,1000)];
        $json_data = ['name'=>'xiaomi', 'phone'=>123456789];
        $order_status = 1;
        $remark = 'test-remark-'.rand(1,1000);

        $pg = Application::getApp()->get('pg');
        $query = $pg->newQuery();
        $query->table('tbl_order')->insert([
            'order_id' => time(),
            'user_id' => 10000,
            'receiver_user_name' => $receiver_user_name,
            'receiver_user_phone' => $receiver_user_phone,
            'order_amount' => $order_amount,
            'address' => $address,
            'order_product_ids' => json_encode($order_product_ids),
            'json_data' => json_encode($json_data),
            'order_status' => $order_status,
            'remark' => $remark,
            'expend_data' => '{"name":"xiaomi","phone":123456789}',
        ]);
        $id = $query->getLastInsID();

        $client = new \GuzzleHttp\Client([
            'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(), // 只需把handler注入进来即可
            'base_uri' => "http://127.0.0.1:9501",
        ]);
        $response = $client->get('/api/send-task-worker');
        $result = $response->getBody()->getContents();
        $result = json_decode($result, true);
        var_dump($result);

        return ['id' => $id];

    }

    /**
     * @Api("测试 OrderPgEntity 保存 PostgreSQL 订单")
     *
     * curl 测试：
     * curl "http://127.0.0.1:9501/user/user-order/save-pg-order1"
     */
    #[ApiOperation(description: '测试 OrderPgEntity 保存 PostgreSQL 订单')]
    public function savePgOrder1(): array
    {
        $userId = 10000;

        $struct = new \Swoolefy\Core\Struct();
        $struct->set('name', 'bingcool');

        $orderObject = new \Test\Module\Order\OrderPgEntity($userId);
        $orderObject->user_id = $userId;
        $orderObject->receiver_user_name = "张三";
        $orderObject->receiver_user_phone = "12344556";
        $orderObject->order_amount = 123.50;
        $orderObject->address = "广东省深圳xxxxxx";
        $orderObject->order_product_ids = [1222, 345, 567, rand(1, 1000)];
        $orderObject->json_data = ['name' => 'xiaomi', 'phone' => 123456789];
        $orderObject->order_status = 1;
        $orderObject->remark = 'test-remark-' . rand(1, 1000) . '-' . $struct->get('name');
        $orderObject->setFormatter(new OrderFormatter());
        //$orderObject->skipEvent(OrderEntity::AFTER_INSERT);

        // 自定义 事件覆盖原事件
        $orderObject->setEventHandle(OrderEntity::AFTER_INSERT, function () {
            /**
             * @var \Test\Module\Order\OrderEntity $this
             */
            $this->onAfterInsert();
            //var_dump('next onAfterInsert');

        });

        $orderObject->save();

        if ($orderObject->isExists()) {
            return $orderObject->getAttributes();
        }
        return [];

    }

    /**
     * @Api("测试按 uid 删除用户（打印入参）")
     *
     * curl 测试：
     * curl -X DELETE "http://127.0.0.1:9501/user/remove-use?uid=1"
     */
    #[ApiOperation(description: '测试按 uid 删除用户（打印入参）')]
    public function removeUser(RequestInput $requestInput)
    {
        RunLog::info("removeUser");
        $uid = $requestInput->input('uid');
        var_dump($uid);
    }

    /**
     * @Api("测试长时间 sleep 的 Curl/阻塞场景")
     *
     * curl 测试：
     * curl "http://127.0.0.1:9501/test-curl"
     */
    #[ApiOperation(description: '测试长时间 sleep 的 Curl/阻塞场景')]
    public function testCurl(RequestInput $requestInput): bool
    {
        sleep(10);
        return true;
    }

    /**
     * @Api("测试 PostgreSQL 子查询构建与用户列表 SQL")
     *
     * curl 测试：
     * curl "http://127.0.0.1:9501/user/pg/user-list"
     */
    #[ApiOperation(description: '测试 PostgreSQL 子查询构建与用户列表 SQL')]
    public function userList(RequestInput $requestInput)
    {
        $table = Sql::table('tbl_user')->as('user_t1');

        $query = App::getPgSql()
            ->newQuery()
            ->from($table)
            ->field($table->C("*"))
            ->where($table->C('id'),'>', 0)
            ->fetchSql()
            ->buildSql();

        $subTable = Sql::table(Sql::buildSubSql($query))->as('user_t2');

        $query = App::getPgSql()
            ->newQuery()
            ->from($subTable)
            ->field($subTable->C("user_name"))
            ->fetchSql()
            ->select();

        var_dump($query);


    }


}