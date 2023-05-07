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

/**
 * 在使用该模块时，必须提前设置这些值达到一定的值
 * /proc/sys/kernel/msgmax　　　 单个消息的最大值　　　　  缺省值为 8192
 * /proc/sys/kernel/msgmnb  　　 单个消息队列的容量的最大值　缺省值为 16384
 * /proc/sys/kernel/msgmni  　　 消息体的数量　　　　　　　缺省值为　16
 * 可通过下面的方式进行设置
 * echo 819200 > /proc/sys/kernel/msgmax
 * echo 1638400 > /proc/sys/kernel/msgmnb
 * echo 1600 > /proc/sys/kernel/msgmni
 *
 * 需要注意的是，一般是通过公式 msgmax * msgmni < msgmnb 来设置
 */

/**
 * $msgStat = msg_stat_queue($msgQueue);
 * print_r($msgStat);
 * 打印出如下：
 * msg_perm.uid The uid of the owner of the queue.
 * msg_perm.gid The gid of the owner of the queue.
 * msg_perm.mode The file access mode of the queue.
 * msg_stime The time that the last message was sent to the queue.
 * msg_rtime The time that the last message was received from the queue.
 * msg_ctime The time that the queue was last changed.
 * msg_qnum The number of messages waiting to be read from the queue.
 * msg_qbytes The maximum number of bytes allowed in one message queue. On Linux, this value may be read and modified via /proc/sys/kernel/msgmnb.
 * msg_lspid The pid of the process that sent the last message to the queue.
 * msg_lrpid The pid of the process that received the last message from the queue.
 */

namespace Swoolefy\Core\Memory;

use Swoolefy\Exception\SystemException;

class SysvmsgManager
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * @var array
     */
    private $msgQueues = [];

    /**
     * @var array
     */
    private $msgNameMapQueue = [];

    /**
     * @var array
     */
    private $msgProject = [];

    /**
     * @var array
     */
    private $msgTypes = [];

    /**
     * read from sys_kernel
     * @var array
     */
    private $sysKernelInfo = [];

    /**
     * max msg size
     * @var int
     */
    private $sysKernelMsgmnb;

    /**
     * default msg type
     */
    const COMMON_MSG_TYPE = 1;

    /**
     * addMsgFtok create Msg instance
     *
     * @param string $msg_queue_name
     * @param string $path_name
     * @param string $project
     * @return bool
     * @throws \Exception
     */
    public function addMsgFtok(string $msg_queue_name, string $path_name, string $project)
    {
        if (!extension_loaded('sysvmsg')) {
            throw new \Exception(sprintf("【Warning】%s::%s missing sysvmsg extension",
                __CLASS__,
                __FUNCTION__
            ));
        }

        if (strlen($project) != 1) {
            throw new \Exception(sprintf("【Warning】%s::%s. the params of project require only one string charset",
                __CLASS__,
                __FUNCTION__
            ));
        }

        $pathNameKey     = md5($path_name);
        $msgQueueNameKey = md5($msg_queue_name);

        if (isset($this->msgProject[$pathNameKey][$project])) {
            throw new \Exception(sprintf("【Warning】%s::%s. the params of project is had setting",
                __CLASS__,
                __FUNCTION__
            ));
        }

        $this->msgProject[$pathNameKey][$project] = $project;

        $msgKey = ftok($path_name, $project);
        if ($msgKey < 0) {
            throw new SystemException(sprintf("【Warning】%s::%s create msg_key failed",
                __CLASS__,
                __FUNCTION__
            ));
        }

        $msgQueue = msg_get_queue($msgKey, 0666);
        $this->msgNameMapQueue[$msgQueueNameKey] = $msg_queue_name;

        if (is_resource($msgQueue) && msg_queue_exists($msgKey)) {
            $this->msgQueues[$msgQueueNameKey] = $msgQueue;
            defined('ENABLE_WORKERFY_SYSVMSG_MSG') or define('ENABLE_WORKERFY_SYSVMSG_MSG', 1);
        }

        return true;
    }

    /**
     * getSysKernelInfo
     *
     * @param bool $force
     * @return array
     */
    public function getSysKernelInfo(bool $force = false)
    {
        if (isset($this->sysKernelInfo) && !empty($this->sysKernelInfo) && !$force) {
            return $this->sysKernelInfo;
        }
        // 单个消息体最大限制，单位字节
        $msgMax = @file_get_contents("/proc/sys/kernel/msgmax");
        // 队列的最大容量限制，单位字节
        $msgmnb = @file_get_contents("/proc/sys/kernel/msgmnb");
        // 队列能存消息体的最大的数量个数
        $msgmni = @file_get_contents("/proc/sys/kernel/msgmni");
        $this->sysKernelInfo = ['msgmax' => (int)$msgMax, 'msgmnb' => (int)$msgmnb, 'msgmni' => (int)$msgmni];
        return $this->sysKernelInfo;
    }

    /**
     * registerMsgType 注册消息类型
     *
     * @param string $msg_queue_name
     * @param string $msg_type_name
     * @param int $msg_type
     * @return bool
     * @throws \Exception
     */
    public function registerMsgType(
        string $msg_queue_name,
        string $msg_type_name,
        int $msg_type = 1
    )
    {
        if ($msg_type <= 0) {
            throw new SystemException(sprintf("【Warning】%s::%s third param of msg_flag_num need to > 0",
                __CLASS__,
                __FUNCTION__
            ));
        }

        $msgQueueNameKey = md5($msg_queue_name);
        $msgTypeNameKey = md5($msg_type_name);
        if (isset($this->msgTypes[$msgQueueNameKey][$msgTypeNameKey])) {
            throw new SystemException(sprintf("【Warning】%s::%s second params of msg_type_name=%s had setting",
                __CLASS__,
                __FUNCTION__,
                $msg_type_name
            ));
        }

        if (isset($this->msgTypes[$msgQueueNameKey])) {
            $registerMsgTypes = array_values($this->msgTypes[$msgQueueNameKey]);
            if (!in_array($msg_type, $registerMsgTypes)) {
                $this->msgTypes[$msgQueueNameKey][$msgTypeNameKey] = $msg_type;
                return true;
            }
        } else {
            $this->msgTypes[$msgQueueNameKey][$msgTypeNameKey] = $msg_type;
            return true;
        }
    }

    /**
     * push msg
     *
     * @param string $msg_queue_name
     * @param mixed $msg
     * @param string|null $msg_type_name
     * @return bool
     * @throws \Exception
     */
    public function push(string $msg_queue_name, $msg, ?string $msg_type_name = null)
    {
        $msgQueueNameKey = md5($msg_queue_name);
        if (!isset($this->msgQueues[$msgQueueNameKey])) {
            throw new SystemException(sprintf("【Warning】%s::%s queue=%s is not exist",
                __CLASS__,
                __FUNCTION__,
                $msg_queue_name
            ));
        }

        $msgType = self::COMMON_MSG_TYPE;
        if ($msg_type_name) {
            $msgTypeNameKey = md5($msg_type_name);
            if (isset($this->msgTypes[$msgQueueNameKey][$msgTypeNameKey])) {
                $msgType = $this->msgTypes[$msgQueueNameKey][$msgTypeNameKey];
            } else {
                throw new SystemException(sprintf("【Warning】%s::%s msg type=%s is not exist",
                    __CLASS__,
                    __FUNCTION__,
                    $msg_type_name
                ));
            }
        }

        $msgQueue = $this->msgQueues[$msgQueueNameKey];
        $res = msg_send($msgQueue, $msgType, $msg, $serialize = true, $blocking = false, $errorCode);
        if ($res === false) {
            throw new SystemException(sprintf("【Warning】%s::%s msg_send error, error code=%d",
                __CLASS__,
                __FUNCTION__,
                $errorCode));
        }
        return true;
    }

    /**
     * msgRecive
     *
     * @param string $msg_queue_name
     * @param string|null $msg_type_name
     * @param int $max_size
     * @return mixed
     * @throws \Exception
     */
    public function pop(
        string $msg_queue_name,
        ?string $msg_type_name = null,
        int $max_size = 65535
    )
    {
        $msgQueueNameKey = md5($msg_queue_name);
        if (!isset($this->msgQueues[$msgQueueNameKey])) {
            throw new SystemException(sprintf("【Warning】%s::%s queue=%s is not exist",
                __CLASS__,
                __FUNCTION__,
                $msg_queue_name
            ));
        }

        if ($msg_type_name) {
            $msgTypeNameKey = md5($msg_type_name);
            if (isset($this->msgTypes[$msgQueueNameKey][$msgTypeNameKey])) {
                $msgTypeFlagNum = $this->msgTypes[$msgQueueNameKey][$msgTypeNameKey];
            } else {
                throw new SystemException(sprintf("【Warning】%s::%s msg type=%s is not exist",
                    __CLASS__,
                    __FUNCTION__,
                    $msg_type_name
                ));
            }
        } else {
            $msgTypeFlagNum = self::COMMON_MSG_TYPE;
        }

        $msgQueue = $this->msgQueues[$msgQueueNameKey];
        $res = msg_receive($msgQueue, $msgTypeFlagNum, $msgType, $max_size, $msg, true, 0, $errorCode);
        if ($res === false) {
            throw new SystemException(sprintf("【Warning】%s::%s. msg_receive() accept msg error, code=%d",
                __CLASS__,
                __FUNCTION__,
                $errorCode
            ));
        }
        return $msg;
    }

    /**
     * getMsgQueue 获取队列实例
     *
     * @param string $msg_queue_name
     * @return \SysvMessageQueue|resource
     * @throws \Exception
     */
    public function getMsgQueue(string $msg_queue_name)
    {
        $msgQueueNameKey = md5($msg_queue_name);
        if (!isset($this->msgQueues[$msgQueueNameKey])) {
            throw new SystemException(sprintf("【Warning】%s::%s. queue msg=%s is not exist",
                __CLASS__,
                __FUNCTION__,
                $msg_queue_name
            ));
        }
        /**
         * @var \SysvMessageQueue|resource $msgQueue
         */
        $msgQueue = $this->msgQueues[$msgQueueNameKey];
        return $msgQueue;
    }

    /**
     * getMsgType 获取注册的类型，默认是1，公共类型
     *
     * @param string $msg_queue_name
     * @param string|null $msg_type_name
     * @return int|mixed
     * @throws \Exception
     */
    public function getMsgType(string $msg_queue_name, ?string $msg_type_name = null)
    {
        $msgType = self::COMMON_MSG_TYPE;
        $msgQueueNameKey = md5($msg_queue_name);
        if ($msg_type_name) {
            $msgTypeNameKey = md5($msg_type_name);
            if (isset($this->msgTypes[$msgQueueNameKey][$msgTypeNameKey])) {
                $msgType = $this->msgTypes[$msgQueueNameKey][$msgTypeNameKey];
            } else {
                throw new SystemException(sprintf("【Warning】s%::s% msg type=s% is not exist",
                    __CLASS__,
                    __FUNCTION__,
                    $msg_queue_name
                ));
            }
        }
        return $msgType;
    }

    /**
     * getMsgQueueWaitToPopNum 获取队列里面待读取消息体数量
     *
     * @param string $msg_queue_name
     * @return mixed
     * @throws \Exception
     */
    public function getMsgQueueWaitToPopNum(string $msg_queue_name)
    {
        $msgQueue = $this->getMsgQueue($msg_queue_name);
        $status = msg_stat_queue($msgQueue);
        if (!isset($this->sysKernelMsgmnb)) {
            if (isset($status['msg_qbytes'])) {
                $this->sysKernelMsgmnb = $status['msg_qbytes'];
            }
        }
        return $status['msg_qnum'];
    }

    /**
     * 队列容量大小-单位字节
     *
     * @param string $msg_queue_name
     * @return mixed
     * @throws \Exception
     */
    public function getMsgQueueSize(string $msg_queue_name)
    {
        if (isset($this->sysKernelMsgmnb)) {
            return $this->sysKernelMsgmnb;
        }
        $msgQueue = $this->getMsgQueue($msg_queue_name);
        $status = msg_stat_queue($msgQueue);
        if (isset($status['msg_qbytes'])) {
            $this->sysKernelMsgmnb = $status['msg_qbytes'];
        }
        return $this->sysKernelMsgmnb;
    }

    /**
     * @param string|null $msg_queue_name
     * @return bool
     * @throws \Exception
     */
    public function destroyMsgQueue(?string $msg_queue_name = null)
    {
        if ($msg_queue_name) {
            $msgQueue = $this->getMsgQueue($msg_queue_name);
            is_resource($msgQueue) && msg_remove_queue($msgQueue);
            return true;
        }
        // remove all
        if (!empty($this->msgQueues)) {
            foreach ($this->msgQueues as $msgQueue) {
                if (is_resource($msgQueue)) {
                    $status = msg_stat_queue($msgQueue);
                    if ($status['msg_qnum'] == 0) {
                        msg_remove_queue($msgQueue);
                    }
                }
            }
        }
    }

    /**
     * getAllMsgQueueWaitToPopNum
     * @return array
     */
    public function getAllMsgQueueWaitToPopNum()
    {
        $result = [];
        foreach ($this->msgQueues as $key => $msgQueue) {
            if (is_resource($msgQueue)) {
                $status = msg_stat_queue($msgQueue);
                $waitToReadNum = $status['msg_qnum'];
                if ($msgQueueName = $this->msgNameMapQueue[$key]) {
                    $result[] = [$msgQueueName, $waitToReadNum];
                }
            }
        }
        return $result;
    }
}