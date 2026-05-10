<?php
namespace Test\Module\Order\Request;
use Doctrine\Common\Collections\ArrayCollection;
use OpenApi\Attributes\Property;
use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BasePageRequest;
use Swoolefy\Http\BaseRequest;

class LogContentPageRequest extends BasePageRequest {
    #[ApiProperty(description: '日志名称')]
    #[ValidationRule(
        rule: 'required|string'
    )]
    protected string $logName;

    public function getLogName(): string
    {
        return $this->logName;
    }

    public function setLogName(string $logName): static
    {
        $this->logName = $logName;
        return $this;
    }
}
