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

use JsonException;
use Swoolefy\Core\Application;
use Swoolefy\Core\ResponseFormatter;

trait ResponseParseTrait
{

    /**
     * @param array $data
     * @param int $code
     * @param mixed $msg
     * @param string $formatter
     * @return mixed
     */
    public function returnJson(
        array  $data = [],
        int    $code = ResponseCode::CodeOk,
        string $msg  = ResponseCode::CodeOkText,
        string $formatter = 'json'
    )
    {
        $responseData = ResponseFormatter::formatDataArray($code, $msg, $data);
        $this->jsonSerialize($responseData, $formatter);
    }

    /**
     * @param mixed $result
     * @param string $formatter
     * @return void
     */
    public function returnResult($result, string $formatter = 'json')
    {
        $this->jsonSerialize(ActionResultNormalizer::normalize($result), $formatter);
    }

    /**
     * jsonSerialize
     * @param mixed $data
     * @param string $formatter
     * @return void
     * @throws JsonException
     */
    protected function jsonSerialize($data = [], string $formatter = 'json')
    {
        $jsonEncodeFlags = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        switch (strtoupper($formatter)) {
            case 'JSON':
                $this->swooleResponse->header('Content-Type', 'application/json; charset=utf-8');
                $responseContent = json_encode($data, $jsonEncodeFlags);
                break;
            default:
                $this->swooleResponse->header('Content-Type', 'application/json; charset=utf-8');
                $responseContent = json_encode($data, $jsonEncodeFlags);
                break;
        }

        $chunkSize = 2 * 1024 * 1024;
        $responseContentLength = strlen($responseContent);
        if ($responseContentLength > $chunkSize) {
            for ($offset = 0; $offset < $responseContentLength; $offset += $chunkSize) {
                $this->swooleResponse->write(substr($responseContent, $offset, $chunkSize));
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
