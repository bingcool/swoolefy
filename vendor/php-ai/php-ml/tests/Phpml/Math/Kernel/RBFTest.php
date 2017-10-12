<?php

declare(strict_types=1);

namespace test\Phpml\Math\Kernel;

use Phpml\Math\Kernel\RBF;
use PHPUnit\Framework\TestCase;

class RBFTest extends TestCase
{
    public function testComputeRBFKernelFunction()
    {
        $rbf = new RBF($gamma = 0.001);

        $this->assertEquals(1, $rbf->compute([1, 2], [1, 2]));
        $this->assertEquals(0.97336, $rbf->compute([1, 2, 3], [4, 5, 6]), '', $delta = 0.0001);
        $this->assertEquals(0.00011, $rbf->compute([4, 5], [1, 100]), '', $delta = 0.0001);

        $rbf = new RBF($gamma = 0.2);

        $this->assertEquals(1, $rbf->compute([1, 2], [1, 2]));
        $this->assertEquals(0.00451, $rbf->compute([1, 2, 3], [4, 5, 6]), '', $delta = 0.0001);
        $this->assertEquals(0, $rbf->compute([4, 5], [1, 100]));
    }
}
