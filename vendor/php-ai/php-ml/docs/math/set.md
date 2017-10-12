# Set

Class that wraps PHP arrays containing primitive types to mathematical sets.

### Creation

To create Set use flat arrays containing primitives only:

```
use \Phpml\Math\Set;

$set = new Set([1, 2, 2, 3, 1.1, -1, -10]);
$set->toArray();
// return [-10, -1, 1, 1.1, 2, 3]

$set = new Set(['B', '', 'A']);
$set->toArray();
// return ['', 'A', 'B']
```

Injected array is sorted by SORT_ASC, duplicates are removed and index is rewritten. 

### Union

Create the union of two Sets:

```
use \Phpml\Math\Set;

$union = Set::union(new Set([1, 3]), new Set([1, 2]));
$union->toArray();
//return [1, 2, 3]
```

### Intersection

Create the intersection of two Sets:

```
use \Phpml\Math\Set;

$intersection = Set::intersection(new Set(['A', 'C']), new Set(['B', 'C']));
$intersection->toArray();
//return ['C']
```

### Complement

Create the set-theoretic difference of two Sets:

```
use \Phpml\Math\Set;

$difference = Set::difference(new Set(['A', 'B', 'C']), new Set(['A']));
$union->toArray();
//return ['B', 'C']
```

### Adding elements

```
use \Phpml\Math\Set;

$set = new Set([1, 2]);
$set->addAll([3]);
$set->add(4);
$set->toArray();
//return [1, 2, 3, 4]
```

### Removing elements

```
use \Phpml\Math\Set;

$set = new Set([1, 2]);
$set->removeAll([2]);
$set->remove(1);
$set->toArray();
//return []
```

### Check membership

```
use \Phpml\Math\Set;

$set = new Set([1, 2]);
$set->containsAll([2, 3]);
//return false
$set->contains(1);
//return true
```

### Cardinality

```
use \Phpml\Math\Set;

$set = new Set([1, 2]);
$set->cardinality();
//return 2
```

### Is empty

```
use \Phpml\Math\Set;

$set = new Set();
$set->isEmpty();
//return true
```

### Working with loops

```
use \Phpml\Math\Set;

$set = new Set(['A', 'B', 'C']);

foreach($set as $element) {
    echo "$element, ";
}

// echoes A, B, C
```
