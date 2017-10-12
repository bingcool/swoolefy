<?php

declare(strict_types=1);

namespace tests\Preprocessing;

use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Imputer\Strategy\MeanStrategy;
use Phpml\Preprocessing\Imputer\Strategy\MedianStrategy;
use Phpml\Preprocessing\Imputer\Strategy\MostFrequentStrategy;
use PHPUnit\Framework\TestCase;

class ImputerTest extends TestCase
{
    public function testComplementsMissingValuesWithMeanStrategyOnColumnAxis()
    {
        $data = [
            [1, null, 3, 4],
            [4, 3, 2, 1],
            [null, 6, 7, 8],
            [8, 7, null, 5],
        ];

        $imputeData = [
            [1, 5.33, 3, 4],
            [4, 3, 2, 1],
            [4.33, 6, 7, 8],
            [8, 7, 4, 5],
        ];

        $imputer = new Imputer(null, new MeanStrategy(), Imputer::AXIS_COLUMN, $data);
        $imputer->transform($data);

        $this->assertEquals($imputeData, $data, '', $delta = 0.01);
    }

    public function testComplementsMissingValuesWithMeanStrategyOnRowAxis()
    {
        $data = [
            [1, null, 3, 4],
            [4, 3, 2, 1],
            [null, 6, 7, 8],
            [8, 7, null, 5],
        ];

        $imputeData = [
            [1, 2.66, 3, 4],
            [4, 3, 2, 1],
            [7, 6, 7, 8],
            [8, 7, 6.66, 5],
        ];

        $imputer = new Imputer(null, new MeanStrategy(), Imputer::AXIS_ROW, $data);
        $imputer->transform($data);

        $this->assertEquals($imputeData, $data, '', $delta = 0.01);
    }

    public function testComplementsMissingValuesWithMediaStrategyOnColumnAxis()
    {
        $data = [
            [1, null, 3, 4],
            [4, 3, 2, 1],
            [null, 6, 7, 8],
            [8, 7, null, 5],
        ];

        $imputeData = [
            [1, 6, 3, 4],
            [4, 3, 2, 1],
            [4, 6, 7, 8],
            [8, 7, 3, 5],
        ];

        $imputer = new Imputer(null, new MedianStrategy(), Imputer::AXIS_COLUMN, $data);
        $imputer->transform($data);

        $this->assertEquals($imputeData, $data, '', $delta = 0.01);
    }

    public function testComplementsMissingValuesWithMediaStrategyOnRowAxis()
    {
        $data = [
            [1, null, 3, 4],
            [4, 3, 2, 1],
            [null, 6, 7, 8],
            [8, 7, null, 5],
        ];

        $imputeData = [
            [1, 3, 3, 4],
            [4, 3, 2, 1],
            [7, 6, 7, 8],
            [8, 7, 7, 5],
        ];

        $imputer = new Imputer(null, new MedianStrategy(), Imputer::AXIS_ROW, $data);
        $imputer->transform($data);

        $this->assertEquals($imputeData, $data, '', $delta = 0.01);
    }

    public function testComplementsMissingValuesWithMostFrequentStrategyOnColumnAxis()
    {
        $data = [
            [1, null, 3, 4],
            [4, 3, 2, 1],
            [null, 6, 7, 8],
            [8, 7, null, 5],
            [8, 3, 2, 5],
        ];

        $imputeData = [
            [1, 3, 3, 4],
            [4, 3, 2, 1],
            [8, 6, 7, 8],
            [8, 7, 2, 5],
            [8, 3, 2, 5],
        ];

        $imputer = new Imputer(null, new MostFrequentStrategy(), Imputer::AXIS_COLUMN, $data);
        $imputer->transform($data);

        $this->assertEquals($imputeData, $data);
    }

    public function testComplementsMissingValuesWithMostFrequentStrategyOnRowAxis()
    {
        $data = [
            [1, null, 3, 4, 3],
            [4, 3, 2, 1, 7],
            [null, 6, 7, 8, 6],
            [8, 7, null, 5, 5],
            [8, 3, 2, 5, 4],
        ];

        $imputeData = [
            [1, 3, 3, 4, 3],
            [4, 3, 2, 1, 7],
            [6, 6, 7, 8, 6],
            [8, 7, 5, 5, 5],
            [8, 3, 2, 5, 4],
        ];

        $imputer = new Imputer(null, new MostFrequentStrategy(), Imputer::AXIS_ROW, $data);
        $imputer->transform($data);

        $this->assertEquals($imputeData, $data);
    }

    public function testImputerWorksOnFitSamples()
    {
        $trainData = [
            [1, 3, 4],
            [6, 7, 8],
            [8, 7, 5],
        ];

        $data = [
            [1, 3, null],
            [6, null, 8],
            [null, 7, 5],
        ];

        $imputeData = [
            [1, 3, 5.66],
            [6, 5.66, 8],
            [5, 7, 5],
        ];

        $imputer = new Imputer(null, new MeanStrategy(), Imputer::AXIS_COLUMN, $trainData);
        $imputer->transform($data);

        $this->assertEquals($imputeData, $data, '', $delta = 0.01);
    }
}
