# Distance

Selected algorithms require the use of a function for calculating the distance.

### Euclidean

Class for calculation Euclidean distance.

![euclidean](https://upload.wikimedia.org/math/8/4/9/849f040fd10bb86f7c85eb0bbe3566a4.png "Euclidean Distance")

To calculate Euclidean distance:

```
$a = [4, 6];
$b = [2, 5];
   
$euclidean = new Euclidean();
$euclidean->distance($a, $b);
// return 2.2360679774998
```

### Manhattan

Class for calculation Manhattan distance.

![manhattan](https://upload.wikimedia.org/math/4/c/5/4c568bd1d76a6b15e19cb2ac3ad75350.png "Manhattan Distance")

To calculate Manhattan distance:

```
$a = [4, 6];
$b = [2, 5];
   
$manhattan = new Manhattan();
$manhattan->distance($a, $b);
// return 3
```

### Chebyshev

Class for calculation Chebyshev distance.

![chebyshev](https://upload.wikimedia.org/math/7/1/2/71200f7dbb43b3bcfbcbdb9e02ab0a0c.png "Chebyshev Distance")

To calculate Chebyshev distance:

```
$a = [4, 6];
$b = [2, 5];
   
$chebyshev = new Chebyshev();
$chebyshev->distance($a, $b);
// return 2
```

### Minkowski

Class for calculation Minkowski distance.

![minkowski](https://upload.wikimedia.org/math/a/a/0/aa0c62083c12390cb15ac3217de88e66.png "Minkowski Distance")

To calculate Minkowski distance:

```
$a = [4, 6];
$b = [2, 5];
   
$minkowski = new Minkowski();
$minkowski->distance($a, $b);
// return 2.080
```

You can provide the `lambda` parameter:

```
$a = [6, 10, 3];
$b = [2, 5, 5];

$minkowski = new Minkowski($lambda = 5);
$minkowski->distance($a, $b);
// return 5.300
```

### Custom distance

To apply your own function of distance use `Distance` interface. Example

```
class CustomDistance implements Distance
{
    /**
     * @param array $a
     * @param array $b
     *
     * @return float
     */
    public function distance(array $a, array $b): float
    {
        $distance = [];
        $count = count($a);

        for ($i = 0; $i < $count; ++$i) {
            $distance[] = $a[$i] * $b[$i];
        }

        return min($distance);    
    }
}
```
