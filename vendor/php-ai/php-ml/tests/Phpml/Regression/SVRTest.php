<?php

declare(strict_types=1);

namespace tests\Regression;

use Phpml\Regression\SVR;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\ModelManager;
use PHPUnit\Framework\TestCase;

class SVRTest extends TestCase
{
    public function testPredictSingleFeatureSamples()
    {
        $delta = 0.01;

        $samples = [[60], [61], [62], [63], [65]];
        $targets = [3.1, 3.6, 3.8, 4, 4.1];

        $regression = new SVR(Kernel::LINEAR);
        $regression->train($samples, $targets);

        $this->assertEquals(4.03, $regression->predict([64]), '', $delta);
    }

    public function testPredictMultiFeaturesSamples()
    {
        $delta = 0.01;

        $samples = [[73676, 1996], [77006, 1998], [10565, 2000], [146088, 1995], [15000, 2001], [65940, 2000], [9300, 2000], [93739, 1996], [153260, 1994], [17764, 2002], [57000, 1998], [15000, 2000]];
        $targets = [2000, 2750, 15500, 960, 4400, 8800, 7100, 2550, 1025, 5900, 4600, 4400];

        $regression = new SVR(Kernel::LINEAR);
        $regression->train($samples, $targets);

        $this->assertEquals([4109.82, 4112.28], $regression->predict([[60000, 1996], [60000, 2000]]), '', $delta);
    }

    public function testSaveAndRestore()
    {
        $samples = [[60], [61], [62], [63], [65]];
        $targets = [3.1, 3.6, 3.8, 4, 4.1];

        $regression = new SVR(Kernel::LINEAR);
        $regression->train($samples, $targets);

        $testSamples = [64];
        $predicted = $regression->predict($testSamples);

        $filename = 'svr-test'.rand(100, 999).'-'.uniqid();
        $filepath = tempnam(sys_get_temp_dir(), $filename);
        $modelManager = new ModelManager();
        $modelManager->saveToFile($regression, $filepath);

        $restoredRegression = $modelManager->restoreFromFile($filepath);
        $this->assertEquals($regression, $restoredRegression);
        $this->assertEquals($predicted, $restoredRegression->predict($testSamples));
    }
}
