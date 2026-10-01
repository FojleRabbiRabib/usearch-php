--TEST--
Add vectors and retrieve nearest neighbours, with key and distance checks.
--FILE--
<?php

use Usearch\Index;

$idx = new Index(['dimensions' => 3, 'metric' => Usearch\Metric::L2sq]);
$idx->reserve(4);
var_dump($idx->add(10, [1.0, 0.0, 0.0]));
var_dump($idx->add(20, [0.0, 1.0, 0.0]));
var_dump($idx->add(30, [0.0, 0.0, 1.0]));
var_dump($idx->size());

$rows = $idx->search([1.0, 0.0, 0.0], 2);
var_dump(count($rows));
var_dump($rows[0]['key']);
var_dump($rows[0]['distance'] < $rows[1]['distance']);

/* Packed input takes the zero-parse path; results must agree with the array form. */
$packed = pack('f3', 1.0, 0.0, 0.0);
$packedRows = $idx->search($packed, 1);
var_dump($packedRows[0]['key'] === $rows[0]['key']);

/* Distance between two vectors under the index's metric. */
var_dump(is_float($idx->distance([1.0, 0.0, 0.0], [0.0, 1.0, 0.0])));

/* Exact retrieval of a stored vector. */
var_dump($idx->get(20));
?>
--EXPECTF--
NULL
NULL
NULL
int(3)
int(2)
int(10)
bool(true)
bool(true)
bool(true)
array(3) {
  [0]=>
  float(0)
  [1]=>
  float(1)
  [2]=>
  float(0)
}
