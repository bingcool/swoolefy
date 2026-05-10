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

namespace Test\Module\Order\Response;

use Swoolefy\Annotation\ArrayList;
use Swoolefy\Core\Dto\ArrayDto;
use Test\Module\Order\Request\LogContentDto;

class LogContentPageResult extends ArrayDto
{
    /**
     * @var int
     */
    protected int $total = 0;

    /**
     * @var array<LogContentDto>
     */
    #[ArrayList(
        itemClass:LogContentDto::class
    )]
    protected array $list = [];

    public function setTotal(int $total)
    {
        $this->total = $total;
        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setList(array $list)
    {
        $this->list = $list;
        return $this;
    }

    public function getList(): array
    {
        return $this->list;
    }

    /**
     * @param LogContentDto $logContentDto
     * @return $this
     */
    public function addListItem(LogContentDto $logContentDto): static
    {
        $this->list[] = $logContentDto;
        return $this;
    }
}
