<?php
namespace Test\Module\Order;

use Common\Library\Db\SqlBuilder;
use Test\Library\ListObject;

class OrderList extends ListObject
{
    protected $orderId;

    protected $userId;

    protected $address;

    protected $orderStatus;

    protected $alias = 'a';

    public function __construct()
    {
        $this->initFormatter();
    }


    public function initFormatter()
    {
        if (!$this->formatter) {
            $this->formatter = new OrderFormatter();
        }
        return $this->formatter;
    }


    public function setOrderId(int|array $orderId)
    {
        if (is_int($orderId)) {
            $orderIds = [$orderId];
        }else {
            $orderIds = $orderId;
        }

        $this->orderId = $orderIds;
    }

    public function setUserId(int|array $userId)
    {
        if (is_int($userId)) {
            $userIds = [$userId];
        }else {
            $userIds = $userId;
        }

        $this->userId = $userIds;
    }

    public function setAddress(string $address)
    {
        $this->address = $address;
    }

    public function setOrderStatus(?int $status)
    {
        $this->orderStatus = $status;
    }

    public function buildParams(): array
    {
        $sql = '';
        $params = [];

        if (!empty($this->orderId)) {
            SqlBuilder::buildIntWhere($this->alias, 'order_id', $this->orderId, $sql, $params);
        }

        if (!empty($this->userId)) {
            SqlBuilder::buildIntWhere($this->alias, 'user_id', $this->userId, $sql, $params);
        }

        if (!empty($this->address)) {
            SqlBuilder::buildLike($this->alias, 'address', $this->address, $sql, $params);
        }

        if (!empty($this->orderStatus)) {
            SqlBuilder::buildEqualWhere($this->alias, 'order_status', $this->orderStatus, $sql, $params);
        }

        return [$sql, $params];
    }

    public function total(): int
    {
        return 0;
    }

    public function find()
    {
        list($whereSql, $params) = $this->buildParams();
        $order = $this->buildOrderBy();
        $limit = $this->buildLimit();
        $fields = '*';
        $sql = "select * from tbl_order as {$this->alias} where {$whereSql} {$order} $limit";
        $result = (new OrderEntity(10000))->getFields();
        var_dump($result);

    }
}