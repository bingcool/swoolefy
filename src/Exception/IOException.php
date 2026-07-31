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

namespace Swoolefy\Exception;

/**
 * 文件 IO 失败（mkdir/copy/write/rename 等），携带源/目标路径便于诊断。
 */
class IOException extends SystemException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        protected string $sourcePath = '',
        protected string $targetPath = ''
    ) {
        $detail = $message;
        if ($this->sourcePath !== '' || $this->targetPath !== '') {
            $detail .= sprintf(
                ' (source=%s, target=%s)',
                $this->sourcePath !== '' ? $this->sourcePath : '-',
                $this->targetPath !== '' ? $this->targetPath : '-'
            );
        }
        parent::__construct($detail, $code, $previous);
    }

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    public function getTargetPath(): string
    {
        return $this->targetPath;
    }
}
