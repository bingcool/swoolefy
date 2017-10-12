# Tf-idf Transformer

Tf–idf, short for term frequency–inverse document frequency, is a numerical statistic that is intended to reflect how important a word is to a document in a collection or corpus.

### Constructor Parameters

* $samples (array) - samples for fit tf-idf model

```
use Phpml\FeatureExtraction\TfIdfTransformer;

$samples = [
    [1, 2, 4],
    [0, 2, 1]
];

$transformer = new TfIdfTransformer($samples);
```

### Transformation

To transform a collection of text samples use `transform` method. Example:

```
use Phpml\FeatureExtraction\TfIdfTransformer;

$samples = [
    [0 => 1, 1 => 1, 2 => 2, 3 => 1, 4 => 0, 5 => 0],
    [0 => 1, 1 => 1, 2 => 0, 3 => 0, 4 => 2, 5 => 3],
];
        
$transformer = new TfIdfTransformer($samples);
$transformer->transform($samples);

/*
$samples = [
   [0 => 0, 1 => 0, 2 => 0.602, 3 => 0.301, 4 => 0, 5 => 0],
   [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0.602, 5 => 0.903],
];
*/
        
```
