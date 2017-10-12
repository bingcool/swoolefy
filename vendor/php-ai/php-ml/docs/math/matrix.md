# Matrix

Class that wraps PHP arrays to mathematical matrix.

### Creation

To create Matrix use simple arrays:

```
$matrix = new Matrix([
    [3, 3, 3],
    [4, 2, 1],
    [5, 6, 7],
]);
```

You can also create Matrix (one dimension) from flat array:

```
$flatArray = [1, 2, 3, 4];
$matrix = Matrix::fromFlatArray($flatArray);
```

### Matrix data

Methods for reading data from Matrix:

```
$matrix->toArray(); // cast matrix to PHP array
$matrix->getRows(); // rows count
$matrix->getColumns(); // columns count
$matrix->getColumnValues($column=4); // get values from given column
```

### Determinant

Read more about [matrix determinant](https://en.wikipedia.org/wiki/Determinant).

```
$matrix = new Matrix([
    [3, 3, 3],
    [4, 2, 1],
    [5, 6, 7],
]);

$matrix->getDeterminant();
// return -3
```

### Transpose

Read more about [matrix transpose](https://en.wikipedia.org/wiki/Transpose).

```
$matrix->transpose();
// return new Matrix 
```

### Multiply

Multiply Matrix by another Matrix.

```
$matrix1 = new Matrix([
    [1, 2, 3],
    [4, 5, 6],
]);

$matrix2 = new Matrix([
    [7, 8],
    [9, 10],
    [11, 12],
]);

$matrix1->multiply($matrix2);

// result $product = [
//  [58, 64],
//  [139, 154],
//];
```

### Divide by scalar

You can divide Matrix by scalar value.

```
$matrix->divideByScalar(2);
```

### Inverse

Read more about [invertible matrix](https://en.wikipedia.org/wiki/Invertible_matrix)

```
$matrix = new Matrix([
    [3, 4, 2],
    [4, 5, 5],
    [1, 1, 1],
]);

$matrix->inverse();

// result $inverseMatrix = [
//    [0, -1, 5],
//    [1 / 2, 1 / 2, -7 / 2],
//    [-1 / 2, 1 / 2, -1 / 2],
//];

```

### Cross out

Cross out given row and column from Matrix.

```
$matrix = new Matrix([
    [3, 4, 2],
    [4, 5, 5],
    [1, 1, 1],
]);

$matrix->crossOut(1, 1)

// result $crossOuted = [
//    [3, 2],
//    [1, 1],
//];
```
