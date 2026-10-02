--TEST--
NaN and infinite components are accepted and round-trip unchanged.
--FILE--
<?php

use Usearch\Index;

$idx = new Index(['dimensions' => 3, 'metric' => Usearch\Metric::L2sq]);

/* Non-finite input is legal: it passes through the marshalling layer
 * unchanged rather than being silently sanitised. */
$idx->add(1, [NAN, INF, -INF]);
var_dump($idx->size());

$back = $idx->get(1);
var_dump(is_nan($back[0]));
var_dump($back[1] === INF);
var_dump($back[2] === -INF);

/* A finite add next to a non-finite one is unaffected. */
$idx->add(2, [1.0, 2.0, 3.0]);
var_dump($idx->get(2));

/* Searching still returns rows; the non-finite vector is simply farthest. */
$rows = $idx->search([1.0, 2.0, 3.0], 2);
var_dump(count($rows));
var_dump($rows[0]['key']);

/* distance() with a non-finite operand must not fault. */
var_dump(is_float($idx->distance([NAN, 0.0, 0.0], [1.0, 1.0, 1.0])));
?>
--EXPECTF--
int(1)
bool(true)
bool(true)
bool(true)
array(3) {
  [0]=>
  float(1)
  [1]=>
  float(2)
  [2]=>
  float(3)
}
int(2)
int(2)
bool(true)
