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

namespace Swoolefy\Worker;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\CommandRunner;
use Swoolefy\Worker\Dto\PipeMsgDto;

class CtlApi
{
    /**
     * @var Request
     */
    public $request;

    /**
     * @var Response
     */
    public $response;

    public $processStatusList = [];

    const API_LIST = '/process-list';
    const API_START = '/process-start';
    const API_STOP = '/process-stop';
    const API_STATUS = '/process-status';

    const BIN_FILE = '/usr/bin/php';

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
        $statusFileList = file_get_contents(WORKER_STATUS_FILE);
        $statusFileList = json_decode($statusFileList, true);
        $this->processStatusList = $statusFileList['master']['children_process'] ?? [];
    }

    public function handle()
    {
        $params = $this->request->get;
        $uri = $this->request->server['request_uri'];
        $pid = $params["pid"];
        $processName = $params['process_name'];
        switch ($uri) {
            case self::API_START:
                $this->start($pid, $processName);
                break;
            case self::API_STOP:
                $this->stop($pid, $processName);
                break;
            case self::API_STATUS:
                $this->status($pid, $processName);
                break;
            default:
                $this->response->status(404);
                $this->response->end('404 Not Found');
                break;
        }
    }

    /**
     * @param Response $response
     */
    public function start(int $pid, string $processName)
    {
        $processStatus = $this->processStatusList[$processName] ?? [];
        if (empty($processStatus)) {
            return $this->returnFail('process not found', []);
        }

        $pid = $processStatus['pid'] ?? 0;
        $runner = CommandRunner::getInstance(__FUNCTION__);
        $runner->isNextHandle(false);
        if (\swoole\process::kill($pid, 0)) {
            $pipeMsgDto = new PipeMsgDto();
            $pipeMsgDto->action = WORKER_CLI_SEND_MSG;
            $pipeMsgDto->targetHandler = $processName;
            $pipeMsgDto->message = json_encode([
                'action' => 'restart',
                'msg' => ''
            ]);
            // 发送数据toWorker
            $pipeMsg = serialize($pipeMsgDto);
            $cliToWorkerPipeFile = CLI_TO_WORKER_PIPE;
            $pipe = @fopen($cliToWorkerPipeFile, 'w+');
            if (flock($pipe, LOCK_EX)) {
                fwrite($pipe, $pipeMsg);
                flock($pipe, LOCK_UN);
            }
            fclose($pipe);
        }else {
            $pipeMsgDto = new PipeMsgDto();
            $pipeMsgDto->action = WORKER_CLI_SEND_MSG;
            $pipeMsgDto->targetHandler = $processName;
            $pipeMsgDto->message = json_encode([
                'action' => 'start',
                'msg' => ''
            ]);
            // 发送数据toWorker
            $pipeMsg = serialize($pipeMsgDto);
            $cliToWorkerPipeFile = CLI_TO_WORKER_PIPE;
            $pipe = @fopen($cliToWorkerPipeFile, 'w+');
            if (flock($pipe, LOCK_EX)) {
                fwrite($pipe, $pipeMsg);
                flock($pipe, LOCK_UN);
            }
            fclose($pipe);
        }

        $this->returnSuccess(['name' => 'bingcool']);
    }

    public function stop(): void
    {
        $uri = $this->request->server['request_uri'];
    }

    public function status(): void
    {

    }
    public function returnSuccess(array $data)
    {
        $this->response->header('Content-Type', 'application/json');
        return $this->response->end(json_encode([
            'code' => 0,
            'msg' => 'success',
            'data' => $data
        ]));
    }

    public function returnFail(string $msg, array $data)
    {
        $this->response->header('Content-Type', 'application/json');
        return $this->response->end(json_encode([
            'code' => -1,
            'msg' => $msg,
            'data' => $data
        ]));
    }
}