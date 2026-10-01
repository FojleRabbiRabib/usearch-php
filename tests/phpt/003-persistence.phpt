--TEST--
Index serialization: save() to disk and reload via load().
--FILE--
<?php

use Usearch\Index;

$tmp = tempnam(sys_get_temp_dir(), 'usearch-test-');

$idx = new Index(['dimensions' => 2]);
$idx->add(1, [1.0, 2.0]);
$idx->add(2, [3.0, 4.0]);
$idx->save($tmp);

$reloaded = new Index(['dimensions' => 2]);
$reloaded->load($tmp);

var_dump($reloaded->size());
var_dump($reloaded->contains(1));
var_dump($reloaded->contains(2));

$rows = $reloaded->search([1.0, 2.0], 1);
var_dump($rows[0]['key']);

$meta = Index::metadata($tmp);
var_dump($meta['dimensions']);
/* The file header stores metric, quantization, dimensions and multi only;
 * upstream reports connectivity/expansion as 0 by construction, so assert on
 * the fields the format actually carries. */
var_dump($meta['metric']);
var_dump($meta['quantization']);

unlink($tmp);
?>
--EXPECTF--
int(2)
bool(true)
bool(true)
int(1)
int(2)
int(1)
int(1)
