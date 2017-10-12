<?php

declare(strict_types=1);

namespace tests\Phpml\Metric;

use Phpml\Math\Distance\Minkowski;
use PHPUnit\Framework\TestCase;

class MinkowskiTest extends TestCase
{
    /**
     * @var Minkowski
     */
    private $distanceMetric;

    public function setUp()
    {
        $this->distanceMetric = new Minkowski();
    }

    /**
     * @expectedException \Phpml\Exception\InvalidArgumentException
     */
    public function testThrowExceptionOnInvalidArguments()
    {
        $a = [0, 1, 2];
        $b = [0, 2];

        $this->distanceMetric->distance($a, $b);
    }

    public function testCalculateDistanceForOneDimension()
    {
        $a = [4];
        $b = [2];

        $expectedDistance = 2;
        $actualDistance = $this->distanceMetric->distance($a, $b);

        $this->assertEquals($expectedDistance, $actualDistance);
    }

    public function testCalculateDistanceForTwoDimensions()
    {
        $a = [4, 6];
        $b = [2, 5];

        $expectedDistance = 2.080;
        $actualDistance = $this->distanceMetric->distance($a, $b);

        $this->assertEquals($expectedDistance, $actualDistance, '', $delta = 0.001);
    }

    public function testCalculateDistanceForThreeDimensions()
    {
        $a = [6, 10, 3];
        $b = [2, 5, 5];

        $expectedDistance = 5.819;
        $actualDistance = $this->distanceMetric->distance($a, $b);

        $this->assertEquals($expectedDistance, $actualDistance, '', $delta = 0.001);
    }

    public function testCalculateDistanceForThreeDimensionsWithDifferentLambda()
    {
        $distanceMetric = new Minkowski($lambda = 5);

        $a = [6, 10, 3];
        $b = [2, 5, 5];

        $expectedDistance = 5.300;
        $actualDistance = $distanceMetric->distance($a, $b);

        $this->assertEquals($expectedDistance, $actualDistance, '', $delta = 0.001);
    }
}
