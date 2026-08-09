<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Runtime\RuntimeRegistry;
use Test\App;

class RuntimeController extends BController
{

    /**
     * 测试Runtime指标
     *
     * Route: GET /api/runtime
     *
    ```bash
    curl -X GET 'http://127.0.0.1:9501/api/runtime' \
    -H 'Accept: application/json'
    ```
     */
    #[ApiOperation(description: '测试Runtime指标')]
    public function runtime(): array
    {
        $enabled = (bool) (BaseServer::getConf()['runtime_observability']['diagnostics']['enable'] ?? true);
        if (!$enabled || RuntimeRegistry::diagnostics() === null) {
           throw new \Exception('Runtime diagnostics are not enabled');
        }
        $includeHistory = ($this->swooleRequest->get['memory_history'] ?? '0') === '1';
        return RuntimeRegistry::diagnostics()->snapshot($includeHistory);
    }
}
