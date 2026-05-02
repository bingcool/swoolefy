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

class BaseRequest
{
    /**
     * @var RequestInput
     */
    private RequestInput $requestInput;

    /**
     * @param RequestInput $requestInput
     * @return static
     */
    public function setRequestInput(RequestInput $requestInput): static
    {
        $this->requestInput = $requestInput;
        return $this;
    }

    /**
     * @return RequestInput
     */
    public function getRequestInput(): RequestInput
    {
        return $this->requestInput;
    }

    public function toArray(): array
    {
        $data = (array)$this;
        unset($data['requestInput']);
        return $data;
    }
}