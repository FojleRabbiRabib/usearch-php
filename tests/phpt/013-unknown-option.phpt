--TEST--
Unknown and malformed constructor options are rejected before any index is built.
--FILE--
<?php

use Usearch\Index;

/* A typo'd key must be an error, not a silently ignored option: the caller
 * believes they tuned a knob they did not. */
try {
    new Index(['dimentions' => 3]);
    echo "FAIL: typo'd key did not throw\n";
} catch (ValueError $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}

/* Positional entries have no option name to match. */
try {
    new Index([3]);
    echo "FAIL: list-shaped options did not throw\n";
} catch (ValueError $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}

/* The unknown key must be named, so the typo is findable. */
try {
    new Index(['connectivty' => 8]);
    echo "FAIL: unnamed error\n";
} catch (ValueError $e) {
    echo "PASS: names the key: " . var_export(str_contains($e->getMessage(), 'connectivty'), true) . "\n";
}

/* Wrong-typed values for known keys are rejected too. */
try {
    new Index(['dimensions' => 'three']);
    echo "FAIL: string dimensions did not throw\n";
} catch (ValueError $e) {
    echo "PASS: " . $e->getMessage() . "\n";
}

/* And a fully valid call still works after all of that. */
$idx = new Index(['dimensions' => 2]);
var_dump($idx->dimensions());
?>
--EXPECTF--
PASS: unknown option 'dimentions'
PASS: options must be an associative array
PASS: names the key: true
PASS: dimensions must be a positive integer
int(2)
