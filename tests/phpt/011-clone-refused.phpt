--TEST--
Cloning an index is refused by the engine instead of double-freeing the handle.
--FILE--
<?php

use Usearch\Index;

$idx = new Index(['dimensions' => 2]);
$idx->add(1, [1.0, 2.0]);

try {
    $copy = clone $idx;
    echo "FAIL: clone did not throw\n";
    unset($copy);
} catch (Throwable $e) {
    echo "PASS: " . get_class($e) . ": " . $e->getMessage() . "\n";
}

/* The original must still work after the refused clone. */
var_dump($idx->size());
var_dump($idx->get(1));

/* And the index stays usable through save/load afterwards. */
$path = tempnam(sys_get_temp_dir(), 'usearch-clone-');
$idx->save($path);
$reloaded = new Index(['dimensions' => 2]);
$reloaded->load($path);
var_dump($reloaded->size());
unlink($path);
?>
--EXPECTF--
PASS: Error: Trying to clone an uncloneable object of class Usearch\Index
int(1)
array(2) {
  [0]=>
  float(1)
  [1]=>
  float(2)
}
int(1)
