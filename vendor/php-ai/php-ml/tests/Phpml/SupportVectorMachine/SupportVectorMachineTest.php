<?php

declare(strict_types=1);

namespace tests\SupportVectorMachine;

use Phpml\SupportVectorMachine\Kernel;
use Phpml\SupportVectorMachine\SupportVectorMachine;
use Phpml\SupportVectorMachine\Type;
use PHPUnit\Framework\TestCase;

class SupportVectorMachineTest extends TestCase
{
    public function testTrainCSVCModelWithLinearKernel()
    {
        $samples = [[1, 3], [1, 4], [2, 4], [3, 1], [4, 1], [4, 2]];
        $labels = ['a', 'a', 'a', 'b', 'b', 'b'];

        $model =
            'svm_type c_svc
kernel_type linear
nr_class 2
total_sv 2
rho 0
label 0 1
nr_sv 1 1
SV
0.25 1:2 2:4 
-0.25 1:4 2:2 
';

        $svm = new SupportVectorMachine(Type::C_SVC, Kernel::LINEAR, 100.0);
        $svm->train($samples, $labels);

        $this->assertEquals($model, $svm->getModel());
    }

    public function testPredictSampleWithLinearKernel()
    {
        $samples = [[1, 3], [1, 4], [2, 4], [3, 1], [4, 1], [4, 2]];
        $labels = ['a', 'a', 'a', 'b', 'b', 'b'];

        $svm = new SupportVectorMachine(Type::C_SVC, Kernel::LINEAR, 100.0);
        $svm->train($samples, $labels);

        $predictions = $svm->predict([
            [3, 2],
            [2, 3],
            [4, -5],
        ]);

        $this->assertEquals('b', $predictions[0]);
        $this->assertEquals('a', $predictions[1]);
        $this->assertEquals('b', $predictions[2]);
    }

    public function testPredictSampleFromMultipleClassWithRbfKernel()
    {
        $samples = [
            [1, 3], [1, 4], [1, 4],
            [3, 1], [4, 1], [4, 2],
            [-3, -1], [-4, -1], [-4, -2],
        ];
        $labels = [
            'a', 'a', 'a',
            'b', 'b', 'b',
            'c', 'c', 'c',
        ];

        $svm = new SupportVectorMachine(Type::C_SVC, Kernel::RBF, 100.0);
        $svm->train($samples, $labels);

        $predictions = $svm->predict([
            [1, 5],
            [4, 3],
            [-4, -3],
        ]);

        $this->assertEquals('a', $predictions[0]);
        $this->assertEquals('b', $predictions[1]);
        $this->assertEquals('c', $predictions[2]);
    }
}
