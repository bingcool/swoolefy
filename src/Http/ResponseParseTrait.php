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

namespace Swoolefy\Http;

use Swoolefy\Core\Application;
use Swoolefy\Core\ResponseFormatter;

trait ResponseParseTrait
{

    /**
     * @param array $data
     * @param int $code
     * @param mixed $msg
     * @param string $formatter
     * @return void
     */
    public function returnJson(
        array  $data = [],
        int    $code = 0,
        string $msg  = '',
        string $formatter = 'json'
    )
    {
        $responseData = ResponseFormatter::formatDataArray($code, $msg, $data);
        $this->jsonSerialize($responseData, $formatter);
    }

    /**
     * jsonSerialize
     * @param array $data
     * @param string $formatter
     * @return void
     */
    protected function jsonSerialize(array $data = [], string $formatter = 'json')
    {
        switch (strtoupper($formatter)) {
            case 'JSON':
                $this->swooleResponse->header('Content-Type', 'application/json; charset=utf-8');
                $responseContent = json_encode($data, JSON_UNESCAPED_UNICODE);
                break;
            default:
                $responseContent = json_encode($data, JSON_UNESCAPED_UNICODE);
                break;
        }

        if (strlen($responseContent) > 2 * 1024 * 1024) {
            $chunks = str_split($responseContent, 2 * 1024 * 1024);
            unset($responseContent);
            foreach ($chunks as $k => $chunk) {
                $this->swooleResponse->write($chunk);
                unset($chunks[$k]);
            }
        } else {
            $this->swooleResponse->write($responseContent);
        }

        if(is_object(Application::getApp())) {
            Application::getApp()->setEnd();
        }
        $this->swooleResponse->end();
    }
}