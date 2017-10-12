# Statistic

Selected statistical methods.

## Correlation

Correlation coefficients are used in statistics to measure how strong a relationship is between two variables. There are several types of correlation coefficient.

### Pearson correlation
 
Pearson’s correlation or Pearson correlation is a correlation coefficient commonly used in linear regression.

Example:

```
use Phpml\Math\Statistic\Correlation;

$x = [43, 21, 25, 42, 57, 59];
$y = [99, 65, 79, 75, 87, 82];

Correlation::pearson($x, $y);
// return 0.549
```

## Mean

### Arithmetic

Example:

```
use Phpml\Math\Statistic\Mean;

Mean::arithmetic([2, 5];
// return 3.5

Mean::arithmetic([0.5, 0.5, 1.5, 2.5, 3.5];
// return 1.7
```

## Median

Example:

```
use Phpml\Math\Statistic\Mean;

Mean::median([5, 2, 6, 1, 3, 4]);
// return 3.5

Mean::median([5, 2, 6, 1, 3]);
// return 3
```

## Mode

Example:

```
use Phpml\Math\Statistic\Mean;

Mean::mode([5, 2, 6, 1, 3, 4, 6, 6, 5]);
// return 6
```

## Standard Deviation

Example:

```
use Phpml\Math\Statistic\StandardDeviation;

$population = [5, 6, 8, 9];
StandardDeviation::population($population)
// return 1.825

$population = [7100, 15500, 4400, 4400, 5900, 4600, 8800, 2000, 2750, 2550,  960, 1025];
StandardDeviation::population($population)
// return 4079
```
