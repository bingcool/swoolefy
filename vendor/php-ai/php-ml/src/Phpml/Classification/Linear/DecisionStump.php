<?php

declare(strict_types=1);

namespace Phpml\Classification\Linear;

use Phpml\Helper\Predictable;
use Phpml\Helper\OneVsRest;
use Phpml\Classification\WeightedClassifier;
use Phpml\Classification\DecisionTree;

class DecisionStump extends WeightedClassifier
{
    use Predictable, OneVsRest;

    const AUTO_SELECT = -1;

    /**
     * @var int
     */
    protected $givenColumnIndex;

    /**
     * @var array
     */
    protected $binaryLabels;

    /**
     * Lowest error rate obtained while training/optimizing the model
     *
     * @var float
     */
    protected $trainingErrorRate;

    /**
     * @var int
     */
    protected $column;

    /**
     * @var mixed
     */
    protected $value;

    /**
     * @var string
     */
    protected $operator;

    /**
     * @var array
     */
    protected $columnTypes;

    /**
     * @var int
     */
    protected $featureCount;

    /**
     * @var float
     */
    protected $numSplitCount = 100.0;

    /**
     * Distribution of samples in the leaves
     *
     * @var array
     */
    protected $prob;

    /**
     * A DecisionStump classifier is a one-level deep DecisionTree. It is generally
     * used with ensemble algorithms as in the weak classifier role. <br>
     *
     * If columnIndex is given, then the stump tries to produce a decision node
     * on this column, otherwise in cases given the value of -1, the stump itself
     * decides which column to take for the decision (Default DecisionTree behaviour)
     *
     * @param int $columnIndex
     */
    public function __construct(int $columnIndex = self::AUTO_SELECT)
    {
        $this->givenColumnIndex = $columnIndex;
    }

    /**
     * @param array $samples
     * @param array $targets
     * @throws \Exception
     */
    protected function trainBinary(array $samples, array $targets)
    {
        $this->samples = array_merge($this->samples, $samples);
        $this->targets = array_merge($this->targets, $targets);
        $this->binaryLabels = array_keys(array_count_values($this->targets));
        $this->featureCount = count($this->samples[0]);

        // If a column index is given, it should be among the existing columns
        if ($this->givenColumnIndex > count($this->samples[0]) - 1) {
            $this->givenColumnIndex = self::AUTO_SELECT;
        }

        // Check the size of the weights given.
        // If none given, then assign 1 as a weight to each sample
        if ($this->weights) {
            $numWeights = count($this->weights);
            if ($numWeights != count($this->samples)) {
                throw new \Exception("Number of sample weights does not match with number of samples");
            }
        } else {
            $this->weights = array_fill(0, count($this->samples), 1);
        }

        // Determine type of each column as either "continuous" or "nominal"
        $this->columnTypes = DecisionTree::getColumnTypes($this->samples);

        // Try to find the best split in the columns of the dataset
        // by calculating error rate for each split point in each column
        $columns = range(0, count($this->samples[0]) - 1);
        if ($this->givenColumnIndex != self::AUTO_SELECT) {
            $columns = [$this->givenColumnIndex];
        }

        $bestSplit = [
            'value' => 0, 'operator' => '',
            'prob' => [], 'column' => 0,
            'trainingErrorRate' => 1.0];
        foreach ($columns as $col) {
            if ($this->columnTypes[$col] == DecisionTree::CONTINUOUS) {
                $split = $this->getBestNumericalSplit($col);
            } else {
                $split = $this->getBestNominalSplit($col);
            }

            if ($split['trainingErrorRate'] < $bestSplit['trainingErrorRate']) {
                $bestSplit = $split;
            }
        }

        // Assign determined best values to the stump
        foreach ($bestSplit as $name => $value) {
            $this->{$name} = $value;
        }
    }

    /**
     * While finding best split point for a numerical valued column,
     * DecisionStump looks for equally distanced values between minimum and maximum
     * values in the column. Given <i>$count</i> value determines how many split
     * points to be probed. The more split counts, the better performance but
     * worse processing time (Default value is 10.0)
     *
     * @param float $count
     */
    public function setNumericalSplitCount(float $count)
    {
        $this->numSplitCount = $count;
    }

    /**
     * Determines best split point for the given column
     *
     * @param int $col
     *
     * @return array
     */
    protected function getBestNumericalSplit(int $col)
    {
        $values = array_column($this->samples, $col);
        // Trying all possible points may be accomplished in two general ways:
        // 1- Try all values in the $samples array ($values)
        // 2- Artificially split the range of values into several parts and try them
        // We choose the second one because it is faster in larger datasets
        $minValue = min($values);
        $maxValue = max($values);
        $stepSize = ($maxValue - $minValue) / $this->numSplitCount;

        $split = null;

        foreach (['<=', '>'] as $operator) {
            // Before trying all possible split points, let's first try
            // the average value for the cut point
            $threshold = array_sum($values) / (float) count($values);
            list($errorRate, $prob) = $this->calculateErrorRate($threshold, $operator, $values);
            if ($split == null || $errorRate < $split['trainingErrorRate']) {
                $split = ['value' => $threshold, 'operator' => $operator,
                        'prob' => $prob, 'column' => $col,
                        'trainingErrorRate' => $errorRate];
            }

            // Try other possible points one by one
            for ($step = $minValue; $step <= $maxValue; $step+= $stepSize) {
                $threshold = (float)$step;
                list($errorRate, $prob) = $this->calculateErrorRate($threshold, $operator, $values);
                if ($errorRate < $split['trainingErrorRate']) {
                    $split = ['value' => $threshold, 'operator' => $operator,
                        'prob' => $prob, 'column' => $col,
                        'trainingErrorRate' => $errorRate];
                }
            }// for
        }

        return $split;
    }

    /**
     * @param int $col
     *
     * @return array
     */
    protected function getBestNominalSplit(int $col) : array
    {
        $values = array_column($this->samples, $col);
        $valueCounts = array_count_values($values);
        $distinctVals= array_keys($valueCounts);

        $split = null;

        foreach (['=', '!='] as $operator) {
            foreach ($distinctVals as $val) {
                list($errorRate, $prob) = $this->calculateErrorRate($val, $operator, $values);

                if ($split == null || $split['trainingErrorRate'] < $errorRate) {
                    $split = ['value' => $val, 'operator' => $operator,
                        'prob' => $prob, 'column' => $col,
                        'trainingErrorRate' => $errorRate];
                }
            }
        }

        return $split;
    }


    /**
     *
     * @param type $leftValue
     * @param type $operator
     * @param type $rightValue
     *
     * @return boolean
     */
    protected function evaluate($leftValue, $operator, $rightValue)
    {
        switch ($operator) {
            case '>': return $leftValue > $rightValue;
            case '>=': return $leftValue >= $rightValue;
            case '<': return $leftValue < $rightValue;
            case '<=': return $leftValue <= $rightValue;
            case '=': return $leftValue === $rightValue;
            case '!=':
            case '<>': return $leftValue !== $rightValue;
        }

        return false;
    }

    /**
     * Calculates the ratio of wrong predictions based on the new threshold
     * value given as the parameter
     *
     * @param float $threshold
     * @param string $operator
     * @param array $values
     *
     * @return array
     */
    protected function calculateErrorRate(float $threshold, string $operator, array $values) : array
    {
        $wrong = 0.0;
        $prob = [];
        $leftLabel = $this->binaryLabels[0];
        $rightLabel= $this->binaryLabels[1];

        foreach ($values as $index => $value) {
            if ($this->evaluate($value, $operator, $threshold)) {
                $predicted = $leftLabel;
            } else {
                $predicted = $rightLabel;
            }

            $target = $this->targets[$index];
            if (strval($predicted) != strval($this->targets[$index])) {
                $wrong += $this->weights[$index];
            }

            if (! isset($prob[$predicted][$target])) {
                $prob[$predicted][$target] = 0;
            }
            $prob[$predicted][$target]++;
        }

        // Calculate probabilities: Proportion of labels in each leaf
        $dist = array_combine($this->binaryLabels, array_fill(0, 2, 0.0));
        foreach ($prob as $leaf => $counts) {
            $leafTotal = (float)array_sum($prob[$leaf]);
            foreach ($counts as $label => $count) {
                if (strval($leaf) == strval($label)) {
                    $dist[$leaf] = $count / $leafTotal;
                }
            }
        }

        return [$wrong / (float) array_sum($this->weights), $dist];
    }

    /**
     * Returns the probability of the sample of belonging to the given label
     *
     * Probability of a sample is calculated as the proportion of the label
     * within the labels of the training samples in the decision node
     *
     * @param array $sample
     * @param mixed $label
     *
     * @return float
     */
    protected function predictProbability(array $sample, $label) : float
    {
        $predicted = $this->predictSampleBinary($sample);
        if (strval($predicted) == strval($label)) {
            return $this->prob[$label];
        }

        return 0.0;
    }

    /**
     * @param array $sample
     *
     * @return mixed
     */
    protected function predictSampleBinary(array $sample)
    {
        if ($this->evaluate($sample[$this->column], $this->operator, $this->value)) {
            return $this->binaryLabels[0];
        }

        return $this->binaryLabels[1];
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return "IF $this->column $this->operator $this->value " .
            "THEN " . $this->binaryLabels[0] . " ".
            "ELSE " . $this->binaryLabels[1];
    }
}
