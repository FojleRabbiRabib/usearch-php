<?php

/**
 * tests/quality/recall.php — recall@k gate over Fashion-MNIST.
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 *
 * Builds a deterministic, single-threaded HNSW index over the first BUILD
 * vectors of the Fashion-MNIST training split (784-dim, scaled to [0,1]),
 * then measures the overlap between the index's top-k and the exact
 * brute-force top-k for QUERY vectors of the test split. The gate is
 * recall@10 >= RECALL_THRESHOLD.
 *
 * Ground truth is computed with the extension's own distance() over packed
 * vectors, so this gate isolates HNSW graph quality: it would catch a search
 * regression (approximation collapsing, a broken traversal) without double
 * counting metric-correctness, which the invariant phpt cases pin. The build
 * is pinned to one add thread because multi-threaded insertion order changes
 * the graph, and a recall number that drifts run to run is not a gate.
 *
 * Usage: php -n -d extension=modules/usearch.so tests/quality/recall.php
 * Requires tools/fetch-dataset.sh to have run.
 */

declare(strict_types=1);

use Usearch\Index;
use Usearch\Metric;

/* The packed corpus and its ground-truth copies are ordinary PHP strings, so
 * PHP's default 128 MB limit aborts the build long before the index is the
 * constraint. Raise it for this script the way tools/benchmark.php does. */
if ((int) ini_get('memory_limit') !== -1 && ini_get('memory_limit') !== '-1') {
    $limit = ini_get('memory_limit');
    $bytes = match (strtoupper(substr($limit, -1))) {
        'G' => (int) $limit * 1073741824,
        'M' => (int) $limit * 1048576,
        'K' => (int) $limit * 1024,
        default => (int) $limit,
    };
    if ($bytes < 2147483648) {
        ini_set('memory_limit', '2G');
    }
}

/* 30k is the smallest size at which the graph visibly approximates: at 5k the
 * index answers every query exactly and a recall gate could not distinguish a
 * working traversal from a degenerate one. */
const BUILD_COUNT = 30000;
const QUERY_COUNT = 200;
const TOP_K = 10;
const RECALL_THRESHOLD = 0.95;
const DIMS = 784;

/**
 * Decodes the first $count images of an IDX3 file into packed rows of DIMS
 * little-endian float32 values scaled to [0,1].
 *
 * @return list<string>
 */
function readIdxImages(string $path, int $count): array
{
    $fh = gzopen($path, 'rb');
    if ($fh === false) {
        fwrite(STDERR, "FAIL: cannot open {$path}; run tools/fetch-dataset.sh first\n");
        exit(1);
    }
    $header = unpack('N4', (string) gzread($fh, 16));
    if ($header === false || $header[1] !== 2051) {
        fwrite(STDERR, "FAIL: {$path} is not an IDX3 image file\n");
        exit(1);
    }
    $raw = (string) gzread($fh, $count * DIMS);
    gzclose($fh);
    if (strlen($raw) !== $count * DIMS) {
        fwrite(STDERR, "FAIL: {$path} is shorter than the requested {$count} images\n");
        exit(1);
    }

    /* Byte-wise translation at C speed: one byte of grey level maps to its
     * packed float32, so the per-image cost is one strtr() instead of a
     * 784-iteration PHP loop. */
    $table = [];
    for ($level = 0; $level < 256; $level++) {
        $table[chr($level)] = pack('g', $level / 255.0);
    }
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = strtr(substr($raw, $i * DIMS, DIMS), $table);
    }
    return $rows;
}

$datasetDir = dirname(__DIR__, 2) . '/vendor-data/fashion-mnist';
$train = readIdxImages($datasetDir . '/train-images-idx3-ubyte.gz', BUILD_COUNT);
$test = readIdxImages($datasetDir . '/t10k-images-idx3-ubyte.gz', QUERY_COUNT);

$t0 = microtime(true);
$idx = new Index(['dimensions' => DIMS, 'metric' => Metric::L2sq]);
$idx->threadsAdd(1);
$idx->reserve(BUILD_COUNT);
foreach ($train as $key => $packed) {
    $idx->add($key, $packed);
}
$buildSeconds = microtime(true) - $t0;

/* Brute-force ground truth: exact top-k per query over the same packed rows. */
$t0 = microtime(true);
$totalRecall = 0.0;
$worst = 1.0;
foreach ($test as $query) {
    $scores = [];
    foreach ($train as $key => $packed) {
        $scores[$key] = $idx->distance($query, $packed);
    }
    asort($scores);
    $exact = array_slice(array_keys($scores), 0, TOP_K);

    $rows = $idx->search($query, TOP_K);
    $approxKeys = [];
    foreach ($rows as $row) {
        $approxKeys[$row['key']] = true;
    }
    $hits = 0;
    foreach ($exact as $key) {
        if (isset($approxKeys[$key])) {
            $hits++;
        }
    }
    $recall = $hits / TOP_K;
    $totalRecall += $recall;
    $worst = min($worst, $recall);
}
$gtSeconds = microtime(true) - $t0;
$meanRecall = $totalRecall / QUERY_COUNT;

printf(
    "build: %d vectors in %.1fs   ground truth: %d queries in %.1fs\n",
    BUILD_COUNT,
    $buildSeconds,
    QUERY_COUNT,
    $gtSeconds
);
printf(
    "recall@%d: mean %.4f, worst %.4f (threshold %.2f)\n",
    TOP_K,
    $meanRecall,
    $worst,
    RECALL_THRESHOLD
);

if ($meanRecall < RECALL_THRESHOLD) {
    fwrite(STDERR, "FAIL: mean recall@10 {$meanRecall} is below " . RECALL_THRESHOLD . "\n");
    exit(1);
}
echo "RECALL GATE: PASS\n";
