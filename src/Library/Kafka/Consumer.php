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

namespace Swoolefy\Library\Kafka;

use RdKafka\Conf;
use RdKafka\TopicConf;
use RdKafka\KafkaConsumer;

class Consumer {
    /**
     * @var KafkaConsumer
     */
    protected $rdKafkaConsumer;

    /**
     * @var string
     */
    protected $metaBrokerList = '127.0.0.9092';

    /**
     * @var string
     */
    protected $topicName;

    /**
     * @var Conf
     */
    protected $conf;

    /**
     * @var TopicConf;
     */
    protected $topicConf;

    /**
     * @var string
     */
    protected $groupId;

    /**
     * @var array
     */
    protected $rebalanceCbCallbacks = [];

    /**
     * @var array
     */
    protected $defaultConf = [
        'enable.auto.commit' => 1,
        'auto.commit.interval.ms' => 200,
        'auto.offset.reset' => 'earliest'
    ];

    /**
     * Consumer constructor.
     * @param string $metaBrokerList
     * @param string $topicName
     */
    public function __construct(
        $metaBrokerList = '',
        $topicName = ''
    )
    {
        $this->conf = new \RdKafka\Conf();
        $this->setBrokerList($metaBrokerList);
        $this->setDefaultConf();
        $this->setRebalanceCb();
        $this->topicName = $topicName;
    }

    /**
     * @param array $conf
     */
    public function setDefaultConf(array $conf = [])
    {
        $conf = array_merge($this->defaultConf, $conf);
        foreach($conf as $key => $value) {
            $this->conf->set($key, $value);
            if($key == 'auto.offset.reset') {
                $this->getTopicConf()->set($key, $value);
            }
        }
    }

    /**
     * @param $metaBrokerList
     */
    public function setBrokerList($metaBrokerList)
    {
        if(is_array($metaBrokerList)) {
            $metaBrokerList = implode(',', $metaBrokerList);
        }
        if(!empty($metaBrokerList)) {
            $this->metaBrokerList = $metaBrokerList;
            $this->conf->set('metadata.broker.list', $metaBrokerList);
        }
    }

    /**
     * @param $groupId
     */
    public function setGroupId($groupId)
    {
        $this->conf->set('group.id', $groupId);
        $this->groupId = $groupId;
    }

    /**
     * @param string $value
     */
    public function setAutoOffsetReset(string $value)
    {
        $this->getTopicConf()->set('auto.offset.reset', $value);
    }

    /**
     * @param callable|null $callback
     */
    public function setRebalanceCb(callable $callback = null)
    {
        if(!$callback) {
            $callback = $this->getRebalanceCbCallBack();
        }
        $this->conf->setRebalanceCb($callback);
    }

    /**
     * @return callable
     */
    protected function getRebalanceCbCallBack() : callable
    {
        return $callBack = function (\RdKafka\KafkaConsumer $kafka, $err, array $partitions = null) {
            switch($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    $kafka->assign($partitions);
                    $callback = $this->rebalanceCbCallbacks[RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS] ?? '';
                    if($callback instanceof \Closure) {
                        $callback->call($this, $partitions);
                    }
                    break;
                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    $kafka->assign(null);
                    $callback = $this->rebalanceCbCallbacks[RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS] ?? '';
                    if($callback instanceof \Closure) {
                        $callback->call($this, $partitions);
                    }
                    break;
                default:
                    throw new \Exception("kafka Consumer RebalanceCb ErrorCode={$err}");
            }
        };
    }

    /**
     * @param \Closure $callback
     * @return bool
     */
    public function setAssignPartitionsCallback(\Closure $callback)
    {
        $this->rebalanceCbCallbacks[RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS] = $callback;
        return true;
    }

    /**
     * @param \Closure $callback
     * @return bool
     */
    public function setRevokePartitionsCallback(\Closure $callback)
    {
        $this->rebalanceCbCallbacks[RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS] = $callback;
        return true;
    }

    /**
     * @param string $topicName
     */
    public function setTopicName(string $topicName)
    {
        $this->topicName = $topicName;
    }

    /**
     * @return string
     */
    public function getTopicName()
    {
        return $this->topicName;
    }

    /**
     * @param Conf $conf
     */
    public function setConf(Conf $conf)
    {
        $this->conf = $conf;
    }

    /**
     * @return Conf
     */
    public function getConf()
    {
        return $this->conf;
    }

    /**
     * @param TopicConf $topicConf
     */
    public function setTopicConf(TopicConf $topicConf)
    {
        $this->topicConf = $topicConf;
    }

    /**
     * @return TopicConf
     */
    public function getTopicConf()
    {
        if(!$this->topicConf) {
            $this->topicConf = new TopicConf();
        }
        return $this->topicConf;
    }

    /**
     * @param string|null $topicName
     * @return KafkaConsumer
     * @throws Throwable
     */
    public function subject(string $topicName = null)
    {
        if(!$this->groupId) {
            throw new \Exception('Kafka Consumer Missing GroupId');
        }
        if($topicName) {
            $this->topicName = $topicName;
        }
        try {
            $rdKafkaConsumer = $this->getRdKafkaConsumer();
            $rdKafkaConsumer->subscribe([$this->topicName]);
        }catch (\Throwable $throwable) {
            throw $throwable;
        }
        return $rdKafkaConsumer;
    }

    /**/
    protected function setTopicConfToConf()
    {
        $topicConf = $this->getTopicConf();
        $this->conf->setDefaultTopicConf($topicConf);
    }

    /**
     * @return KafkaConsumer
     */
    protected function getRdKafkaConsumer()
    {
        $this->setTopicConfToConf();
        $this->rdKafkaConsumer = new KafkaConsumer($this->conf);
        return $this->rdKafkaConsumer;
    }
}