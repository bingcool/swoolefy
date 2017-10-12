<?php

declare(strict_types=1);

namespace tests\Phpml\Math;

use Phpml\Math\Matrix;
use PHPUnit\Framework\TestCase;

class MatrixTest extends TestCase
{
    /**
     * @expectedException \Phpml\Exception\InvalidArgumentException
     */
    public function testThrowExceptionOnInvalidMatrixSupplied()
    {
        new Matrix([[1, 2], [3]]);
    }

    public function testCreateMatrixFromFlatArray()
    {
        $flatArray = [1, 2, 3, 4];
        $matrix = Matrix::fromFlatArray($flatArray);

        $this->assertInstanceOf(Matrix::class, $matrix);
        $this->assertEquals([[1], [2], [3], [4]], $matrix->toArray());
        $this->assertEquals(4, $matrix->getRows());
        $this->assertEquals(1, $matrix->getColumns());
        $this->assertEquals($flatArray, $matrix->getColumnValues(0));
    }

    /**
     * @expectedException \Phpml\Exception\MatrixException
     */
    public function testThrowExceptionOnInvalidColumnNumber()
    {
        $matrix = new Matrix([[1, 2, 3], [4, 5, 6]]);
        $matrix->getColumnValues(4);
    }

    /**
     * @expectedException \Phpml\Exception\MatrixException
     */
    public function testThrowExceptionOnGetDeterminantIfArrayIsNotSquare()
    {
        $matrix = new Matrix([[1, 2, 3], [4, 5, 6]]);
        $matrix->getDeterminant();
    }

    public function testGetMatrixDeterminant()
    {
        //http://matrix.reshish.com/determinant.php
        $matrix = new Matrix([
            [3, 3, 3],
            [4, 2, 1],
            [5, 6, 7],
        ]);
        $this->assertEquals(-3, $matrix->getDeterminant());

        $matrix = new Matrix([
            [1, 2, 3, 3, 2, 1],
            [1 / 2, 5, 6, 7, 1, 1],
            [3 / 2, 7 / 2, 2, 0, 6, 8],
            [1, 8, 10, 1, 2, 2],
            [1 / 4, 4, 1, 0, 2, 3 / 7],
            [1, 8, 7, 5, 4, 4 / 5],
        ]);
        $this->assertEquals(1116.5035, $matrix->getDeterminant(), '', $delta = 0.0001);
    }

    public function testMatrixTranspose()
    {
        $matrix = new Matrix([
            [3, 3, 3],
            [4, 2, 1],
            [5, 6, 7],
        ]);

        $transposedMatrix = [
            [3, 4, 5],
            [3, 2, 6],
            [3, 1, 7],
        ];

        $this->assertEquals($transposedMatrix, $matrix->transpose()->toArray());
    }

    /**
     * @expectedException \Phpml\Exception\InvalidArgumentException
     */
    public function testThrowExceptionOnMultiplyWhenInconsistentMatrixSupplied()
    {
        $matrix1 = new Matrix([[1, 2, 3], [4, 5, 6]]);
        $matrix2 = new Matrix([[3, 2, 1], [6, 5, 4]]);

        $matrix1->multiply($matrix2);
    }

    public function testMatrixMultiplyByMatrix()
    {
        $matrix1 = new Matrix([
            [1, 2, 3],
            [4, 5, 6],
        ]);

        $matrix2 = new Matrix([
            [7, 8],
            [9, 10],
            [11, 12],
        ]);

        $product = [
            [58, 64],
            [139, 154],
        ];

        $this->assertEquals($product, $matrix1->multiply($matrix2)->toArray());
    }

    public function testDivideByScalar()
    {
        $matrix = new Matrix([
            [4, 6, 8],
            [2, 10, 20],
        ]);

        $quotient = [
            [2, 3, 4],
            [1, 5, 10],
        ];

        $this->assertEquals($quotient, $matrix->divideByScalar(2)->toArray());
    }

    /**
     * @expectedException \Phpml\Exception\MatrixException
     */
    public function testThrowExceptionWhenInverseIfArrayIsNotSquare()
    {
        $matrix = new Matrix([[1, 2, 3], [4, 5, 6]]);
        $matrix->inverse();
    }

    /**
     * @expectedException \Phpml\Exception\MatrixException
     */
    public function testThrowExceptionWhenInverseIfMatrixIsSingular()
    {
        $matrix = new Matrix([
          [0, 0, 0],
          [0, 0, 0],
          [0, 0, 0],
       ]);

        $matrix->inverse();
    }

    public function testInverseMatrix()
    {
        //http://ncalculators.com/matrix/inverse-matrix.htm
        $matrix = new Matrix([
            [3, 4, 2],
            [4, 5, 5],
            [1, 1, 1],
        ]);

        $inverseMatrix = [
            [0, -1, 5],
            [1 / 2, 1 / 2, -7 / 2],
            [-1 / 2, 1 / 2, -1 / 2],
        ];

        $this->assertEquals($inverseMatrix, $matrix->inverse()->toArray(), '', $delta = 0.0001);
    }

    public function testCrossOutMatrix()
    {
        $matrix = new Matrix([
            [3, 4, 2],
            [4, 5, 5],
            [1, 1, 1],
        ]);

        $crossOuted = [
            [3, 2],
            [1, 1],
        ];

        $this->assertEquals($crossOuted, $matrix->crossOut(1, 1)->toArray());
    }
}
