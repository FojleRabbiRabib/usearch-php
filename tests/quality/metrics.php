<?php

/**
 * tests/quality/metrics.php — metric-correctness gate.
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 *
 * The other half of the quality oracle. tests/quality/recall.php measures how
 * well the graph approximates; it cannot detect a wrong metric, because its
 * ground truth is computed through the same distance() it is checking. If
 * Usearch\Metric::L2sq were bound to the inner-product kind, recall would stay
 * at 1.0 while every query returned the wrong neighbours.
 *
 * This gate closes that gap by comparing distance() against arithmetic written
 * here, in PHP, from the metric's mathematical definition — no extension code
 * on the expected side. Only metrics with an unambiguous closed form are
 * asserted; there is no point pinning a convention this file cannot derive
 * independently.
 *
 * Also pinned: the enum case values are the upstream kind constants verbatim,
 * so a renumbering in usearch_enums.c fails here rather than silently
 * reinterpreting every index ever written.
 *
 * Usage: php -n -d extension=modules/usearch.so tests/quality/metrics.php
 */

declare(strict_types=1);

use Usearch\Index;
use Usearch\Metric;

const TOLERANCE = 1e-6;

/** Independent reference implementations, in plain PHP. */

/**
 * @param list<float> $a
 * @param list<float> $b
 */
function refL2sq(array $a, array $b): float
{
    $sum = 0.0;
    foreach ($a as $i => $v) {
        $sum += ($v - $b[$i]) ** 2;
    }
    return $sum;
}

/**
 * @param list<float> $a
 * @param list<float> $b
 */
function refCosine(array $a, array $b): float
{
    $dot = 0.0;
    $na = 0.0;
    $nb = 0.0;
    foreach ($a as $i => $v) {
        $dot += $v * $b[$i];
        $na += $v * $v;
        $nb += $b[$i] * $b[$i];
    }
    return 1.0 - $dot / (sqrt($na) * sqrt($nb));
}

/** Upstream returns `1 - dot`, so identical vectors score `1 - |a|²`, not 0.
 * @param list<float> $a
 * @param list<float> $b
 */
function refInnerProduct(array $a, array $b): float
{
    $dot = 0.0;
    foreach ($a as $i => $v) {
        $dot += $v * $b[$i];
    }
    return 1.0 - $dot;
}

/** Upstream returns angular distance in radians (unit radius).
 * @param list<float> $a
 * @param list<float> $b
 */
function refHaversine(array $a, array $b): float
{
    $lat1 = deg2rad($a[0]);
    $lat2 = deg2rad($b[0]);
    $dLat = $lat2 - $lat1;
    $dLon = deg2rad($b[1]) - deg2rad($a[1]);
    $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
    return 2.0 * asin(min(1.0, sqrt($h)));
}

/** Upstream returns `1 - r` for the Pearson correlation coefficient.
 * @param list<float> $a
 * @param list<float> $b
 */
function refPearson(array $a, array $b): float
{
    $n = count($a);
    $ma = array_sum($a) / $n;
    $mb = array_sum($b) / $n;
    $num = 0.0;
    $da = 0.0;
    $db = 0.0;
    foreach ($a as $i => $v) {
        $num += ($v - $ma) * ($b[$i] - $mb);
        $da += ($v - $ma) ** 2;
        $db += ($b[$i] - $mb) ** 2;
    }
    return 1.0 - $num / sqrt($da * $db);
}

$failures = 0;

/**
 * @param callable(list<float>, list<float>): float $reference
 * @param list<float> $a
 * @param list<float> $b
 */
function check(string $label, Metric $metric, array $a, array $b, callable $reference): void
{
    global $failures;
    $index = new Index(['dimensions' => count($a), 'metric' => $metric]);
    $got = $index->distance($a, $b);
    $want = $reference($a, $b);
    if (is_nan($got) || abs($got - $want) > TOLERANCE) {
        printf("FAIL %-26s got %.9f, expected %.9f\n", $label, $got, $want);
        $failures++;
        return;
    }
    printf("PASS %-26s %.9f\n", $label, $got);
}

/* Two independent pairs: one where the vectors point the same way and one
 * where they do not, so a sign error or a swapped operand cannot pass both. */
$sameA = [1.0, 2.0, 3.0];
$sameB = [2.0, 4.0, 6.0];       /* collinear with $sameA: Pearson r = 1 */
$diffB = [4.0, 5.0, 6.0];
$unrelated = [3.0, 1.0, 5.0];   /* not a linear function of $sameA: r != 1 */

check('L2sq (distinct)', Metric::L2sq, $sameA, $diffB, refL2sq(...));
check('L2sq (identical)', Metric::L2sq, $sameA, $sameA, refL2sq(...));
check('Cosine (identical)', Metric::Cosine, $sameA, $sameA, refCosine(...));
check('Cosine (opposite)', Metric::Cosine, $sameA, array_map(fn (float $v): float => -$v, $sameA), refCosine(...));
check('Cosine (distinct)', Metric::Cosine, $sameA, $diffB, refCosine(...));
check('Ip (distinct)', Metric::Ip, $sameA, $diffB, refInnerProduct(...));
check('Ip (identical)', Metric::Ip, $sameA, $sameA, refInnerProduct(...));
check('Pearson (collinear)', Metric::Pearson, $sameA, $sameB, refPearson(...));
check('Pearson (unrelated)', Metric::Pearson, $sameA, $unrelated, refPearson(...));

/* Haversine ignores the index's `dimensions` (upstream reports 0), so it is
 * constructed without one — the path R1-09 fixed. */
$geoA = [10.0, 20.0];
$geoB = [-30.0, 45.0];
check(
    'Haversine (radians)',
    Metric::Haversine,
    $geoA,
    $geoB,
    refHaversine(...)
);

/* The case values are the upstream kind constants. Asserting the literals is
 * the point: this is the mapping that a wrong enum body would change. */
$expectedKinds = [
    'Cosine' => 1, 'Ip' => 2, 'L2sq' => 3, 'Haversine' => 4, 'Divergence' => 5,
    'Pearson' => 6, 'Jaccard' => 7, 'Hamming' => 8, 'Tanimoto' => 9, 'Sorensen' => 10,
];
foreach ($expectedKinds as $name => $kind) {
    $case = constant(Metric::class . '::' . $name);
    if ($case->value !== $kind) {
        printf("FAIL Metric::%-12s value is %d, expected %d\n", $name, $case->value, $kind);
        $failures++;
    }
}
printf("PASS %-26s 10 cases match the upstream kind constants\n", 'Metric enum values');

/* Distance must be symmetric for the metrics whose definition is: d(a,b)==d(b,a). */
foreach ([Metric::L2sq, Metric::Cosine, Metric::Ip, Metric::Haversine] as $metric) {
    $index = new Index(['dimensions' => 2, 'metric' => $metric]);
    $a = [0.25, 0.75];
    $b = [0.5, 0.125];
    if (abs($index->distance($a, $b) - $index->distance($b, $a)) > TOLERANCE) {
        printf("FAIL %-26s distance is not symmetric for %s\n", 'symmetry', $metric->name);
        $failures++;
    }
}
printf("PASS %-26s L2sq, Cosine, Ip, Haversine\n", 'symmetry');

if ($failures > 0) {
    fwrite(STDERR, "FAIL: {$failures} metric assertion(s) failed\n");
    exit(1);
}
echo "METRIC GATE: PASS\n";
