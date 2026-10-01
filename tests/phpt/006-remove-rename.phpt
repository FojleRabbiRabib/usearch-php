--TEST--
Key operations: count, contains, rename, remove, clear.
--FILE--
<?php

use Usearch\Index;

$idx = new Index(['dimensions' => 2]);
$idx->add(10, [1.0, 1.0]);
$idx->add(20, [2.0, 2.0]);

var_dump($idx->contains(10));
var_dump($idx->contains(99));
var_dump($idx->count(10));

/* Rename 10 to 15. */
var_dump($idx->rename(10, 15));
var_dump($idx->contains(10));
var_dump($idx->contains(15));

/* Remove 20. */
var_dump($idx->remove(20));
var_dump($idx->contains(20));
var_dump($idx->size());

/* Clear everything. */
$idx->clear();
var_dump($idx->size());
?>
--EXPECTF--
bool(true)
bool(false)
int(1)
int(1)
bool(false)
bool(true)
int(1)
bool(false)
int(1)
int(0)
