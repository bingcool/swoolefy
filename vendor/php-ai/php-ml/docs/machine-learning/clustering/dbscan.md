# DBSCAN clustering

It is a density-based clustering algorithm: given a set of points in some space, it groups together points that are closely packed together (points with many nearby neighbors), marking as outliers points that lie alone in low-density regions (whose nearest neighbors are too far away). DBSCAN is one of the most common clustering algorithms and also most cited in scientific literature.
*(source: wikipedia)*

### Constructor Parameters

* $epsilon - epsilon, maximum distance between two samples for them to be considered as in the same neighborhood
* $minSamples - number of samples in a neighborhood for a point to be considered as a core point (this includes the point itself)
* $distanceMetric - Distance object, default Euclidean (see [distance documentation](../../math/distance.md))

```
$dbscan = new DBSCAN($epsilon = 2, $minSamples = 3);
$dbscan = new DBSCAN($epsilon = 2, $minSamples = 3, new Minkowski($lambda=4));
```

### Clustering

To divide the samples into clusters simply use `cluster` method. It's return the `array` of clusters with samples inside.

```
$samples = [[1, 1], [8, 7], [1, 2], [7, 8], [2, 1], [8, 9]];

$dbscan = new DBSCAN($epsilon = 2, $minSamples = 3);
$dbscan->cluster($samples);
// return [0=>[[1, 1], ...], 1=>[[8, 7], ...]] 
```
