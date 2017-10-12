<?php

declare(strict_types=1);

namespace tests;

use Phpml\ModelManager;
use Phpml\Regression\LeastSquares;
use PHPUnit\Framework\TestCase;

class ModelManagerTest extends TestCase
{
    public function testSaveAndRestore()
    {
        $filename = uniqid();
        $filepath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;

        $estimator = new LeastSquares();
        $modelManager = new ModelManager();
        $modelManager->saveToFile($estimator, $filepath);

        $restored = $modelManager->restoreFromFile($filepath);
        $this->assertEquals($estimator, $restored);
    }

    /**
     * @expectedException \Phpml\Exception\FileException
     */
    public function testRestoreWrongFile()
    {
        $filepath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unexisting';
        $modelManager = new ModelManager();
        $modelManager->restoreFromFile($filepath);
    }
}
