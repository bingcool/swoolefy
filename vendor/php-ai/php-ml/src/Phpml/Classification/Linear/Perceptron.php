<?php

declare(strict_types=1);

namespace Phpml\Classification\Linear;

use Phpml\Helper\Predictable;
use Phpml\Helper\OneVsRest;
use Phpml\Classification\Classifier;
use Phpml\Preprocessing\Normalizer;

class Perceptron implements Classifier
{
    use Predictable, OneVsRest;

    /**
     * The function whose result will be used to calculate the network error
     * for each instance
     *
     * @var string
     */
    protected static $errorFunction = 'outputClass';

   /**
     * @var array
     */
    protected $samples = [];

    /**
     * @var array
     */
    protected $targets = [];

    /**
     * @var array
     */
    protected $labels = [];

    /**
     * @var int
     */
    protected $featureCount = 0;

    /**
     * @var array
     */
    protected $weights;

    /**
     * @var float
     */
    protected $learningRate;

    /**
     * @var int
     */
    protected $maxIterations;

    /**
     * @var Normalizer
     */
    protected $normalizer;

    /**
     * Minimum amount of change in the weights between iterations
     * that needs to be obtained to continue the training
     *
     * @var float
     */
    protected $threshold = 1e-5;

    /**
     * Initalize a perceptron classifier with given learning rate and maximum
     * number of iterations used while training the perceptron <br>
     *
     * Learning rate should be a float value between 0.0(exclusive) and 1.0(inclusive) <br>
     * Maximum number of iterations can be an integer value greater than 0
     * @param int $learningRate
     * @param int $maxIterations
     */
    public function __construct(float $learningRate = 0.001, int $maxIterations = 1000,
        bool $normalizeInputs = true)
    {
        if ($learningRate <= 0.0 || $learningRate > 1.0) {
            throw new \Exception("Learning rate should be a float value between 0.0(exclusive) and 1.0(inclusive)");
        }

        if ($maxIterations <= 0) {
            throw new \Exception("Maximum number of iterations should be an integer greater than 0");
        }

        if ($normalizeInputs) {
            $this->normalizer = new Normalizer(Normalizer::NORM_STD);
        }

        $this->learningRate = $learningRate;
        $this->maxIterations = $maxIterations;
    }

    /**
     * Sets minimum value for the change in the weights
     * between iterations to continue the iterations.<br>
     *
     * If the weight change is less than given value then the
     * algorithm will stop training
     *
     * @param float $threshold
     */
    public function setChangeThreshold(float $threshold = 1e-5)
    {
        $this->threshold = $threshold;
    }

   /**
     * @param array $samples
     * @param array $targets
     */
    public function trainBinary(array $samples, array $targets)
    {
        $this->labels = array_keys(array_count_values($targets));
        if (count($this->labels) > 2) {
            throw new \Exception("Perceptron is for binary (two-class) classification only");
        }

        if ($this->normalizer) {
            $this->normalizer->transform($samples);
        }

        // Set all target values to either -1 or 1
        $this->labels = [1 => $this->labels[0], -1 => $this->labels[1]];
        foreach ($targets as $target) {
            $this->targets[] = strval($target) == strval($this->labels[1]) ? 1 : -1;
        }

        // Set samples and feature count vars
        $this->samples = array_merge($this->samples, $samples);
        $this->featureCount = count($this->samples[0]);

        // Init weights with random values
        $this->weights = array_fill(0, $this->featureCount + 1, 0);
        foreach ($this->weights as &$weight) {
            $weight = rand() / (float) getrandmax();
        }
        // Do training
        $this->runTraining();
    }

    /**
     * Adapts the weights with respect to given samples and targets
     * by use of perceptron learning rule
     */
    protected function runTraining()
    {
        $currIter = 0;
        $bestWeights = null;
        $bestScore = count($this->samples);
        $bestWeightIter = 0;

        while ($this->maxIterations > $currIter++) {
            $weights = $this->weights;
            $misClassified = 0;
            foreach ($this->samples as $index => $sample) {
                $target = $this->targets[$index];
                $prediction = $this->{static::$errorFunction}($sample);
                $update = $target - $prediction;
                if ($target != $prediction) {
                    $misClassified++;
                }
                // Update bias
                $this->weights[0] += $update * $this->learningRate; // Bias
                // Update other weights
                for ($i=1; $i <= $this->featureCount; $i++) {
                    $this->weights[$i] += $update * $sample[$i - 1] * $this->learningRate;
                }
            }

            // Save the best weights in the "pocket" so that
            // any future weights worse than this will be disregarded
            if ($bestWeights == null || $misClassified <= $bestScore) {
                $bestWeights = $weights;
                $bestScore = $misClassified;
                $bestWeightIter = $currIter;
            }

            // Check for early stop
            if ($this->earlyStop($weights)) {
                break;
            }
        }

        // The weights in the pocket are better than or equal to the last state
        // so, we use these weights
        $this->weights = $bestWeights;
    }

    /**
     * @param array $oldWeights
     *
     * @return boolean
     */
    protected function earlyStop($oldWeights)
    {
        // Check for early stop: No change larger than 1e-5
        $diff = array_map(
            function ($w1, $w2) {
                return abs($w1 - $w2) > 1e-5 ? 1 : 0;
            },
            $oldWeights, $this->weights);

        if (array_sum($diff) == 0) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the sample should be normalized and if so, returns the
     * normalized sample
     *
     * @param array $sample
     *
     * @return array
     */
    protected function checkNormalizedSample(array $sample)
    {
        if ($this->normalizer) {
            $samples = [$sample];
            $this->normalizer->transform($samples);
            $sample = $samples[0];
        }

        return $sample;
    }

    /**
     * Calculates net output of the network as a float value for the given input
     *
     * @param array $sample
     * @return int
     */
    protected function output(array $sample)
    {
        $sum = 0;
        foreach ($this->weights as $index => $w) {
            if ($index == 0) {
                $sum += $w;
            } else {
                $sum += $w * $sample[$index - 1];
            }
        }

        return $sum;
    }

    /**
     * Returns the class value (either -1 or 1) for the given input
     *
     * @param array $sample
     * @return int
     */
    protected function outputClass(array $sample)
    {
        return $this->output($sample) > 0 ? 1 : -1;
    }

    /**
     * Returns the probability of the sample of belonging to the given label.
     *
     * The probability is simply taken as the distance of the sample
     * to the decision plane.
     *
     * @param array $sample
     * @param mixed $label
     */
    protected function predictProbability(array $sample, $label)
    {
        $predicted = $this->predictSampleBinary($sample);

        if (strval($predicted) == strval($label)) {
            $sample = $this->checkNormalizedSample($sample);
            return abs($this->output($sample));
        }

        return 0.0;
    }

    /**
     * @param array $sample
     * @return mixed
     */
    protected function predictSampleBinary(array $sample)
    {
        $sample = $this->checkNormalizedSample($sample);

        $predictedClass = $this->outputClass($sample);

        return $this->labels[ $predictedClass ];
    }
}
