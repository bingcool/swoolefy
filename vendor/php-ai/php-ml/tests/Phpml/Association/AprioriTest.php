<?php

declare(strict_types=1);

namespace tests\Classification;

use Phpml\Association\Apriori;
use Phpml\ModelManager;
use PHPUnit\Framework\TestCase;

class AprioriTest extends TestCase
{
    private $sampleGreek = [
        ['alpha', 'beta', 'epsilon'],
        ['alpha', 'beta', 'theta'],
        ['alpha', 'beta', 'epsilon'],
        ['alpha', 'beta', 'theta'],
    ];

    private $sampleChars = [
        ['E', 'D', 'N', 'E+N', 'EN'],
        ['E', 'R', 'N', 'E+R', 'E+N', 'ER', 'EN'],
        ['D', 'R'],
        ['E', 'D', 'N', 'E+N'],
        ['E', 'R', 'N', 'E+R', 'E+N', 'ER'],
        ['E', 'D', 'R', 'E+R', 'ER'],
        ['E', 'D', 'N', 'E+N', 'EN'],
        ['E', 'R', 'E+R'],
        ['E'],
        ['N'],
    ];

    private $sampleBasket = [
        [1, 2, 3, 4],
        [1, 2, 4],
        [1, 2],
        [2, 3, 4],
        [2, 3],
        [3, 4],
        [2, 4],
    ];

    public function testGreek()
    {
        $apriori = new Apriori(0.5, 0.5);
        $apriori->train($this->sampleGreek, []);

        $this->assertEquals('beta', $apriori->predict([['alpha', 'epsilon'], ['beta', 'theta']])[0][0][0]);
        $this->assertEquals('alpha', $apriori->predict([['alpha', 'epsilon'], ['beta', 'theta']])[1][0][0]);
    }

    public function testPowerSet()
    {
        $apriori = new Apriori();

        $this->assertCount(8, $this->invoke($apriori, 'powerSet', [['a', 'b', 'c']]));
    }

    public function testApriori()
    {
        $apriori = new Apriori(3 / 7);
        $apriori->train($this->sampleBasket, []);

        $L = $apriori->apriori();

        $this->assertCount(0, $L[3]);
        $this->assertCount(4, $L[2]);
        $this->assertTrue($this->invoke($apriori, 'contains', [$L[2], [1, 2]]));
        $this->assertFalse($this->invoke($apriori, 'contains', [$L[2], [1, 3]]));
        $this->assertFalse($this->invoke($apriori, 'contains', [$L[2], [1, 4]]));
        $this->assertTrue($this->invoke($apriori, 'contains', [$L[2], [2, 3]]));
        $this->assertTrue($this->invoke($apriori, 'contains', [$L[2], [2, 4]]));
        $this->assertTrue($this->invoke($apriori, 'contains', [$L[2], [3, 4]]));
    }

    public function testGetRules()
    {
        $apriori = new Apriori(0.4, 0.8);
        $apriori->train($this->sampleChars, []);

        $this->assertCount(19, $apriori->getRules());
    }

    public function testAntecedents()
    {
        $apriori = new Apriori();

        $this->assertCount(6, $this->invoke($apriori, 'antecedents', [['a', 'b', 'c']]));
    }

    public function testItems()
    {
        $apriori = new Apriori();
        $apriori->train($this->sampleGreek, []);
        $this->assertCount(4, $this->invoke($apriori, 'items', []));
    }

    public function testFrequent()
    {
        $apriori = new Apriori(0.51);
        $apriori->train($this->sampleGreek, []);

        $this->assertCount(0, $this->invoke($apriori, 'frequent', [[['epsilon'], ['theta']]]));
        $this->assertCount(2, $this->invoke($apriori, 'frequent', [[['alpha'], ['beta']]]));
    }

    public function testCandidates()
    {
        $apriori = new Apriori();
        $apriori->train($this->sampleGreek, []);

        $this->assertArraySubset([0 => ['alpha', 'beta']], $this->invoke($apriori, 'candidates', [[['alpha'], ['beta'], ['theta']]]));
        $this->assertArraySubset([1 => ['alpha', 'theta']], $this->invoke($apriori, 'candidates', [[['alpha'], ['beta'], ['theta']]]));
        $this->assertArraySubset([2 => ['beta', 'theta']], $this->invoke($apriori, 'candidates', [[['alpha'], ['beta'], ['theta']]]));
        $this->assertCount(3, $this->invoke($apriori, 'candidates', [[['alpha'], ['beta'], ['theta']]]));
    }

    public function testConfidence()
    {
        $apriori = new Apriori();
        $apriori->train($this->sampleGreek, []);

        $this->assertEquals(0.5, $this->invoke($apriori, 'confidence', [['alpha', 'beta', 'theta'], ['alpha', 'beta']]));
        $this->assertEquals(1, $this->invoke($apriori, 'confidence', [['alpha', 'beta'], ['alpha']]));
    }

    public function testSupport()
    {
        $apriori = new Apriori();
        $apriori->train($this->sampleGreek, []);

        $this->assertEquals(1.0, $this->invoke($apriori, 'support', [['alpha', 'beta']]));
        $this->assertEquals(0.5, $this->invoke($apriori, 'support', [['epsilon']]));
    }

    public function testFrequency()
    {
        $apriori = new Apriori();
        $apriori->train($this->sampleGreek, []);

        $this->assertEquals(4, $this->invoke($apriori, 'frequency', [['alpha', 'beta']]));
        $this->assertEquals(2, $this->invoke($apriori, 'frequency', [['epsilon']]));
    }

    public function testContains()
    {
        $apriori = new Apriori();

        $this->assertTrue($this->invoke($apriori, 'contains', [[['a'], ['b']], ['a']]));
        $this->assertTrue($this->invoke($apriori, 'contains', [[[1, 2]], [1, 2]]));
        $this->assertFalse($this->invoke($apriori, 'contains', [[['a'], ['b']], ['c']]));
    }

    public function testSubset()
    {
        $apriori = new Apriori();

        $this->assertTrue($this->invoke($apriori, 'subset', [['a', 'b'], ['a']]));
        $this->assertTrue($this->invoke($apriori, 'subset', [['a'], ['a']]));
        $this->assertFalse($this->invoke($apriori, 'subset', [['a'], ['a', 'b']]));
    }

    public function testEquals()
    {
        $apriori = new Apriori();

        $this->assertTrue($this->invoke($apriori, 'equals', [['a'], ['a']]));
        $this->assertFalse($this->invoke($apriori, 'equals', [['a'], []]));
        $this->assertFalse($this->invoke($apriori, 'equals', [['a'], ['b', 'a']]));
    }

    /**
     * Invokes objects method. Private/protected will be set accessible.
     *
     * @param object &$object Instantiated object to be called on
     * @param string $method  Method name to be called
     * @param array  $params  Array of params to be passed
     *
     * @return mixed
     */
    public function invoke(&$object, $method, array $params = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $params);
    }

    public function testSaveAndRestore()
    {
        $classifier = new Apriori(0.5, 0.5);
        $classifier->train($this->sampleGreek, []);

        $testSamples = [['alpha', 'epsilon'], ['beta', 'theta']];
        $predicted = $classifier->predict($testSamples);

        $filename = 'apriori-test-'.rand(100, 999).'-'.uniqid();
        $filepath = tempnam(sys_get_temp_dir(), $filename);
        $modelManager = new ModelManager();
        $modelManager->saveToFile($classifier, $filepath);

        $restoredClassifier = $modelManager->restoreFromFile($filepath);
        $this->assertEquals($classifier, $restoredClassifier);
        $this->assertEquals($predicted, $restoredClassifier->predict($testSamples));
    }
}
