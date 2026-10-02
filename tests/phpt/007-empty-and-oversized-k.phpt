--TEST--
Empty index search is empty, and oversized k clamps to the index size.
--FILE--
<?php

use Usearch\Index;

$idx = new Index(['dimensions' => 3]);

/* Searching a never-populated index yields no rows and no error. */
var_dump($idx->search([1.0, 0.0, 0.0]));
var_dump($idx->search(pack('f3', 1.0, 0.0, 0.0)));

/* Non-positive counts are rejected up front. */
foreach ([0, -1] as $k) {
    try {
        $idx->search([1.0, 0.0, 0.0], $k);
        echo "FAIL: k={$k} did not throw\n";
    } catch (ValueError $e) {
        echo "PASS: " . $e->getMessage() . "\n";
    }
}

/* After populating, k larger than size returns exactly size rows. */
$idx->reserve(3);
for ($i = 0; $i < 3; $i++) {
    $idx->add($i, [(float) $i, 0.0, 0.0]);
}

var_dump(count($idx->search([1.0, 0.0, 0.0], 100000)));
var_dump(count($idx->search([1.0, 0.0, 0.0], PHP_INT_MAX)));
var_dump(count($idx->search([1.0, 0.0, 0.0], 2)));

/* And the empty case stays safe after a clear(). */
$idx->clear();
var_dump($idx->size());
var_dump(count($idx->search([1.0, 0.0, 0.0], 10)));
?>
--EXPECTF--
array(0) {
}
array(0) {
}
PASS: count must be a positive integer
PASS: count must be a positive integer
int(3)
int(3)
int(2)
int(0)
int(0)
