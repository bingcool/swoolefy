<?php

declare(strict_types=1);

namespace Phpml\Classification\Linear;

use Phpml\Classification\Classifier;

class Adaline extends Perceptron
{

    /**
     * Batch training is the default Adaline training algorithm
     */
    const BATCH_TRAINING    = 1;

    /**
     * Online training: Stochastic gradient descent learning
     */
    const ONLINE_TRAINING    = 2;

    /**
     * The function whose result will be used to calculate the network error
     * for each instance
     *
     * @var string
     */
    protected static $errorFunction = 'output';

    /**
     * Training type may be either 'Batch' or 'Online' learning
     *
     * @var string
     */
    protected $trainingType;

    /**
     * Initalize an Adaline (ADAptive LInear NEuron) classifier with given learning rate and maximum
     * number of iterations used while training the classifier <br>
     *
     * Learning rate should be a float value between 0.0(exclusive) and 1.0 (inclusive) <br>
     * Maximum number of iterations can be an integer value greater than 0 <br>
     * If normalizeInputs is set to true, then every input given to the algorithm will be standardized
     * by use of standard deviation and mean calculation
     *
     * @param int $learningRate
     * @param int $maxIterations
     */
    public function __construct(float $learningRate = 0.001, int $maxIterations = 1000,
        bool $normalizeInputs = true, int $trainingType = self::BATCH_TRAINING)
    {
        if (! in_array($trainingType, [self::BATCH_TRAINING, self::ONLINE_TRAINING])) {
            throw new \Exception("Adaline can only be trained with batch and online/stochastic gradient descent algorithm");
        }

        $this->trainingType = $trainingType;

        parent::__construct($learningRate, $maxIterations, $normalizeInputs);
    }

    /**
     * Adapts the weights with respect to given samples and targets
     * by use of gradient descent learning rule
     */
    protected function runTraining()
    {
        // If online training is chosen, then the parent runTraining method
        // will be executed with the 'output' method as the error function
        if ($this->trainingType == self::ONLINE_TRAINING) {
            return parent::runTraining();
        }

        // Batch learning is executed:
        $currIter = 0;
        while ($this->maxIterations > $currIter++) {
            $weights = $this->weights;

            $outputs = array_map([$this, 'output'], $this->samples);
            $updates = array_map([$this, 'gradient'], $this->targets, $outputs);

            $this->updateWeights($updates);

            if ($this->earlyStop($weights)) {
                break;
            }
        }
    }

    /**
     * Returns the direction of gradient given the desired and actual outputs
     *
     * @param int $desired
     * @param int $output
     * @return int
     */
    protected function gradient($desired, $output)
    {
        return $desired - $output;
    }

    /**
     * Updates the weights of the network given the direction of the
     * gradient for each sample
     *
     * @param array $updates
     */
    protected function updateWeights(array $updates)
    {
        // Updates all weights at once
        for ($i=0; $i <= $this->featureCount; $i++) {
            if ($i == 0) {
                $this->weights[0] += $this->learningRate * array_sum($updates);
            } else {
                $col = array_column($this->samples, $i - 1);

                $error = 0;
                foreach ($col as $index => $val) {
                    $error += $val * $updates[$index];
                }

                $this->weights[$i] += $this->learningRate * $error;
            }
        }
    }
}
