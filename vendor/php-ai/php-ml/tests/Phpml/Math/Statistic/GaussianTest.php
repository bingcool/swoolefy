<?php

declare(strict_types=1);

namespace test\Phpml\Math\StandardDeviation;

use Phpml\Math\Statistic\Gaussian;
use PHPUnit\Framework\TestCase;

class GaussianTest extends TestCase
{
    public function testPdf()
    {
        $std = 1.0;
        $mean= 0.0;
        $g = new Gaussian($mean, $std);

        // Allowable error
        $delta = 0.001;
        $x     = [0, 0.1, 0.5, 1.0, 1.5, 2.0, 2.5, 3.0];
        $pdf = [0.3989, 0.3969, 0.3520, 0.2419, 0.1295, 0.0539, 0.0175, 0.0044];
        foreach ($x as $i => $v) {
            $this->assertEquals($pdf[$i], $g->pdf($v), '', $delta);

            $this->assertEquals($pdf[$i], Gaussian::distributionPdf($mean, $std, $v), '', $delta);
        }
    }
}
