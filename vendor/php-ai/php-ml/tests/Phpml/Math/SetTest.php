<?php

declare(strict_types=1);

namespace tests\Phpml\Math;

use Phpml\Math\Set;
use PHPUnit\Framework\TestCase;

class SetTest extends TestCase
{
    public function testUnion()
    {
        $union = Set::union(new Set([3, 1]), new Set([3, 2, 2]));

        $this->assertInstanceOf('\Phpml\Math\Set', $union);
        $this->assertEquals(new Set([1, 2, 3]), $union);
        $this->assertEquals(3, $union->cardinality());
    }

    public function testIntersection()
    {
        $intersection = Set::intersection(new Set(['C', 'A']), new Set(['B', 'C']));

        $this->assertInstanceOf('\Phpml\Math\Set', $intersection);
        $this->assertEquals(new Set(['C']), $intersection);
        $this->assertEquals(1, $intersection->cardinality());
    }

    public function testDifference()
    {
        $difference = Set::difference(new Set(['C', 'A', 'B']), new Set(['A']));

        $this->assertInstanceOf('\Phpml\Math\Set', $difference);
        $this->assertEquals(new Set(['B', 'C']), $difference);
        $this->assertEquals(2, $difference->cardinality());
    }

    public function testPower()
    {
        $power = Set::power(new Set(['A', 'B']));

        $this->assertInternalType('array', $power);
        $this->assertEquals([new Set(), new Set(['A']), new Set(['B']), new Set(['A', 'B'])], $power);
        $this->assertCount(4, $power);
    }

    public function testCartesian()
    {
        $cartesian = Set::cartesian(new Set(['A']), new Set([1, 2]));

        $this->assertInternalType('array', $cartesian);
        $this->assertEquals([new Set(['A', 1]), new Set(['A', 2])], $cartesian);
        $this->assertCount(2, $cartesian);
    }

    public function testContains()
    {
        $set = new Set(['B', 'A', 2, 1]);

        $this->assertTrue($set->contains('B'));
        $this->assertTrue($set->containsAll(['A', 'B']));

        $this->assertFalse($set->contains('C'));
        $this->assertFalse($set->containsAll(['A', 'B', 'C']));
    }

    public function testRemove()
    {
        $set = new Set(['B', 'A', 2, 1]);

        $this->assertEquals((new Set([1, 2, 2, 2, 'B']))->toArray(), $set->remove('A')->toArray());
    }

    public function testAdd()
    {
        $set = new Set(['B', 'A', 2, 1]);
        $set->addAll(['foo', 'bar']);
        $this->assertEquals(6, $set->cardinality());
    }

    public function testEmpty()
    {
        $set = new Set([1, 2]);
        $set->removeAll([2, 1]);
        $this->assertEquals(new Set(), $set);
        $this->assertTrue($set->isEmpty());
    }

    public function testToArray()
    {
        $set = new Set([1, 2, 2, 3, 'A', false, '', 1.1, -1, -10, 'B']);

        $this->assertEquals([-10, '', -1, 'A', 'B', 1, 1.1, 2, 3], $set->toArray());
    }
}
