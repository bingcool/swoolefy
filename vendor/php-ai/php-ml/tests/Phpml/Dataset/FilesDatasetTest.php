<?php

declare(strict_types=1);

namespace tests\Phpml\Dataset;

use Phpml\Dataset\FilesDataset;
use PHPUnit\Framework\TestCase;

class FilesDatasetTest extends TestCase
{
    /**
     * @expectedException \Phpml\Exception\DatasetException
     */
    public function testThrowExceptionOnMissingRootFolder()
    {
        new FilesDataset('some/not/existed/path');
    }

    public function testLoadFilesDatasetWithBBCData()
    {
        $rootPath = dirname(__FILE__).'/Resources/bbc';

        $dataset = new FilesDataset($rootPath);

        $this->assertCount(50, $dataset->getSamples());
        $this->assertCount(50, $dataset->getTargets());

        $targets = ['business', 'entertainment', 'politics', 'sport', 'tech'];
        $this->assertEquals($targets, array_values(array_unique($dataset->getTargets())));

        $firstSample = file_get_contents($rootPath.'/business/001.txt');
        $this->assertEquals($firstSample, $dataset->getSamples()[0][0]);

        $firstTarget = 'business';
        $this->assertEquals($firstTarget, $dataset->getTargets()[0]);

        $lastSample = file_get_contents($rootPath.'/tech/010.txt');
        $this->assertEquals($lastSample, $dataset->getSamples()[49][0]);

        $lastTarget = 'tech';
        $this->assertEquals($lastTarget, $dataset->getTargets()[49]);
    }
}
