<?php

declare(strict_types=1);

namespace tests\Phpml\FeatureExtraction;

use Phpml\FeatureExtraction\TfIdfTransformer;
use PHPUnit\Framework\TestCase;

class TfIdfTransformerTest extends TestCase
{
    public function testTfIdfTransformation()
    {
        // https://en.wikipedia.org/wiki/Tf-idf

        $samples = [
            [0 => 1, 1 => 1, 2 => 2, 3 => 1, 4 => 0, 5 => 0],
            [0 => 1, 1 => 1, 2 => 0, 3 => 0, 4 => 2, 5 => 3],
        ];

        $tfIdfSamples = [
            [0 => 0, 1 => 0, 2 => 0.602, 3 => 0.301, 4 => 0, 5 => 0],
            [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0.602, 5 => 0.903],
        ];

        $transformer = new TfIdfTransformer($samples);
        $transformer->transform($samples);

        $this->assertEquals($tfIdfSamples, $samples, '', 0.001);
    }
}
