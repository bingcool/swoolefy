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
        description: 'pageSize每页数量'
    )]
    #[ValidationRule(
        rule: 'required|int',
        message: [
            'required' => 'pageSize is required',
            'int' => 'pageSize must be int'
        ]
    )]
    protected int $pageSize = 10;

    public function setPage(int $page): static
    {
        $this->page = $page;
        return $this;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = $pageSize;
        return $this;
    }
    public function getPageSize(): int
    {
        return $this->pageSize;
    }
}