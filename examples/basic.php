<?php

/**
 * examples/basic.php — runnable, self-verifying introduction to usearch-php.
 *
 * Demonstrates creating a dense vector index, inserting embeddings, searching
 * for nearest neighbours by Cosine distance, recovering stored vectors, and
 * persisting to disk.
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

use Usearch\Index;
use Usearch\Metric;
use Usearch\Scalar;

echo "usearch-php version: " . Index::version() . "\n";
echo "Hardware acceleration: " . Index::hardwareAccelerationCompiled() . " (compiled), "
    . Index::hardwareAccelerationAvailable() . " (host available)\n";

/* Create an index for 4-dimensional embeddings, using Cosine distance. */
$index = new Index([
    'dimensions' => 4,
    'metric' => Metric::Cosine,
    'quantization' => Scalar::F32,
    'connectivity' => 16,
    'expansionAdd' => 64,
    'expansionSearch' => 32,
]);

/* Insert sample document embeddings. */
$documents = [
    101 => [0.9, 0.1, 0.0, 0.0], /* Topic: AI */
    102 => [0.8, 0.2, 0.1, 0.0], /* Topic: Machine Learning */
    201 => [0.0, 0.0, 0.9, 0.1], /* Topic: Gardening */
    202 => [0.1, 0.0, 0.8, 0.2], /* Topic: Botany */
];

foreach ($documents as $id => $vec) {
    $index->add($id, $vec);
}

echo "Indexed " . $index->size() . " documents. Memory usage: " . $index->memoryUsage() . " bytes\n\n";

/* Query for AI-like documents. */
$query = [1.0, 0.0, 0.0, 0.0];
$results = $index->search($query, 2);

echo "Query: [1.0, 0.0, 0.0, 0.0]\nTop 2 matches:\n";
foreach ($results as $match) {
    printf("  - doc_id=%d  distance=%.4f\n", $match['key'], $match['distance']);
}

/* Assertions for self-verifying example contract. */
if ($results[0]['key'] !== 101) {
    throw new RuntimeException("expected top match to be doc 101, got " . $results[0]['key']);
}
if ($results[1]['key'] !== 102) {
    throw new RuntimeException("expected second match to be doc 102, got " . $results[1]['key']);
}

/* Persist to disk and reload. */
$tmp = tempnam(sys_get_temp_dir(), 'usearch-example-');
$index->save($tmp);

$reloaded = new Index(['dimensions' => 4]);
$reloaded->load($tmp);
if ($reloaded->size() !== 4) {
    throw new RuntimeException("expected reloaded size 4, got " . $reloaded->size());
}
@unlink($tmp);

echo "\nSUCCESS: basic vector search example executed cleanly.\n";
