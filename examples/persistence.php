<?php

/**
 * examples/persistence.php — the in-memory buffer twins of save/load/view.
 *
 * Self-verifying: saveBuffer() must return exactly the bytes save() writes,
 * a loadBuffer() round-trip must answer identically, a buffer view must stay
 * readable and read-only even after the caller's bytes are gone, and
 * metadataBuffer() must agree with metadata().
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

use Usearch\Index;

$index = new Index(['dimensions' => 2]);
for ($i = 1; $i <= 4; $i++) {
    $index->add($i, [(float) $i, 0.5]);
}

$bytes = $index->saveBuffer();

$path = tempnam(sys_get_temp_dir(), 'usearch-persist-');
$index->save($path);
if ($bytes !== file_get_contents($path)) {
    throw new RuntimeException('saveBuffer() returned different bytes than save() wrote');
}
if (Index::metadataBuffer($bytes) !== Index::metadata($path)) {
    throw new RuntimeException('metadataBuffer() disagrees with metadata()');
}

$copy = new Index(['dimensions' => 2]);
$copy->loadBuffer($bytes);
if ($copy->size() !== 4) {
    throw new RuntimeException('expected 4 vectors after loadBuffer(), got ' . $copy->size());
}

$view = new Index(['dimensions' => 2]);
$view->viewBuffer($bytes);
unset($bytes); /* the view retains its own payload */
if (count($view->search([1.0, 0.5], 4)) !== 4) {
    throw new RuntimeException('buffer view lost its rows after the source bytes were unset');
}
try {
    $view->add(9, [1.0, 1.0]);
    throw new RuntimeException('a buffer view accepted a mutation');
} catch (Usearch\Exception $e) {
    /* expected: the view is read-only */
}

unlink($path);
echo 'SUCCESS: buffer persistence example executed cleanly.' . PHP_EOL;
