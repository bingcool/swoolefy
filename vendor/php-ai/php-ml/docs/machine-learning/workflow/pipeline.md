# Pipeline

In machine learning, it is common to run a sequence of algorithms to process and learn from dataset. For example:

    * Split each document’s text into tokens.
    * Convert each document’s words into a numerical feature vector ([Token Count Vectorizer](machine-learning/feature-extraction/token-count-vectorizer/)).
    * Learn a prediction model using the feature vectors and labels.
    
PHP-ML represents such a workflow as a Pipeline, which consists sequence of transformers and a estimator.


### Constructor Parameters

* $transformers (array|Transformer[]) - sequence of objects that implements Transformer interface
* $estimator (Estimator) - estimator that can train and predict

```
use Phpml\Classification\SVC;
use Phpml\FeatureExtraction\TfIdfTransformer;
use Phpml\Pipeline;

$transformers = [
    new TfIdfTransformer(),
];
$estimator = new SVC();

$pipeline = new Pipeline($transformers, $estimator);
```

### Example

First our pipeline replace missing value, then normalize samples and finally train SVC estimator. Thus prepared pipeline repeats each transformation step for predicted sample.

```
use Phpml\Classification\SVC;
use Phpml\Pipeline;
use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Normalizer;
use Phpml\Preprocessing\Imputer\Strategy\MostFrequentStrategy;

$transformers = [
    new Imputer(null, new MostFrequentStrategy()),
    new Normalizer(),
];
$estimator = new SVC();

$samples = [
    [1, -1, 2],
    [2, 0, null],
    [null, 1, -1],
];

$targets = [
    4,
    1,
    4,
];

$pipeline = new Pipeline($transformers, $estimator);
$pipeline->train($samples, $targets);

$predicted = $pipeline->predict([[0, 0, 0]]);

// $predicted == 4
```
