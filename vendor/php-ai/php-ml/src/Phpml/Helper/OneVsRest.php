<?php

declare(strict_types=1);

namespace Phpml\Helper;

trait OneVsRest
{
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
    protected $classifiers;

    /**
     * @var array
     */
    protected $labels;

    /**
     * Train a binary classifier in the OvR style
     *
     * @param array $samples
     * @param array $targets
     */
    public function train(array $samples, array $targets)
    {
        // Clone the current classifier, so that
        // we don't mess up its variables while training
        // multiple instances of this classifier
        $classifier = clone $this;
        $this->classifiers = [];

        // If there are only two targets, then there is no need to perform OvR
        $this->labels = array_keys(array_count_values($targets));
        if (count($this->labels) == 2) {
            $classifier->trainBinary($samples, $targets);
            $this->classifiers[] = $classifier;
        } else {
            // Train a separate classifier for each label and memorize them
            $this->samples = $samples;
            $this->targets = $targets;
            foreach ($this->labels as $label) {
                $predictor = clone $classifier;
                $targets = $this->binarizeTargets($label);
                $predictor->trainBinary($samples, $targets);
                $this->classifiers[$label] = $predictor;
            }
        }
    }

    /**
     * Groups all targets into two groups: Targets equal to
     * the given label and the others
     *
     * @param mixed $label
     */
    private function binarizeTargets($label)
    {
        $targets = [];

        foreach ($this->targets as $target) {
            $targets[] = $target == $label ? $label : "not_$label";
        }

        return $targets;
    }


    /**
     * @param array $sample
     *
     * @return mixed
     */
    protected function predictSample(array $sample)
    {
        if (count($this->labels) == 2) {
            return $this->classifiers[0]->predictSampleBinary($sample);
        }

        $probs = [];

        foreach ($this->classifiers as $label => $predictor) {
            $probs[$label] = $predictor->predictProbability($sample, $label);
        }

        arsort($probs, SORT_NUMERIC);
        return key($probs);
    }

    /**
     * Each classifier should implement this method instead of train(samples, targets)
     *
     * @param array $samples
     * @param array $targets
     */
    abstract protected function trainBinary(array $samples, array $targets);

    /**
     * Each classifier that make use of OvR approach should be able to
     * return a probability for a sample to belong to the given label.
     *
     * @param array $sample
     *
     * @return mixed
     */
    abstract protected function predictProbability(array $sample, string $label);

    /**
     * Each classifier should implement this method instead of predictSample()
     *
     * @param array $sample
     *
     * @return mixed
     */
    abstract protected function predictSampleBinary(array $sample);
}
