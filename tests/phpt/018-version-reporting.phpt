--TEST--
version() reports the vendored pin the linked build actually carries.
--FILE--
<?php

use Usearch\Index;

$v = Index::version();
var_dump($v);

/* The runtime report must contain the exact release tools/fetch-usearch.sh
 * pins; a stale pin rebuilt against a newer tarball must be visible here. */
var_dump(str_contains($v, '+usearch.2.26.2'));
var_dump(str_starts_with($v, '0.1.0'));
?>
--EXPECTF--
string(%d) "%s"
bool(true)
bool(true)
