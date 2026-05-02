<?php
namespace Test\Module\Order\Request;
use Doctrine\Common\Collections\ArrayCollection;
use Swoolefy\Core\Dto\BaseResponseDto;
use Swoolefy\Http\BaseRequest;

class LogSaveRequest extends BaseRequest
{
    /**
     * @var array<int>
     */
    private array $logIds;

    /**
     * @param array<int> $logIds
     */
    public function setLogIds(array $logIds)
    {
        $arrayList = new ArrayCollection($logIds);
        $this->logIds = $arrayList
            ->map(function ($logId) {
                return intval($logId);
            })
            ->toArray();
    }

    /**
     * @return array<int>
     */
    public function getLogIds(): array
    {
        return $this->logIds ?? [];
    }
}
