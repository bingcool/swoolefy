<?php
namespace Test\Module\Order\Request;
use Doctrine\Common\Collections\ArrayCollection;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Core\Dto\BaseResponseDto;
use Swoolefy\Http\BaseRequest;

class LogSaveRequest extends BaseRequest
{
    /**
     * @var array<int>
     */
    #[ValidationRule(
        rule: "required|array",
        message: "日志ID不能为空"
    )]
    private array $logIds;


    private $logName;

    /**
     * @param array<int> $logIds
     */
    public function setLogIds(array $logIds)
    {
//        $arrayList = new ArrayCollection($logIds);
//        $this->logIds = $arrayList
//            ->map(function ($logId) {
//                return intval($logId);
//            })
//            ->toArray();
        $this->logIds = $logIds;
    }

    /**
     * @return array<int>
     */
    public function getLogIds(): array
    {
        return $this->logIds ?? [];
    }
}
