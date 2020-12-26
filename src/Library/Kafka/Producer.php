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
use RdKafka\ProducerTopic;

class Producer {
    /**
     * @var \RdKafka\Producer
     */
    protected $rdKafkaProducer;

    /**
     * @var string
     */
    protected $metaBrokerList = '127.0.0.1:9092';

    /**
     * @var Conf
     */
    protected $conf;

    /**
     * @var ProducerTopic
     */
    protected $producerTopic;

    /**
     * @var TopicConf
     */
    protected $topicConf;

    /**
     * @var string
     */
    protected $topicName;

    /**
     * Producer constructor.
     * @param string $metaBrokerList
     * @param string $topicName
     */
    public function __construct($metaBrokerList = '', string $topicName = '')
    {
        $this->conf = new \RdKafka\Conf();
        $this->setBrokerList($metaBrokerList);
        $this->topicName = $topicName;
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
     * @return \RdKafka\Producer
     */
    public function getRdKafkaProducer(): \RdKafka\Producer
    {
        if(!$this->rdKafkaProducer) {
            $this->rdKafkaProducer = new \RdKafka\Producer($this->conf);
        }
        return $this->rdKafkaProducer;
    }

    /**
     * @return ProducerTopic
     */
    public function getProducerTopic()
    {
        if(!$this->producerTopic) {
            $this->producerTopic = $this->getRdKafkaProducer()->newTopic($this->topicName, $this->topicConf ?? null);
        }

        return $this->producerTopic;
    }

    /**
     * @param string $payload
     * @param int $timeoutMs
     * @param string|null $key
     * @param int $partition
     * @param int $msgFlag
     * @return void
     * @throws Exception
     */
    public function produce(
        string $payload,
        int $timeoutMs = 5000,
        string $key = null,
        $partition = RD_KAFKA_PARTITION_UA,
        $msgFlag = 0
    ) {
        if(!$this->topicName) {
            throw new \Exception('Kafka Producer Missing topicName');
        }
        $this->getRdKafkaProducer();
        $this->rdKafkaProducer->addBrokers($this->metaBrokerList);
        $this->producerTopic = $this->getProducerTopic();
        $this->producerTopic->produce($partition, $msgFlag, $payload, $key);
        $this->rdKafkaProducer->flush($timeoutMs);
    }

    /**
     * @param string $payload
     * @param int $timeoutMs
     * @param string|null $key
     * @param array|null $headers
     * @param int $partition
     * @param int $msgFlag
     * @throws Exception
     */
    public function producev(
        string $payload,
        int $timeoutMs = 5000,
        string $key = null,
        $headers = null,
        $partition = RD_KAFKA_PARTITION_UA,
        $msgFlag = 0
    ) {
        if(!$this->topicName) {
            throw new \Exception('Kafka Producer Missing topicName');
        }
        $this->getRdKafkaProducer();
        $this->rdKafkaProducer->addBrokers($this->metaBrokerList);
        $this->producerTopic = $this->getProducerTopic();
        $this->producerTopic->producev($partition, $msgFlag, $payload, $key ?? null, $headers ?? null, $timeoutMs ?? null);
        $this->rdKafkaProducer->flush($timeoutMs);
    }

}

