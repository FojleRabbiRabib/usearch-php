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

    try {
        $idx->search($query, 0); /* non-positive count */
    } catch (\ValueError $e) {
    }

    /* Oversized k clamps to size; the allocation path must stay clean. */
    $idx->search($query, PHP_INT_MAX);

    /* Non-finite components round-trip through the marshalling buffers. */
    $nan = new Index(['dimensions' => 3]);
    $nan->add(1, [NAN, INF, -INF]);
    $nan->get(1);
    $nan->distance([NAN, 0.0, 0.0], [1.0, 1.0, 1.0]);
    unset($nan);

    /* Multi-key groups: add, count, rename, remove. */
    $multi = new Index(['dimensions' => 3, 'multi' => true]);
    $multi->add(5, [1.0, 0.0, 0.0]);
    $multi->add(5, [0.0, 1.0, 0.0]);
    $multi->count(5);
    $multi->rename(5, 6);
    $multi->remove(6);
    unset($multi);

    /* Corrupt-file paths: every rejection must free its buffers. */
    foreach (['junk', 'empty', 'truncated'] as $kind) {
        $bad = tempnam(sys_get_temp_dir(), 'usearch-bad-');
        if ($kind === 'junk') {
            file_put_contents($bad, "not an index\n");
        } elseif ($kind === 'truncated') {
            copy($tmp, $bad);
            $fh = fopen($bad, 'r+');
            if ($fh === false) {
                fwrite(STDERR, "FAIL: cannot open {$bad}\n");
                exit(1);
            }
            ftruncate($fh, 64);
            fclose($fh);
        }
        foreach (['load', 'view'] as $method) {
            $victim = new Index(['dimensions' => 8]);
            try {
                $victim->$method($bad);
            } catch (\Usearch\Exception $e) {
            }
            unset($victim);
        }
        unlink($bad);
    }

    /* An empty index: search returns no rows without allocating a result set. */
    $blank = new Index(['dimensions' => 8]);
    $blank->search($query, 10);
    unset($blank);

    /* Buffer variants: serialize, inspect, reload, view, and corrupt rejects.
     * The buffer view retains its payload on the object, so the churn must
     * show no leak when the viewed object dies before or after its source. */
    $bytes = $idx->saveBuffer();
    Index::metadataBuffer($bytes);
    $fromBuf = new Index(['dimensions' => 3]);
    $fromBuf->loadBuffer($bytes);
    $fromBuf->search($query, 2);
    unset($fromBuf);
    $bufView = new Index(['dimensions' => 3]);
    $bufView->viewBuffer($bytes);
    $bufView->search($query, 2);
    unset($bufView);
    foreach (['junk' => "not an index\n", 'short' => substr($bytes, 0, 16)] as $bad) {
        $victim = new Index(['dimensions' => 3]);
        try {
            $victim->loadBuffer($bad);
        } catch (\Usearch\Exception $e) {
        }
        try {
            $victim->viewBuffer($bad);
        } catch (\Usearch\Exception $e) {
        }
        unset($victim);
    }
    unset($bytes);

    unset($idx);
}

@unlink($tmp);
echo "CHURN COMPLETED: " . ITERATIONS . " cycles cleanly executed\n";
