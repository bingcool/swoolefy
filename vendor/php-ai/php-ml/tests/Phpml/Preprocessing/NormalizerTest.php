<?php

declare(strict_types=1);

namespace tests\Preprocessing;

use Phpml\Preprocessing\Normalizer;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    /**
     * @expectedException \Phpml\Exception\NormalizerException
     */
    public function testThrowExceptionOnInvalidNorm()
    {
        new Normalizer(99);
    }

    public function testNormalizeSamplesWithL2Norm()
    {
        $samples = [
            [1, -1, 2],
            [2, 0, 0],
            [0, 1, -1],
        ];

        $normalized = [
            [0.4, -0.4, 0.81],
            [1.0, 0.0, 0.0],
            [0.0, 0.7, -0.7],
        ];

        $normalizer = new Normalizer();
        $normalizer->transform($samples);

        $this->assertEquals($normalized, $samples, '', $delta = 0.01);
    }

    public function testNormalizeSamplesWithL1Norm()
    {
        $samples = [
            [1, -1, 2],
            [2, 0, 0],
            [0, 1, -1],
        ];

        $normalized = [
            [0.25, -0.25, 0.5],
            [1.0, 0.0, 0.0],
            [0.0, 0.5, -0.5],
        ];

        $normalizer = new Normalizer(Normalizer::NORM_L1);
        $normalizer->transform($samples);

        $this->assertEquals($normalized, $samples, '', $delta = 0.01);
    }

    public function testFitNotChangeNormalizerBehavior()
    {
        $samples = [
            [1, -1, 2],
            [2, 0, 0],
            [0, 1, -1],
        ];

        $normalized = [
            [0.4, -0.4, 0.81],
            [1.0, 0.0, 0.0],
            [0.0, 0.7, -0.7],
        ];

        $normalizer = new Normalizer();
        $normalizer->transform($samples);

        $this->assertEquals($normalized, $samples, '', $delta = 0.01);

        $normalizer->fit($samples);

        $this->assertEquals($normalized, $samples, '', $delta = 0.01);
    }

    public function testL1NormWithZeroSumCondition()
    {
        $samples = [
            [0, 0, 0],
            [2, 0, 0],
            [0, 1, -1],
        ];

        $normalized = [
            [0.33, 0.33, 0.33],
            [1.0, 0.0, 0.0],
            [0.0, 0.5, -0.5],
        ];

        $normalizer = new Normalizer(Normalizer::NORM_L1);
        $normalizer->transform($samples);

        $this->assertEquals($normalized, $samples, '', $delta = 0.01);
    }

    public function testStandardNorm()
    {
        // Generate 10 random vectors of length 3
        $samples = [];
        srand(time());
        for ($i=0; $i<10; $i++) {
            $sample = array_fill(0, 3, 0);
            for ($k=0; $k<3; $k++) {
                $sample[$k] = rand(1, 100);
            }
            $samples[] = $sample;
        }

        // Use standard normalization
        $normalizer = new Normalizer(Normalizer::NORM_STD);
        $normalizer->transform($samples);

        // Values in the vector should be some value between -3 and +3
        $this->assertCount(10, $samples);
        foreach ($samples as $sample) {
            $errors = array_filter($sample,
                function ($element) {
                    return $element < -3 || $element > 3;
                });
            $this->assertCount(0, $errors);
        }
    }
}
