<?php
namespace Test\Module\Order;

use Common\Library\Component\ListItemFormatter;
use Common\Library\Db\Query;
use Common\Library\Component\ListObject;

class OrderList extends ListObject
{
    protected $orderId;

    protected $userId;

    protected $address;

    protected $orderStatus;

    protected $alias = 'a';

    public function __construct()
    {
        parent::__construct();
    }

    public function setOrderId($orderId)
    {
        if (is_int($orderId)) {
            $orderIds = [$orderId];
        }else {
            $orderIds = $orderId;
        }

        $this->orderId = $orderIds;
    }

    public function setUserId($userId)
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

    protected function buildFormatter(): ?ListItemFormatter
    {
        if (!$this->formatter) {
            return new OrderFormatter();
        }
        return null;
    }

    protected function buildQuery(): Query
    {
        $model = new OrderEntity();
        return $model->newQuery()->table($model->getTableName());
    }

    protected function buildParams()
    {
        if ($this->hadBuildParams) {
            return;
        }
        $this->hadBuildParams = true;

        if (!empty($this->orderId)) {
            $this->query->whereIn('order_id', $this->orderId);
        }

        if (!empty($this->userId)) {
            $this->query->whereIn('user_id', $this->userId);
        }

        if (!empty($this->address)) {
            $this->query->where('address', $this->address);
        }

        if (!empty($this->orderStatus)) {
            $this->query->where('order_status', $this->orderStatus);
        }
    }

    public function find()
    {
        $this->buildParams();
        $this->buildOrderBy();
        $this->buildLimit();
        $list = $this->query->select();
        if ( $formatter = $this->getFormatter()) {
            $formatter->setListData($list);
            $list = $formatter->result();
        }
        return $list;
    }

    public function total(): int
    {
        $this->buildParams();
        return $this->query->count();
    }


}