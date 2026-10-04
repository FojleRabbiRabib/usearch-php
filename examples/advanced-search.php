<?php

/**
 * examples/advanced-search.php — filtered search, custom metrics, exact search.
 *
 * Self-verifying: the predicate filter must return exactly the surviving
 * keys, the custom metric must agree with hand arithmetic, and exactSearch()
 * must report the true brute-force winner and its distance.
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

use Usearch\Index;
use Usearch\Metric;

/* Filtered search: only keys the predicate accepts are returned. */
$index = new Index(['dimensions' => 2, 'metric' => Metric::L2sq]);
foreach ([1, 2, 3, 4, 5, 6] as $key) {
    $index->add($key, [(float) $key, 0.0]);
}
$even = $index->filteredSearch([0.0, 0.0], fn (int $key): bool => $key % 2 === 0, 10);
if (array_column($even, 'key') !== [2, 4, 6]) {
    throw new RuntimeException('expected even keys [2, 4, 6], got ' . json_encode(array_column($even, 'key')));
}

/* A custom metric: L1 over the stored-format bytes, cross-checked. */
$l1 = function (string $a, string $b): float {
    $x = unpack('g2', $a);
    $y = unpack('g2', $b);
    if ($x === false || $y === false) {
        throw new RuntimeException('malformed vector bytes reached the custom metric');
    }
    return abs($x[1] - $y[1]) + abs($x[2] - $y[2]);
};
$index->changeMetric($l1);
$want = abs(1.0 - 4.0) + abs(2.0 - 6.0);
$got = $index->distance([1.0, 2.0], [4.0, 6.0]);
if (abs($got - $want) > 1e-9) {
    throw new RuntimeException("custom metric reported {$got}, expected {$want}");
}

/* Exact search: the true brute-force top-k over any dataset, no index.
 * L2sq from [2, 0]: key 0 -> 1, key 1 -> 5, key 2 -> 17. */
$corpus = [[1.0, 0.0], [0.0, 1.0], [3.0, 4.0]];
$truth = Index::exactSearch($corpus, [2.0, 0.0], Metric::L2sq, 2);
if ($truth[0]['key'] !== 0 || abs($truth[0]['distance'] - 1.0) > 1e-9) {
    throw new RuntimeException('exact search missed the true nearest neighbour');
}
if ($truth[1]['key'] !== 1) {
    throw new RuntimeException('exact search mis-ordered the runner-up');
}

echo 'SUCCESS: advanced search example executed cleanly.' . PHP_EOL;
