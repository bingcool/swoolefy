<?php

declare(strict_types=1);

namespace tests\Phpml\Metric;

use Phpml\Classification\SVC;
use Phpml\CrossValidation\RandomSplit;
use Phpml\Dataset\Demo\IrisDataset;
use Phpml\Metric\Accuracy;
use Phpml\SupportVectorMachine\Kernel;
use PHPUnit\Framework\TestCase;

class AccuracyTest extends TestCase
{
    /**
     * @expectedException \Phpml\Exception\InvalidArgumentException
     */
    public function testThrowExceptionOnInvalidArguments()
    {
        $actualLabels = ['a', 'b', 'a', 'b'];
        $predictedLabels = ['a', 'a'];

        Accuracy::score($actualLabels, $predictedLabels);
    }

    public function testCalculateNormalizedScore()
    {
        $actualLabels = ['a', 'b', 'a', 'b'];
        $predictedLabels = ['a', 'a', 'b', 'b'];

        $this->assertEquals(0.5, Accuracy::score($actualLabels, $predictedLabels));
    }

    public function testCalculateNotNormalizedScore()
    {
        $actualLabels = ['a', 'b', 'a', 'b'];
        $predictedLabels = ['a', 'b', 'b', 'b'];

        $this->assertEquals(3, Accuracy::score($actualLabels, $predictedLabels, false));
    }

    public function testAccuracyOnDemoDataset()
    {
        $dataset = new RandomSplit(new IrisDataset(), 0.5, 123);

        $classifier = new SVC(Kernel::RBF);
        $classifier->train($dataset->getTrainSamples(), $dataset->getTrainLabels());

        $predicted = $classifier->predict($dataset->getTestSamples());

        $accuracy = Accuracy::score($dataset->getTestLabels(), $predicted);

        $expected = PHP_VERSION_ID >= 70100 ? 1 : 0.959;

        $this->assertEquals($expected, $accuracy, '', 0.01);
    }
}
