<?php

/**
 * tests/quality/interop.php — cross-language index-file interoperability gate.
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 *
 * Verifies bidirectional binary serialization and search compatibility between
 * usearch-php and upstream USearch (C++ and Python):
 *   Pass 1: Upstream C++ generates -> PHP loads & views (keys, distances, get(), view immutability).
 *   Pass 2: PHP generates -> Upstream C++ validates (keys, distances, retrieval).
 *   Pass 3: Buffer variants (saveBuffer, loadBuffer, viewBuffer, metadataBuffer).
 *   Pass 4: Python cross-check (conditional if python3-usearch is present).
 *
 * Usage: php -n -d extension=modules/usearch.so tests/quality/interop.php
 */

declare(strict_types=1);

use Usearch\Index;
use Usearch\Metric;
use Usearch\Scalar;

/**
 * Return all 9 test case configurations matching interop_cpp.cpp.
 *
 * @return list<array{
 *     name: string,
 *     metric: Metric,
 *     scalar: Scalar,
 *     dimensions: int,
 *     count: int,
 *     queries: int,
 *     hasRemovals: bool
 * }>
 */
function getInteropCases(): array
{
    return [
        [
            'name' => 'cos_f32_128',
            'metric' => Metric::Cosine,
            'scalar' => Scalar::F32,
            'dimensions' => 128,
            'count' => 500,
            'queries' => 20,
            'hasRemovals' => false,
        ],
        [
            'name' => 'l2_f32_64',
            'metric' => Metric::L2sq,
            'scalar' => Scalar::F32,
            'dimensions' => 64,
            'count' => 500,
            'queries' => 20,
            'hasRemovals' => false,
        ],
        [
            'name' => 'ip_f32_256',
            'metric' => Metric::Ip,
            'scalar' => Scalar::F32,
            'dimensions' => 256,
            'count' => 500,
            'queries' => 20,
            'hasRemovals' => false,
        ],
        [
            'name' => 'cos_f16_128',
            'metric' => Metric::Cosine,
            'scalar' => Scalar::F16,
            'dimensions' => 128,
            'count' => 500,
            'queries' => 20,
            'hasRemovals' => false,
        ],
        [
            'name' => 'cos_i8_128',
            'metric' => Metric::Cosine,
            'scalar' => Scalar::I8,
            'dimensions' => 128,
            'count' => 500,
            'queries' => 20,
            'hasRemovals' => false,
        ],
        [
            'name' => 'hamming_b1_256',
            'metric' => Metric::Hamming,
            'scalar' => Scalar::B1,
            'dimensions' => 256,
            'count' => 500,
            'queries' => 20,
            'hasRemovals' => false,
        ],
        [
            'name' => 'haversine_f32_2',
            'metric' => Metric::Haversine,
            'scalar' => Scalar::F32,
            'dimensions' => 2,
            'count' => 200,
            'queries' => 10,
            'hasRemovals' => false,
        ],
        [
            'name' => 'lifecycle_tomb',
            'metric' => Metric::Cosine,
            'scalar' => Scalar::F32,
            'dimensions' => 64,
            'count' => 300,
            'queries' => 20,
            'hasRemovals' => true,
        ],
        [
            'name' => 'empty_f32_128',
            'metric' => Metric::Cosine,
            'scalar' => Scalar::F32,
            'dimensions' => 128,
            'count' => 0,
            'queries' => 5,
            'hasRemovals' => false,
        ],
    ];
}

/**
 * Generates deterministic pseudo-random float vector matching interop_cpp.cpp.
 *
 * @return list<float>
 */
function generateVector(int &$state, int $dim, Metric $metric, Scalar $scalar): array
{
    $vec = [];
    for ($d = 0; $d < $dim; ++$d) {
        $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;
        $v = ($state % 10000) / 10000.0;
        if ($metric === Metric::Haversine) {
            $vec[] = $d === 0 ? ($v - 0.5) * 3.141592653589793 : ($v - 0.5) * 6.283185307179586;
        } elseif ($scalar === Scalar::B1 || $metric === Metric::Hamming) {
            $vec[] = $v > 0.5 ? 1.0 : -1.0;
        } else {
            $vec[] = $v;
        }
    }
    return $vec;
}

/**
 * Returns allowed floating point distance tolerance for search assertions.
 */
function allowedTolerance(Scalar $scalar, Metric $metric): float
{
    if ($scalar === Scalar::B1 || $metric === Metric::Hamming) {
        return 0.0;
    }
    if ($scalar === Scalar::I8) {
        return 0.02;
    }
    if ($scalar === Scalar::F16) {
        return 0.005;
    }
    return 0.0002;
}

/**
 * Executes a subprocess with argument-array isolation and validates exit status.
 *
 * @param list<string> $cmd
 */
function runHelper(array $cmd): string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to launch helper process: ' . implode(' ', $cmd));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $status = proc_close($proc);
    if ($status !== 0) {
        throw new RuntimeException(
            "Helper failed with exit code {$status}:\nCommand: " . implode(' ', $cmd) .
            "\nStderr: {$stderr}\nStdout: {$stdout}"
        );
    }

    return (string) $stdout;
}

/**
 * Executes a subprocess with argument-array isolation, returning stdout on success or null on failure.
 *
 * @param list<string> $cmd
 */
function probeCommand(array $cmd): ?string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return null;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $status = proc_close($proc);
    return $status === 0 ? (string) $stdout : null;
}

/**
 * Compiles tests/quality/interop_cpp.cpp inside the temporary workspace directory.
 */
function ensureCppHelper(string $tempDir): string
{
    $src = __DIR__ . '/interop_cpp.cpp';
    $bin = $tempDir . '/interop_cpp';
    $vendored = dirname(__DIR__, 2) . '/vendor-src/USearch-2.26.2/include';

    if (!file_exists($src)) {
        throw new RuntimeException("Missing C++ helper source: {$src}");
    }
    if (!is_dir($vendored)) {
        throw new RuntimeException("Missing vendored headers: {$vendored}. Run tools/fetch-usearch.sh first.");
    }

    $cmd = [
        'g++',
        '-std=c++17',
        '-Wall',
        '-Wextra',
        '-Werror',
        '-O3',
        '-I',
        $vendored,
        $src,
        '-o',
        $bin,
    ];
    runHelper($cmd);

    return $bin;
}

/**
 * Parses line-oriented manifest format.
 *
 * @return array<int, array{keys: list<int>, dists: list<float>}>
 */
function parseManifest(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || count($lines) < 2) {
        throw new RuntimeException("Manifest missing or incomplete: {$path}");
    }

    $queries = [];
    foreach ($lines as $line) {
        if (!str_starts_with($line, 'query_idx')) {
            continue;
        }
        $parts = explode(' ', $line);
        if (count($parts) < 8) {
            continue;
        }
        $qidx = (int) $parts[1];
        $count = (int) $parts[3];
        $keys = $count > 0 && $parts[5] !== '' ? array_map('intval', explode(',', $parts[5])) : [];
        $dists = $count > 0 && $parts[7] !== '' ? array_map('floatval', explode(',', $parts[7])) : [];
        $queries[$qidx] = ['keys' => $keys, 'dists' => $dists];
    }

    return $queries;
}

/**
 * Writes line-oriented manifest format.
 *
 * @param array{
 *     name: string,
 *     metric: Metric,
 *     scalar: Scalar,
 *     dimensions: int,
 *     count: int,
 *     queries: int,
 *     hasRemovals: bool
 * } $case
 * @param array<int, array{keys: list<int>, dists: list<float>}> $queries
 */
function writeManifest(string $path, array $case, int $size, array $queries): void
{
    $out = "case_id={$case['name']}\n";
    $out .= "count={$size} dimensions={$case['dimensions']}\n";
    foreach ($queries as $qidx => $res) {
        $out .= sprintf(
            "query_idx %d count %d keys %s distances %s\n",
            $qidx,
            count($res['keys']),
            implode(',', $res['keys']),
            implode(',', array_map(fn($d) => sprintf('%.8f', $d), $res['dists']))
        );
    }
    file_put_contents($path, $out);
}

// -----------------------------------------------------------------------------
// Main execution
// -----------------------------------------------------------------------------

$tempDir = sys_get_temp_dir() . '/usearch-interop-' . getmypid();
if (!is_dir($tempDir) && !mkdir($tempDir, 0700, true)) {
    fwrite(STDERR, "FAIL: cannot create temporary directory {$tempDir}\n");
    exit(1);
}

register_shutdown_function(function () use ($tempDir) {
    if (is_dir($tempDir)) {
        $files = glob($tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
        @rmdir($tempDir);
    }
});

try {
    $cppBin = ensureCppHelper($tempDir);
    $cases = getInteropCases();

    echo "==============================================================================\n";
    echo "CROSS-LANGUAGE INDEX-FILE INTEROPERABILITY GATE\n";
    echo "==============================================================================\n";
    printf("USearch version: %s\n", Index::version());
    printf("Cases:           %d\n", count($cases));
    printf("Temp workspace:  %s\n", $tempDir);
    echo "------------------------------------------------------------------------------\n";

    // -------------------------------------------------------------------------
    // PASS 1: Upstream C++ -> PHP
    // -------------------------------------------------------------------------
    echo "\n[PASS 1] Upstream C++ -> PHP (load, view, metadata, get, immutability)\n";

    foreach ($cases as $case) {
        $idxFile = $tempDir . "/cpp_{$case['name']}.usearch";
        $manFile = $tempDir . "/cpp_{$case['name']}.manifest";

        runHelper([
            $cppBin,
            '--action=generate',
            "--case={$case['name']}",
            "--index-file={$idxFile}",
            "--manifest-file={$manFile}",
        ]);

        // Metadata inspection
        $meta = Index::metadata($idxFile);
        if ($meta['dimensions'] !== $case['dimensions']) {
            throw new RuntimeException("C++->PHP metadata dimensions mismatch in {$case['name']}");
        }
        if ($meta['metric'] !== $case['metric']->value) {
            throw new RuntimeException("C++->PHP metadata metric mismatch in {$case['name']}");
        }
        if ($meta['quantization'] !== $case['scalar']->value) {
            throw new RuntimeException("C++->PHP metadata quantization mismatch in {$case['name']}");
        }

        $expectedQueries = parseManifest($manFile);
        $tol = allowedTolerance($case['scalar'], $case['metric']);

        // Test load()
        $idxLoad = new Index([
            'dimensions' => $case['dimensions'],
            'metric' => $case['metric'],
            'quantization' => $case['scalar'],
        ]);
        $idxLoad->load($idxFile);

        $expectedCount = $case['hasRemovals'] ? (int) ($case['count'] - ($case['count'] / 5)) : $case['count'];
        if ($idxLoad->size() !== $expectedCount) {
            throw new RuntimeException(
                "C++->PHP load size mismatch in {$case['name']}: got {$idxLoad->size()} exp {$expectedCount}"
            );
        }

        // Test view()
        $idxView = new Index([
            'dimensions' => $case['dimensions'],
            'metric' => $case['metric'],
            'quantization' => $case['scalar'],
        ]);
        $idxView->view($idxFile);

        if ($idxView->size() !== $expectedCount) {
            throw new RuntimeException(
                "C++->PHP view size mismatch in {$case['name']}: got {$idxView->size()} exp {$expectedCount}"
            );
        }

        // Query verification
        $qState = 987654;
        for ($q = 0; $q < $case['queries']; ++$q) {
            $qvec = generateVector($qState, $case['dimensions'], $case['metric'], $case['scalar']);
            $resLoad = $idxLoad->search($qvec, 10);
            $resView = $idxView->search($qvec, 10);
            $exp = $expectedQueries[$q];

            if (count($resLoad) !== count($exp['keys']) || count($resView) !== count($exp['keys'])) {
                throw new RuntimeException("Query count mismatch in {$case['name']} q={$q}");
            }

            for ($m = 0; $m < count($exp['keys']); ++$m) {
                if ($resLoad[$m]['key'] !== $exp['keys'][$m]) {
                    throw new RuntimeException(
                        "C++->PHP load key mismatch in {$case['name']} q={$q} pos={$m}: " .
                        "got {$resLoad[$m]['key']} exp {$exp['keys'][$m]}"
                    );
                }
                if ($resView[$m]['key'] !== $exp['keys'][$m]) {
                    throw new RuntimeException(
                        "C++->PHP view key mismatch in {$case['name']} q={$q} pos={$m}: " .
                        "got {$resView[$m]['key']} exp {$exp['keys'][$m]}"
                    );
                }

                $diffLoad = abs($resLoad[$m]['distance'] - $exp['dists'][$m]);
                $diffView = abs($resView[$m]['distance'] - $exp['dists'][$m]);
                if ($diffLoad > $tol || $diffView > $tol) {
                    throw new RuntimeException(
                        "C++->PHP distance mismatch in {$case['name']} q={$q} pos={$m}: " .
                        "load={$resLoad[$m]['distance']} exp={$exp['dists'][$m]} tol={$tol}"
                    );
                }
            }
        }

        // Vector retrieval get()
        if ($case['count'] > 0 && !$case['hasRemovals']) {
            $retrieved = $idxLoad->get(1);
            if (!is_array($retrieved) || count($retrieved) !== $case['dimensions']) {
                throw new RuntimeException("C++->PHP get(1) failed in {$case['name']}");
            }
            $origState = 424242;
            $origVec = generateVector($origState, $case['dimensions'], $case['metric'], $case['scalar']);
            $norm = 0.0;
            if ($case['scalar'] === Scalar::I8) {
                foreach ($origVec as $val) {
                    $norm += $val * $val;
                }
                $norm = sqrt($norm);
            }
            for ($d = 0; $d < $case['dimensions']; ++$d) {
                if ($case['scalar'] === Scalar::B1 || $case['metric'] === Metric::Hamming) {
                    $expVal = $origVec[$d] > 0.0 ? 1.0 : 0.0;
                } elseif ($case['scalar'] === Scalar::I8) {
                    $expVal = $norm > 0.0 ? round($origVec[$d] * 127.0 / $norm) / 127.0 : 0.0;
                } else {
                    $expVal = $origVec[$d];
                }
                if (abs($retrieved[$d] - $expVal) > $tol + 0.01) {
                    throw new RuntimeException(
                        "C++->PHP get(1) coordinate mismatch in {$case['name']} dim={$d}: " .
                        "got {$retrieved[$d]} exp {$expVal}"
                    );
                }
            }
        }

        // View immutability
        $threwAdd = false;
        try {
            $idxView->add(999999, array_fill(0, $case['dimensions'], 0.5));
        } catch (\Usearch\Exception) {
            $threwAdd = true;
        }
        if (!$threwAdd) {
            throw new RuntimeException("View mode failed to throw on add() in {$case['name']}");
        }

        // Negative test: dimension mismatch on view()
        if ($case['dimensions'] > 2) {
            $threwDim = false;
            try {
                $idxView->search(array_fill(0, $case['dimensions'] + 1, 0.5), 5);
            } catch (\ValueError) {
                $threwDim = true;
            }
            if (!$threwDim) {
                throw new RuntimeException("View mode failed to throw on dimension mismatch in {$case['name']}");
            }
        }

        // Teardown mmap views before unlinking
        unset($idxLoad, $idxView);
        gc_collect_cycles();
        @unlink($idxFile);
        @unlink($manFile);

        printf("  %-20s PASS (metadata, load, view, get, immutability)\n", $case['name']);
    }

    // -------------------------------------------------------------------------
    // PASS 2: PHP -> Upstream C++
    // -------------------------------------------------------------------------
    echo "\n[PASS 2] PHP -> Upstream C++ (save, C++ load & view validation)\n";

    foreach ($cases as $case) {
        $idxFile = $tempDir . "/php_{$case['name']}.usearch";
        $manFile = $tempDir . "/php_{$case['name']}.manifest";

        $idx = new Index([
            'dimensions' => $case['dimensions'],
            'metric' => $case['metric'],
            'quantization' => $case['scalar'],
            'connectivity' => 16,
            'expansionAdd' => 128,
            'expansionSearch' => 64,
        ]);
        if ($case['count'] > 0) {
            $idx->reserve($case['count']);
        }

        $state = 424242;
        for ($i = 0; $i < $case['count']; ++$i) {
            $v = generateVector($state, $case['dimensions'], $case['metric'], $case['scalar']);
            $idx->add($i + 1, $v);
        }

        if ($case['hasRemovals']) {
            for ($i = 0; $i < $case['count']; ++$i) {
                if (($i + 1) % 5 === 0) {
                    $idx->remove($i + 1);
                }
            }
        }

        $idx->save($idxFile);

        // Compute search matches in PHP
        $queries = [];
        $qState = 987654;
        for ($q = 0; $q < $case['queries']; ++$q) {
            $qvec = generateVector($qState, $case['dimensions'], $case['metric'], $case['scalar']);
            $matches = $idx->search($qvec, 10);
            $keys = array_column($matches, 'key');
            $dists = array_column($matches, 'distance');
            $queries[$q] = ['keys' => $keys, 'dists' => $dists];
        }

        writeManifest($manFile, $case, $idx->size(), $queries);
        unset($idx);
        gc_collect_cycles();

        // Validate using C++ helper
        runHelper([
            $cppBin,
            '--action=validate',
            "--case={$case['name']}",
            "--index-file={$idxFile}",
            "--manifest-file={$manFile}",
        ]);

        @unlink($idxFile);
        @unlink($manFile);

        printf("  %-20s PASS (PHP save -> C++ load/view exact match)\n", $case['name']);
    }

    // -------------------------------------------------------------------------
    // PASS 3: In-Memory Buffer Variants (Cross-Language)
    // -------------------------------------------------------------------------
    echo "\n[PASS 3] In-Memory Buffer Variants (Cross-Language)\n";

    $bufCase = $cases[0]; // cos_f32_128
    $cppIdxFile = $tempDir . "/cpp_{$bufCase['name']}.usearch";
    $cppManFile = $tempDir . "/cpp_{$bufCase['name']}.manifest";

    // Re-generate C++ index for buffer test
    runHelper([
        $cppBin,
        '--action=generate',
        "--case={$bufCase['name']}",
        "--index-file={$cppIdxFile}",
        "--manifest-file={$cppManFile}",
    ]);

    // 1. Upstream C++ serialized bytes -> PHP loadBuffer(), viewBuffer(), metadataBuffer()
    $cppRawBytes = file_get_contents($cppIdxFile);
    if ($cppRawBytes === false || strlen($cppRawBytes) === 0) {
        throw new RuntimeException("Failed to read C++ index bytes from {$cppIdxFile}");
    }

    $bufMeta = Index::metadataBuffer($cppRawBytes);
    if (
        $bufMeta['dimensions'] !== $bufCase['dimensions']
        || $bufMeta['metric'] !== $bufCase['metric']->value
        || $bufMeta['quantization'] !== $bufCase['scalar']->value
    ) {
        throw new RuntimeException("metadataBuffer() mismatch on C++ buffer");
    }

    $idxBufLoad = new Index([
        'dimensions' => $bufCase['dimensions'],
        'metric' => $bufCase['metric'],
        'quantization' => $bufCase['scalar'],
    ]);
    $idxBufLoad->loadBuffer($cppRawBytes);

    $idxBufView = new Index([
        'dimensions' => $bufCase['dimensions'],
        'metric' => $bufCase['metric'],
        'quantization' => $bufCase['scalar'],
    ]);
    $idxBufView->viewBuffer($cppRawBytes);

    if ($idxBufLoad->size() !== $bufCase['count'] || $idxBufView->size() !== $bufCase['count']) {
        throw new RuntimeException("Buffer size mismatch on C++ buffer");
    }

    $expectedQueries = parseManifest($cppManFile);
    $qState = 987654;
    $testQ = generateVector($qState, $bufCase['dimensions'], $bufCase['metric'], $bufCase['scalar']);
    $resLoad = $idxBufLoad->search($testQ, 10);
    $resView = $idxBufView->search($testQ, 10);
    $exp = $expectedQueries[0];
    for ($m = 0; $m < count($exp['keys']); ++$m) {
        if ($resLoad[$m]['key'] !== $exp['keys'][$m] || $resView[$m]['key'] !== $exp['keys'][$m]) {
            throw new RuntimeException("Search mismatch on C++ buffer at rank {$m}");
        }
    }

    // 2. PHP saveBuffer() -> Upstream C++ validation
    $idxPhpBuf = new Index([
        'dimensions' => $bufCase['dimensions'],
        'metric' => $bufCase['metric'],
        'quantization' => $bufCase['scalar'],
        'connectivity' => 16,
        'expansionAdd' => 128,
        'expansionSearch' => 64,
    ]);
    $idxPhpBuf->reserve($bufCase['count']);
    $state = 424242;
    for ($i = 0; $i < $bufCase['count']; ++$i) {
        $idxPhpBuf->add($i + 1, generateVector($state, $bufCase['dimensions'], $bufCase['metric'], $bufCase['scalar']));
    }
    $phpRawBuffer = $idxPhpBuf->saveBuffer();

    $phpBufFile = $tempDir . "/php_buf_{$bufCase['name']}.usearch";
    file_put_contents($phpBufFile, $phpRawBuffer);

    // Validate using C++ helper
    runHelper([
        $cppBin,
        '--action=validate',
        "--case={$bufCase['name']}",
        "--index-file={$phpBufFile}",
        "--manifest-file={$cppManFile}",
    ]);

    unset($idxBufLoad, $idxBufView, $idxPhpBuf);
    gc_collect_cycles();
    @unlink($cppIdxFile);
    @unlink($cppManFile);
    @unlink($phpBufFile);
    echo "  C++ buffer -> PHP loadBuffer/viewBuffer & PHP saveBuffer -> C++ validate PASS\n";

    // -------------------------------------------------------------------------
    // PASS 4: Python Interoperability (Conditional Tier 2)
    // -------------------------------------------------------------------------
    echo "\n[PASS 4] Upstream Python (Conditional Tier 2)\n";
    $hasPython = false;
    $pyCheck = probeCommand(['python3', '-c', 'import usearch, numpy; print(usearch.__version__)']);
    if ($pyCheck !== null && trim($pyCheck) !== '') {
        $hasPython = true;
    }

    if ($hasPython) {
        echo "  Python usearch detected (v" . trim($pyCheck) . ") — running python cross-check...\n";
        // Create Python test script on the fly
        $pyScript = $tempDir . '/interop_test.py';
        $pyIndex = $tempDir . '/php_py_test.usearch';

        $idxPy = new Index(['dimensions' => 64, 'metric' => Metric::Cosine]);
        $idxPy->reserve(100);
        $s = 999;
        for ($i = 0; $i < 100; ++$i) {
            $idxPy->add($i + 1, generateVector($s, 64, Metric::Cosine, Scalar::F32));
        }
        $testQ = array_fill(0, 64, 0.5);
        $matches = $idxPy->search($testQ, 5);
        $expKeys = implode(',', array_column($matches, 'key'));

        $idxPy->save($pyIndex);
        unset($idxPy);
        gc_collect_cycles();

        $pyCode = <<< 'PY'
import sys
import numpy as np
from usearch.index import Index

index = Index.restore(sys.argv[1], view=True)
if index.ndim != 64:
    sys.exit(1)
if len(index) != 100:
    sys.exit(2)
query = np.array([0.5] * 64, dtype=np.float32)
matches = index.search(query, 5)
if len(matches) != 5:
    sys.exit(3)
expected_keys = [int(k) for k in sys.argv[2].split(',')]
if list(matches.keys) != expected_keys:
    sys.exit(4)
sys.exit(0)
PY;
        file_put_contents($pyScript, $pyCode);
        runHelper(['python3', $pyScript, $pyIndex, $expKeys]);
        @unlink($pyScript);
        @unlink($pyIndex);
        echo "  Python view cross-check                                PASS\n";
    } else {
        echo "  [SKIPPED: python3 usearch/numpy package not installed in environment]\n";
    }

    echo "\n==============================================================================\n";
    echo "INTEROPERABILITY GATE: ALL PASS\n";
    echo "==============================================================================\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "\nFAIL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
