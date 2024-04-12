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

    public $processRuntimeList = [];

    public $reportTime;

    const API_LIST = '/process-list';
    const API_START = '/process-start';
    const API_STOP = '/process-stop';
    const API_STATUS = '/process-status';

    /**
     * @param Request $request
     * @param Response $response
     */
    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
        $statusFileList = file_get_contents(WORKER_STATUS_FILE);
        $statusFileList = json_decode($statusFileList, true);
        $this->processRuntimeList = $statusFileList['master']['children_process'] ?? [];
        $this->reportTime = $statusFileList['master']['report_time'];
    }

    /**
     * @return bool|void
     */
    public function handle()
    {
        $params = $this->request->get;
        $uri = $this->request->server['request_uri'];
        $processName = $params['process_name'] ?? '';

        switch ($uri) {
            case self::API_LIST:
                $this->list();
                break;
            case self::API_START:
                $this->start($processName);
                break;
            case self::API_STOP:
                $this->stop($processName);
                break;
            case self::API_STATUS:
                $this->status($processName);
                break;
            default:
                $this->response->status(404);
                $this->response->end('404 Not Found');
                break;
        }
    }

    /**
     * @return void
     */
    public function list()
    {
        $confList = MainManager::loadConfByPath();
        foreach ($confList as &$confItem) {
            $processName = $confItem['process_name'];
            $processRuntimeItems = $this->processRuntimeList[$processName] ?? [];
            $confItem['process_list'] = [];
            if (empty($processRuntimeItems)) {
                $confItem['running'] = 0;
                $confItem['start_time'] = '';
                $confItem['report_time'] = $this->reportTime;
            }else {
                $confItem['running'] = 0;
                $confItem['start_time'] = '';
                $confItem['report_time'] = $this->reportTime;

                foreach ($processRuntimeItems as $processRuntimeItem) {
                    if (isset($processRuntimeItem['pid']) ) {
                        $pid = $processRuntimeItem['pid'];
                        if (\Swoole\Process::kill($pid, 0)) {
                            $confItem['running'] = 1;
                            $confItem['start_time'] = $processRuntimeItem['start_time'];
                            break;
                        }else {
                            $confItem['running'] = 0;
                            $confItem['start_time'] = $processRuntimeItem['start_time'];
                        }
                    }
                }
                $confItem['process_list'] = $processRuntimeItems;
            }
        }
        $this->returnSuccess($confList);
    }

    /**
     * @param Response $response
     */
    public function start(string $processName)
    {
        $action = 'restart';
        $this->sendPipeCommand($processName, $action);
        $this->returnSuccess(['action' => $action, 'time'=>date('Y-m-d H:i:s')]);
    }

    public function stop(string $processName)
    {
        $action = 'stop';
        $this->sendPipeCommand($processName, $action);
        $this->returnSuccess(['action' => $action, 'time'=>date('Y-m-d H:i:s')]);
    }

    /**
     * @param string $processName
     * @return mixed
     */
    public function status(string $processName)
    {
        sleep(1);
        $processRuntimeItems = $this->processRuntimeList[$processName] ?? [];
        if (empty($processRuntimeItems)) {
            sleep(6);
            $processRuntimeItems = $this->processRuntimeList[$processName] ?? [];
            if (empty($processRuntimeItems)) {
                return $this->returnSuccess(['status' => 0, 'time'=>date('Y-m-d H:i:s')]);
            }else {
                foreach ($processRuntimeItems as $processRuntimeItem) {
                    if (isset($processRuntimeItem['pid']) ) {
                        $pid = $processRuntimeItem['pid'];
                        if (\Swoole\Process::kill($pid, 0)) {
                            $status = 0;
                            break;
                        }
                    }
                }
            }
        }else {
            foreach ($processRuntimeItems as $processRuntimeItem) {
                if (isset($processRuntimeItem['pid']) ) {
                    $pid = $processRuntimeItem['pid'];
                    if (\Swoole\Process::kill($pid, 0)) {
                        $status = 1;
                        break;
                    }
                }
            }
        }
        return $this->returnSuccess(['status' => $status ?? 0, 'time'=>date('Y-m-d H:i:s')]);
    }

    /**
     * @param string $processName
     * @param string $action
     * @return void
     */
    protected function sendPipeCommand(string $processName, string $action, int $pid = 0)
    {
        $pipeMsgDto = new PipeMsgDto();
        $pipeMsgDto->action = WORKER_CLI_SEND_MSG;
        $pipeMsgDto->targetHandler = $processName;
        $pipeMsgDto->message = json_encode([
            'action' => $action,
            'msg' => $pid
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