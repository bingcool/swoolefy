<?php

declare(strict_types=1);

namespace test\Phpml\Math\StandardDeviation;

use Phpml\Math\Statistic\StandardDeviation;
use PHPUnit\Framework\TestCase;

class StandardDeviationTest extends TestCase
{
    public function testStandardDeviationOfPopulationSample()
    {
        //https://pl.wikipedia.org/wiki/Odchylenie_standardowe
        $delta = 0.001;
        $population = [5, 6, 8, 9];
        $this->assertEquals(1.825, StandardDeviation::population($population), '', $delta);

        //http://www.stat.wmich.edu/s216/book/node126.html
        $delta = 0.5;
        $population = [7100, 15500, 4400, 4400, 5900, 4600, 8800, 2000, 2750, 2550,  960, 1025];
        $this->assertEquals(4079, StandardDeviation::population($population), '', $delta);

        $population = [9300,  10565,  15000,  15000,  17764,  57000,  65940,  73676,  77006,  93739, 146088, 153260];
        $this->assertEquals(50989, StandardDeviation::population($population), '', $delta);
    }

    /**
     * @expectedException \Phpml\Exception\InvalidArgumentException
     */
    public function testThrowExceptionOnEmptyArrayIfNotSample()
    {
        StandardDeviation::population([], false);
    }

    /**
     * @expectedException \Phpml\Exception\InvalidArgumentException
     */
    public function testThrowExceptionOnToSmallArray()
    {
        StandardDeviation::population([1]);
    }
}
