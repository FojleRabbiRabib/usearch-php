--TEST--
Index construction, introspection accessors, and static diagnostics.
--FILE--
<?php

use Usearch\Index;
use Usearch\Metric;
use Usearch\Scalar;

$idx = new Index(['dimensions' => 4]);
var_dump($idx->size());
var_dump($idx->capacity() >= 0);
var_dump($idx->dimensions());
var_dump($idx->connectivity() > 0);
var_dump($idx->expansionAdd() > 0);
var_dump($idx->expansionSearch() > 0);
var_dump(is_string($idx->hardwareAcceleration()));
var_dump(Metric::Cosine->value);
var_dump(Scalar::F32->value);
var_dump(is_string(Index::version()));
var_dump(is_string(Index::hardwareAccelerationCompiled()));
var_dump(is_string(Index::hardwareAccelerationAvailable()));
?>
--EXPECTF--
int(0)
bool(true)
int(4)
bool(true)
bool(true)
bool(true)
bool(true)
int(1)
int(1)
bool(true)
bool(true)
bool(true)
