<?php
namespace Test\WorkerDaemon\Datahub;

use Common\Library\Aliyun\Datahub\AbstractBaseDatahub;
use Common\Library\Aliyun\Datahub\DatahubConfigDto;

class OrderProcessHandle extends AbstractBaseDatahub
{
    /**
     * @param DatahubConfigDto $config
     */
    public function __construct(DatahubConfigDto $config)
    {
        parent::__construct(
            $config->accessId,
            $config->accessKey,
            $config->endpoint,
            $config->projectId,
            $config->topicName
        );
    }
}
