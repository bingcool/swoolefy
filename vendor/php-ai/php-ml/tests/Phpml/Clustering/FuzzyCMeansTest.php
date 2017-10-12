<?php

declare(strict_types=1);

namespace tests\Clustering;

use Phpml\Clustering\FuzzyCMeans;
use PHPUnit\Framework\TestCase;

class FuzzyCMeansTest extends TestCase
{
    public function testFCMSamplesClustering()
    {
        $samples = [[1, 1], [8, 7], [1, 2], [7, 8], [2, 1], [8, 9]];
        $fcm = new FuzzyCMeans(2);
        $clusters = $fcm->cluster($samples);
        $this->assertCount(2, $clusters);
        foreach ($samples as $index => $sample) {
            if (in_array($sample, $clusters[0]) || in_array($sample, $clusters[1])) {
                unset($samples[$index]);
            }
        }
        $this->assertCount(0, $samples);
        return $fcm;
    }

    public function testMembershipMatrix()
    {
        $fcm = $this->testFCMSamplesClustering();
        $clusterCount = 2;
        $sampleCount = 6;
        $matrix = $fcm->getMembershipMatrix();
        $this->assertCount($clusterCount, $matrix);
        foreach ($matrix as $row) {
            $this->assertCount($sampleCount, $row);
        }
        // Transpose of the matrix
        array_unshift($matrix, null);
        $matrix = call_user_func_array('array_map', $matrix);
        // All column totals should be equal to 1 (100% membership)
        foreach ($matrix as $col) {
            $this->assertEquals(1, array_sum($col));
        }
    }
}
