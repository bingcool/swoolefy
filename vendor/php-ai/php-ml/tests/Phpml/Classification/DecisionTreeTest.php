<?php

declare(strict_types=1);

namespace tests\Classification;

use Phpml\Classification\DecisionTree;
use Phpml\ModelManager;
use PHPUnit\Framework\TestCase;

class DecisionTreeTest extends TestCase
{
    private $data = [
        ['sunny',        85,        85,    'false',    'Dont_play'    ],
        ['sunny',        80,    90,    'true',        'Dont_play'    ],
        ['overcast',    83,    78,    'false',    'Play'        ],
        ['rain',        70,    96,    'false',    'Play'        ],
        ['rain',        68,    80,    'false',    'Play'        ],
        ['rain',        65,    70,    'true',    'Dont_play'    ],
        ['overcast',    64,    65,    'true',    'Play'        ],
        ['sunny',        72,    95,    'false',    'Dont_play'    ],
        ['sunny',        69,    70,    'false',    'Play'        ],
        ['rain',        75,    80,    'false',    'Play'        ],
        ['sunny',        75,    70,    'true',    'Play'        ],
        ['overcast',    72,    90,    'true',    'Play'        ],
        ['overcast',    81,    75,    'false',    'Play'        ],
        ['rain',        71,    80,    'true',    'Dont_play'    ]
    ];

    private $extraData = [
        ['scorching',   90,     95,     'false',   'Dont_play'],
        ['scorching',  100,     93,     'true',    'Dont_play'],
    ];

    private function getData($input)
    {
        $targets = array_column($input, 4);
        array_walk($input, function (&$v) {
            array_splice($v, 4, 1);
        });
        return [$input, $targets];
    }

    public function testPredictSingleSample()
    {
        list($data, $targets) = $this->getData($this->data);
        $classifier = new DecisionTree(5);
        $classifier->train($data, $targets);
        $this->assertEquals('Dont_play', $classifier->predict(['sunny', 78, 72, 'false']));
        $this->assertEquals('Play', $classifier->predict(['overcast', 60, 60, 'false']));
        $this->assertEquals('Dont_play', $classifier->predict(['rain', 60, 60, 'true']));

        list($data, $targets) = $this->getData($this->extraData);
        $classifier->train($data, $targets);
        $this->assertEquals('Dont_play', $classifier->predict(['scorching', 95, 90, 'true']));
        $this->assertEquals('Play', $classifier->predict(['overcast', 60, 60, 'false']));
        return $classifier;
    }

    public function testSaveAndRestore()
    {
        list($data, $targets) = $this->getData($this->data);
        $classifier = new DecisionTree(5);
        $classifier->train($data, $targets);

        $testSamples = [['sunny', 78, 72, 'false'], ['overcast', 60, 60, 'false']];
        $predicted = $classifier->predict($testSamples);

        $filename = 'decision-tree-test-'.rand(100, 999).'-'.uniqid();
        $filepath = tempnam(sys_get_temp_dir(), $filename);
        $modelManager = new ModelManager();
        $modelManager->saveToFile($classifier, $filepath);

        $restoredClassifier = $modelManager->restoreFromFile($filepath);
        $this->assertEquals($classifier, $restoredClassifier);
        $this->assertEquals($predicted, $restoredClassifier->predict($testSamples));
    }

    public function testTreeDepth()
    {
        list($data, $targets) = $this->getData($this->data);
        $classifier = new DecisionTree(5);
        $classifier->train($data, $targets);
        $this->assertTrue(5 >= $classifier->actualDepth);
    }
}
