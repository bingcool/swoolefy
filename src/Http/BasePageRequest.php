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

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;

class BasePageRequest extends BaseRequest
{
    /**
     * @var int
     */
    #[ApiProperty(
        description: 'page页码'
    )]
    #[ValidationRule(
        rule: 'required|int',
        message: [
            'required' => 'page is required',
            'int' => 'page must be int'
        ]
    )]
    protected int $page = 1;

    /**
     * @var int
     */
    #[ApiProperty(
        description: 'page_size每页数量'
    )]
    #[ValidationRule(
        rule: 'required|int',
        message: [
            'required' => 'page_size is required',
            'int' => 'page_size must be int'
        ]
    )]
    protected int $page_size = 10;

    public function setPage(int $page): void
    {
        $this->page = $page;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPageSize(int $page_size): void
    {
        $this->page_size = $page_size;
    }
    public function getPageSize(): int
    {
        return $this->page_size;
    }
}