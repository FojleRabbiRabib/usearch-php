--TEST--
In-memory buffer variants agree byte-for-byte with the file paths.
--FILE--
<?php

use Usearch\Index;

$idx = new Index(['dimensions' => 3]);
$idx->reserve(4);
for ($i = 0; $i < 4; $i++) {
    $idx->add($i + 1, [$i + 1.0, 0.5, -0.5]);
}

/* 1. saveBuffer() returns exactly the bytes save() writes. */
$bytes = $idx->saveBuffer();
$path = tempnam(sys_get_temp_dir(), 'usearch-buf-');
$idx->save($path);
var_dump($bytes === file_get_contents($path));
var_dump(strlen($bytes) === $idx->serializedLength());

/* 2. metadataBuffer() reads the same option set as metadata(). */
var_dump(Index::metadataBuffer($bytes) === Index::metadata($path));

/* 3. loadBuffer() restores a working index: size, retrieval, search parity. */
$restored = new Index(['dimensions' => 3]);
$restored->loadBuffer($bytes);
var_dump($restored->size());
var_dump($restored->get(2));
var_dump($restored->search([1.0, 0.5, -0.5], 1)[0]['key'] === $idx->search([1.0, 0.5, -0.5], 1)[0]['key']);

/* 4. viewBuffer() searches, and rejects mutation. */
$view = new Index(['dimensions' => 3]);
$view->viewBuffer($bytes);
var_dump($view->size());
try {
    $view->add(9, [1.0, 1.0, 1.0]);
    echo "FAIL: add on a buffer view did not throw\n";
} catch (Usearch\Exception $e) {
    echo "PASS: buffer view is read-only\n";
}

/* 5. The view keeps its own reference: unsetting the caller's bytes
 * must not invalidate the view. */
unset($bytes);
var_dump(count($view->search([1.0, 0.5, -0.5], 2)));

/* 6. Corrupt and truncated buffers are rejected. */
foreach ([
    'junk' => "this is not an index\n",
    'truncated' => substr(file_get_contents($path), 0, 64),
] as $label => $bad) {
    $victim = new Index(['dimensions' => 3]);
    try {
        $victim->loadBuffer($bad);
        echo "FAIL: loadBuffer({$label}) did not throw\n";
    } catch (Usearch\Exception $e) {
        echo "PASS: loadBuffer({$label}) rejected\n";
    }
    try {
        $victim->viewBuffer($bad);
        echo "FAIL: viewBuffer({$label}) did not throw\n";
    } catch (Usearch\Exception $e) {
        echo "PASS: viewBuffer({$label}) rejected\n";
    }
}

unlink($path);
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
int(4)
array(3) {
  [0]=>
  float(2)
  [1]=>
  float(0.5)
  [2]=>
  float(-0.5)
}
bool(true)
int(4)
PASS: buffer view is read-only
int(2)
PASS: loadBuffer(junk) rejected
PASS: viewBuffer(junk) rejected
PASS: loadBuffer(truncated) rejected
PASS: viewBuffer(truncated) rejected
