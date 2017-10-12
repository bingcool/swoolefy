<?php

declare(strict_types=1);

namespace tests\Phpml\Metric;

use Phpml\Math\Distance\Chebyshev;
use PHPUnit\Framework\TestCase;

class ChebyshevTest extends TestCase
{
    /**
     * @var Chebyshev
     */
    private $distanceMetric;

    public function setUp()
    {
        $this->distanceMetric = new Chebyshev();
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

        $expectedDistance = 2;
        $actualDistance = $this->distanceMetric->distance($a, $b);

        $this->assertEquals($expectedDistance, $actualDistance);
    }

    public function testCalculateDistanceForThreeDimensions()
    {
        $a = [6, 10, 3];
        $b = [2, 5, 5];

        $expectedDistance = 5;
        $actualDistance = $this->distanceMetric->distance($a, $b);

        $this->assertEquals($expectedDistance, $actualDistance);
    }
}
