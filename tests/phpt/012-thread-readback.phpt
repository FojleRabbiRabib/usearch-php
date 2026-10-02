--TEST--
Thread readback describes this object's settings, and resets when load/view replace the handle.
--FILE--
<?php

use Usearch\Index;

$path = tempnam(sys_get_temp_dir(), 'usearch-threads-');

$idx = new Index(['dimensions' => 2]);
$idx->add(1, [1.0, 0.0]);

/* A fresh index reports 0 for both: "unset by this object". */
var_dump($idx->threadsAdd());
var_dump($idx->threadsSearch());

/* The setters read back what was set. */
$idx->threadsAdd(4);
$idx->threadsSearch(3);
var_dump($idx->threadsAdd());
var_dump($idx->threadsSearch());

$idx->save($path);

/* load() swaps in a handle whose budgets came from the file, which this
 * object never set — so the readback resets rather than reporting the stale 4
 * for an index it does not describe. */
$idx->load($path);
var_dump($idx->threadsAdd());
var_dump($idx->threadsSearch());

/* Setting again after the load is reflected again. */
$idx->threadsAdd(7);
var_dump($idx->threadsAdd());

/* Same reset on view(), and the view stays read-only afterwards. */
$view = new Index(['dimensions' => 2]);
$view->threadsAdd(5);
$view->view($path);
var_dump($view->threadsAdd());
var_dump($view->threadsSearch());

try {
    $view->threadsAdd(5);
    echo "FAIL: threadsAdd on a view did not throw\n";
} catch (Usearch\Exception $e) {
    echo "PASS: view is still read-only\n";
}

unlink($path);
?>
--EXPECTF--
int(0)
int(0)
int(4)
int(3)
int(0)
int(0)
int(7)
int(0)
int(0)
PASS: view is still read-only
