<?php

declare(strict_types=1);

namespace tests\Phpml\FeatureExtraction;

use Phpml\FeatureExtraction\StopWords;
use PHPUnit\Framework\TestCase;

class StopWordsTest extends TestCase
{
    public function testCustomStopWords()
    {
        $stopWords = new StopWords(['lorem', 'ipsum', 'dolor']);

        $this->assertTrue($stopWords->isStopWord('lorem'));
        $this->assertTrue($stopWords->isStopWord('ipsum'));
        $this->assertTrue($stopWords->isStopWord('dolor'));

        $this->assertFalse($stopWords->isStopWord('consectetur'));
        $this->assertFalse($stopWords->isStopWord('adipiscing'));
        $this->assertFalse($stopWords->isStopWord('amet'));
    }

    /**
     * @expectedException \Phpml\Exception\InvalidArgumentException
     */
    public function testThrowExceptionOnInvalidLanguage()
    {
        StopWords::factory('Lorem');
    }

    public function testEnglishStopWords()
    {
        $stopWords = StopWords::factory('English');

        $this->assertTrue($stopWords->isStopWord('again'));
        $this->assertFalse($stopWords->isStopWord('strategy'));
    }

    public function testPolishStopWords()
    {
        $stopWords = StopWords::factory('Polish');

        $this->assertTrue($stopWords->isStopWord('wam'));
        $this->assertFalse($stopWords->isStopWord('transhumanizm'));
    }
}
