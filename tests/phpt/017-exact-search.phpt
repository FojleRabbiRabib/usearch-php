--TEST--
exactSearch returns the true brute-force top-k over a caller-supplied dataset.
--FILE--
<?php

use Usearch\Index;
use Usearch\Metric;

/* Hand-computed fixture. L2sq from [2, 0]:
 *   key 0 [1, 0] -> 1, key 1 [0, 1] -> 5, key 2 [3, 4] -> 17, key 3 [0, 0] -> 4. */
$vectors = [[1.0, 0.0], [0.0, 1.0], [3.0, 4.0], [0.0, 0.0]];

$rows = Index::exactSearch($vectors, [2.0, 0.0], Metric::L2sq, 3);
var_dump(array_column($rows, 'key'));
var_dump(array_column($rows, 'distance'));

/* Packed strings and arrays mix on the same width. */
$packed = Index::exactSearch(
    [pack('f2', 1.0, 0.0), [0.0, 1.0], pack('f2', 3.0, 4.0), [0.0, 0.0]],
    pack('f2', 2.0, 0.0),
    Metric::L2sq,
    4
);
var_dump(array_column($packed, 'key'));

/* Cosine over the same set: query [2, 0] points straight at key 0. */
var_dump(Index::exactSearch($vectors, [2.0, 0.0], Metric::Cosine, 1)[0]['key']);

/* Count larger than the dataset clamps; the empty dataset answers empty. */
var_dump(count(Index::exactSearch($vectors, [2.0, 0.0], Metric::L2sq, 100)));
var_dump(Index::exactSearch([], [1.0], Metric::L2sq, 10));

/* A keyed array is not a dataset. */
try {
    Index::exactSearch(['a' => [1.0, 0.0]], [2.0, 0.0], Metric::L2sq);
    echo "FAIL: keyed vectors did not throw\n";
} catch (ValueError $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}

/* Ragged widths fail loudly. */
try {
    Index::exactSearch([[1.0, 0.0], [1.0]], [2.0, 0.0], Metric::L2sq);
    echo "FAIL: ragged vectors did not throw\n";
} catch (ValueError $e) {
    echo "PASS: dimension mismatch raises\n";
}

/* Ground-truth parity: exact top-1 agrees with the graph's answer here. */
$idx = new Index(['dimensions' => 2, 'metric' => Metric::L2sq]);
foreach ($vectors as $i => $v) {
    $idx->add($i, $v);
}
var_dump($idx->search([2.0, 0.0], 1)[0]['key'] === Index::exactSearch($vectors, [2.0, 0.0], Metric::L2sq, 1)[0]['key']);

/* Non-positive counts are rejected. */
try {
    Index::exactSearch($vectors, [2.0, 0.0], Metric::L2sq, 0);
    echo "FAIL: k=0 did not throw\n";
} catch (ValueError $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}
?>
--EXPECTF--
array(3) {
  [0]=>
  int(0)
  [1]=>
  int(3)
  [2]=>
  int(1)
}
array(3) {
  [0]=>
  float(1)
  [1]=>
  float(4)
  [2]=>
  float(5)
}
array(4) {
  [0]=>
  int(0)
  [1]=>
  int(3)
  [2]=>
  int(1)
  [3]=>
  int(2)
}
int(0)
int(4)
array(0) {
}
PASS: vectors must be a list of arrays or packed strings with consecutive 0-based keys
PASS: dimension mismatch raises
bool(true)
PASS: count must be a positive integer
