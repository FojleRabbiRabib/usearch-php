<?php

/**
 * tests/memory/churn.php — Valgrind leak-gate driver for usearch-php.
 *
 * Runs thousands of complete index lifecycles (create, add, search, save,
 * load, view, destroy) plus error paths and vector-marshalling conversions.
 * Valgrind must report 0 definite and 0 indirect leaks for this to pass.
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

use Usearch\Index;
use Usearch\Metric;
use Usearch\Scalar;

$tmp = tempnam(sys_get_temp_dir(), 'usearch-churn-');
if ($tmp === false) {
    fwrite(STDERR, "FAIL: tempnam failed\n");
    exit(1);
}

const ITERATIONS = 200;

for ($i = 0; $i < ITERATIONS; $i++) {
    $idx = new Index([
        'dimensions' => 8,
        'metric' => Metric::Cosine,
        'quantization' => Scalar::F32,
        'connectivity' => 16,
        'expansionAdd' => 32,
        'expansionSearch' => 16,
    ]);

    /* Add a small batch. */
    for ($k = 0; $k < 10; $k++) {
        $vec = array_fill(0, 8, (float) ($k + $i * 0.01));
        $idx->add($k, $vec);
    }

    /* Search with array and packed forms. */
    $query = array_fill(0, 8, 1.0);
    $idx->search($query, 5);

    $packed = pack('f8', 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0);
    $idx->search($packed, 5);

    /* Exact fetch. */
    $idx->get(0);
    $idx->get(999); /* absent */

    /* Save and reload. */
    $idx->save($tmp);
    $reloaded = new Index(['dimensions' => 8]);
    $reloaded->load($tmp);
    $reloaded->search($query, 3);
    unset($reloaded);

    /* View and search. */
    $view = new Index(['dimensions' => 8]);
    $view->view($tmp);
    $view->search($query, 3);
    unset($view);

    /* Error path churn: must not leak temporary buffers. */
    try {
        $idx->add(99, [1.0, 2.0]); /* dimension mismatch */
    } catch (\ValueError $e) {
    }

    try {
        new Index(['dimensions' => 0]);
    } catch (\ValueError $e) {
    }

    unset($idx);
}

@unlink($tmp);
echo "CHURN COMPLETED: " . ITERATIONS . " cycles cleanly executed\n";
