--TEST--
Vector dimension mismatches raise ValueError with expected and got counts.
--FILE--
<?php

use Usearch\Index;

$idx = new Index(['dimensions' => 3]);

try {
	$idx->add(1, [1.0, 2.0]);
	echo "FAIL: add did not throw\n";
} catch (ValueError $e) {
	echo "PASS: " . $e->getMessage() . "\n";
}

try {
	$idx->search([1.0, 2.0, 3.0, 4.0]);
	echo "FAIL: search did not throw\n";
} catch (ValueError $e) {
	echo "PASS: " . $e->getMessage() . "\n";
}

try {
	$idx->add(2, pack('f2', 1.0, 2.0));
	echo "FAIL: packed add did not throw\n";
} catch (ValueError $e) {
	echo "PASS: " . $e->getMessage() . "\n";
}
?>
--EXPECTF--
PASS: vector dimension mismatch: expected 3, got 2
PASS: vector dimension mismatch: expected 3, got 4
PASS: packed vector length mismatch: expected 12 bytes for 3 dimensions, got 8
