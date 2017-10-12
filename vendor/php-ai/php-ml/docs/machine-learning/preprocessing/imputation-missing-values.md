# Imputation missing values

For various reasons, many real world datasets contain missing values, often encoded as blanks, NaNs or other placeholders.
To solve this problem you can use the `Imputer` class.

## Constructor Parameters

* $missingValue (mixed) - this value will be replaced (default null)
* $strategy (Strategy) - imputation strategy (read to use: MeanStrategy, MedianStrategy, MostFrequentStrategy)
* $axis (int) - axis for strategy, Imputer::AXIS_COLUMN or Imputer::AXIS_ROW

```
$imputer = new Imputer(null, new MeanStrategy(), Imputer::AXIS_COLUMN);
$imputer = new Imputer(null, new MedianStrategy(), Imputer::AXIS_ROW);
```

## Strategy

* MeanStrategy - replace missing values using the mean along the axis
* MedianStrategy - replace missing values using the median along the axis
* MostFrequentStrategy - replace missing using the most frequent value along the axis

## Example of use

```
use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Imputer\Strategy\MeanStrategy;

$data = [
    [1, null, 3, 4],
    [4, 3, 2, 1],
    [null, 6, 7, 8],
    [8, 7, null, 5],
];

$imputer = new Imputer(null, new MeanStrategy(), Imputer::AXIS_COLUMN);
$imputer->preprocess($data);

/*
$data = [
    [1, 5.33, 3, 4],
    [4, 3, 2, 1],
    [4.33, 6, 7, 8],
    [8, 7, 4, 5],
];
*/

```
