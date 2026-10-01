--TEST--
Memory-mapped view mode is read-only; mutations raise Usearch\Exception.
--FILE--
<?php

use Usearch\Index;
use Usearch\Exception as UsearchException;

$tmp = tempnam(sys_get_temp_dir(), 'usearch-test-');

$idx = new Index(['dimensions' => 2]);
$idx->add(1, [0.5, 0.5]);
$idx->save($tmp);

$view = new Index(['dimensions' => 2]);
$view->view($tmp);
var_dump($view->size());

/* Search works cleanly through mmap. */
$rows = $view->search([0.5, 0.5], 1);
var_dump($rows[0]['key']);

/* Mutations raise before touching mmap, converting SIGSEGV into an exception. */
try {
	$view->add(2, [1.0, 1.0]);
	echo "FAIL: add did not throw\n";
} catch (UsearchException $e) {
	echo "PASS: add threw: " . $e->getMessage() . "\n";
}

try {
	$view->remove(1);
	echo "FAIL: remove did not throw\n";
} catch (UsearchException $e) {
	echo "PASS: remove threw: " . $e->getMessage() . "\n";
}

try {
	$view->clear();
	echo "FAIL: clear did not throw\n";
} catch (UsearchException $e) {
	echo "PASS: clear threw: " . $e->getMessage() . "\n";
}

unlink($tmp);
?>
--EXPECTF--
int(1)
int(1)
PASS: add threw: cannot modify a memory-mapped read-only index view
PASS: remove threw: cannot modify a memory-mapped read-only index view
PASS: clear threw: cannot modify a memory-mapped read-only index view
