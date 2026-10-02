--TEST--
A multi index stores several vectors per key; count and remove operate on all.
--FILE--
<?php

use Usearch\Index;

$idx = new Index(['dimensions' => 3, 'multi' => true]);

/* Three vectors under one key. */
var_dump($idx->add(7, [1.0, 0.0, 0.0]));
var_dump($idx->add(7, [0.0, 1.0, 0.0]));
var_dump($idx->add(7, [0.0, 0.0, 1.0]));
var_dump($idx->size());
var_dump($idx->count(7));

/* A single-key index would have overwritten: here all three are retrievable. */
var_dump($idx->contains(7));
$rows = $idx->search([1.0, 0.0, 0.0], 10);
var_dump(count($rows));
foreach ($rows as $row) {
    var_dump($row['key']);
}

/* remove() drops every copy of the key. */
var_dump($idx->remove(7));
var_dump($idx->size());
var_dump($idx->count(7));
var_dump($idx->contains(7));

/* And rename on a multi key moves the whole group. */
$idx->add(1, [1.0, 0.0, 0.0]);
$idx->add(1, [0.0, 1.0, 0.0]);
var_dump($idx->rename(1, 2));
var_dump($idx->contains(1));
var_dump($idx->count(2));
?>
--EXPECTF--
NULL
NULL
NULL
int(3)
int(3)
bool(true)
int(3)
int(7)
int(7)
int(7)
int(3)
int(0)
int(0)
bool(false)
int(2)
bool(false)
int(2)
