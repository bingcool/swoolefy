<?php
namespace Test\Process\ListProcess;

use Swoolefy\Core\Process\ProcessController;

class ListController extends ProcessController
{
    private $data;

    /**
     * ListController constructor.
     * @param $data
     * @throws \Exception
     */
    public function __construct($data)
    {
        parent::__construct();
        $this->data = $data;
    }

    /**
     * @param $
     */
    public function doHandle()
    {
        var_dump($this->data);
    }
}