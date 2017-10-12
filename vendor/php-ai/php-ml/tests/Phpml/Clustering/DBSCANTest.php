<?php

declare(strict_types=1);

namespace tests\Clustering;

use Phpml\Clustering\DBSCAN;
use PHPUnit\Framework\TestCase;

class DBSCANTest extends TestCase
{
    public function testDBSCANSamplesClustering()
    {
        $samples = [[1, 1], [8, 7], [1, 2], [7, 8], [2, 1], [8, 9]];
        $clustered = [
            [[1, 1], [1, 2], [2, 1]],
            [[8, 7], [7, 8], [8, 9]],
        ];

        $dbscan = new DBSCAN($epsilon = 2, $minSamples = 3);

        $this->assertEquals($clustered, $dbscan->cluster($samples));

        $samples = [[1, 1], [6, 6], [1, -1], [5, 6], [-1, -1], [7, 8], [-1, 1], [7, 7]];
        $clustered = [
            [[1, 1], [1, -1], [-1, -1], [-1, 1]],
            [[6, 6], [5, 6], [7, 8], [7, 7]],
        ];

        $dbscan = new DBSCAN($epsilon = 3, $minSamples = 4);

        $this->assertEquals($clustered, $dbscan->cluster($samples));
    }
}
