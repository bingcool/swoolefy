<?php
namespace Test\Module\Order\Request;
use Doctrine\Common\Collections\ArrayCollection;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class LogSaveRequest extends BaseRequest
{
    /**
     * @var array<int>
     */
    #[ValidationRule(
        rule: "required|array",
        message: "日志ID不能为空",
        itemRule: "int",
        itemMessage: "日志ID必须是整数"
    )]
    private array $logIds;

    /**
     * @var array<LogContentDto>
     */
    #[ValidationRule(
        rule: "required|array",
        message: "日志内容不能为空",
        itemClass: LogContentDto::class,
    )]
    private array $logContents;

    /**
     * @param array<int> $logIds
     */
    public function setLogIds(array $logIds)
    {
        $this->logIds = $logIds;
    }

    /**
     * @return array<int>
     */
    public function getLogIds(): array
    {
        return $this->logIds ?? [];
    }

    /**
     * @param array<int, LogContentDto> $logContents
     */
    public function setLogContents(array $logContents)
    {
        $this->logContents = $logContents;
    }

    /**
     * @return LogContentDto[]
     */
    public function getLogContents(): array
    {
        return $this->logContents ?? [];
    }
}
