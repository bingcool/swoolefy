<?php

declare(strict_types=1);

namespace tests\Phpml\CrossValidation;

use Phpml\CrossValidation\RandomSplit;
use Phpml\Dataset\ArrayDataset;
use PHPUnit\Framework\TestCase;

class RandomSplitTest extends TestCase
{
    /**
     * @expectedException \Phpml\Exception\InvalidArgumentException
     */
    public function testThrowExceptionOnToSmallTestSize()
    {
        new RandomSplit(new ArrayDataset([], []), 0);
    }

    /**
     * @expectedException \Phpml\Exception\InvalidArgumentException
     */
    public function testThrowExceptionOnToBigTestSize()
    {
        new RandomSplit(new ArrayDataset([], []), 1);
    }

    public function testDatasetRandomSplitWithoutSeed()
    {
        $dataset = new ArrayDataset(
            $samples = [[1], [2], [3], [4]],
            $labels = ['a', 'a', 'b', 'b']
        );

        $randomSplit = new RandomSplit($dataset, 0.5);

        $this->assertCount(2, $randomSplit->getTestSamples());
        $this->assertCount(2, $randomSplit->getTrainSamples());

        $randomSplit2 = new RandomSplit($dataset, 0.25);

        $this->assertCount(1, $randomSplit2->getTestSamples());
        $this->assertCount(3, $randomSplit2->getTrainSamples());
    }

    public function testDatasetRandomSplitWithSameSeed()
    {
        $dataset = new ArrayDataset(
            $samples = [[1], [2], [3], [4], [5], [6], [7], [8]],
            $labels = ['a', 'a', 'a', 'a', 'b', 'b', 'b', 'b']
        );

        $seed = 123;

        $randomSplit1 = new RandomSplit($dataset, 0.5, $seed);
        $randomSplit2 = new RandomSplit($dataset, 0.5, $seed);

        $this->assertEquals($randomSplit1->getTestLabels(), $randomSplit2->getTestLabels());
        $this->assertEquals($randomSplit1->getTestSamples(), $randomSplit2->getTestSamples());
        $this->assertEquals($randomSplit1->getTrainLabels(), $randomSplit2->getTrainLabels());
        $this->assertEquals($randomSplit1->getTrainSamples(), $randomSplit2->getTrainSamples());
    }

    public function testDatasetRandomSplitWithDifferentSeed()
    {
        $dataset = new ArrayDataset(
            $samples = [[1], [2], [3], [4], [5], [6], [7], [8]],
            $labels = ['a', 'a', 'a', 'a', 'b', 'b', 'b', 'b']
        );

        $randomSplit1 = new RandomSplit($dataset, 0.5, 4321);
        $randomSplit2 = new RandomSplit($dataset, 0.5, 1234);

        $this->assertNotEquals($randomSplit1->getTestLabels(), $randomSplit2->getTestLabels());
        $this->assertNotEquals($randomSplit1->getTestSamples(), $randomSplit2->getTestSamples());
        $this->assertNotEquals($randomSplit1->getTrainLabels(), $randomSplit2->getTrainLabels());
        $this->assertNotEquals($randomSplit1->getTrainSamples(), $randomSplit2->getTrainSamples());
    }

    public function testRandomSplitCorrectSampleAndLabelPosition()
    {
        $dataset = new ArrayDataset(
            $samples = [[1], [2], [3], [4]],
            $labels = [1, 2, 3, 4]
        );

        $randomSplit = new RandomSplit($dataset, 0.5);

        $this->assertEquals($randomSplit->getTestSamples()[0][0], $randomSplit->getTestLabels()[0]);
        $this->assertEquals($randomSplit->getTestSamples()[1][0], $randomSplit->getTestLabels()[1]);
        $this->assertEquals($randomSplit->getTrainSamples()[0][0], $randomSplit->getTrainLabels()[0]);
        $this->assertEquals($randomSplit->getTrainSamples()[1][0], $randomSplit->getTrainLabels()[1]);
    }
}
