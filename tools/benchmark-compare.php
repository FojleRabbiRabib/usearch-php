<?php

/**
 * tools/benchmark-compare.php — usearch-php against the alternatives PHP
 * developers use today for the same job.
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 *
 * The honest comparison is not "usearch vs usearch". Two real baselines:
 *   1. Exact brute-force scan in pure PHP — what most PHP apps actually do over
 *      a few thousand embeddings, and the thing that stops scaling.
 *   2. A subprocess HTTP round-trip to an external vector service — the
 *      standard production escape hatch, modelled with a loopback request so
 *      the network cost is real rather than assumed.
 *
 * Usage: php -n -d extension=modules/usearch.so tools/benchmark-compare.php
 */

declare(strict_types=1);

use Usearch\Index;
use Usearch\Metric;

/**
 * Cosine distance between two equal-length vectors.
 *
 * @param list<float> $a
 * @param list<float> $b
 */
function cosine(array $a, array $b): float
{
    $dot = 0.0;
    $na = 0.0;
    $nb = 0.0;
    foreach ($a as $i => $v) {
        $dot += $v * $b[$i];
        $na += $v * $v;
        $nb += $b[$i] * $b[$i];
    }
    return 1.0 - $dot / (sqrt($na) * sqrt($nb) + 1e-12);
}

/**
 * Exact top-k by full scan: what a pure-PHP app does today.
 *
 * @param array<int, list<float>> $vectors
 * @param list<float> $query
 * @return array<int, float>
 */
function bruteForce(array $vectors, array $query, int $k): array
{
    $scored = [];
    foreach ($vectors as $key => $v) {
        $scored[$key] = cosine($query, $v);
    }
    asort($scored);
    return array_slice($scored, 0, $k, true);
}

function processRssBytes(): int
{
    $status = @file_get_contents('/proc/self/status');
    if ($status === false || preg_match('/^VmRSS:\\s+(\\d+)\\s+kB$/m', $status, $matches) !== 1) {
        return 0;
    }
    return (int) $matches[1] * 1024;
}

$dim = 256;
$counts = [500, 2000, 8000];
$k = 10;

echo str_repeat('=', 78) . "\n";
echo "usearch-php vs the alternatives  (dim=$dim, k=$k, cosine)\n";
echo str_repeat('=', 78) . "\n";
printf(
    "%-10s %-18s %-18s %-18s %s\n",
    'vectors',
    'pure-PHP scan',
    'usearch (in-proc)',
    'speedup',
    'RSS delta: php / ext'
);
echo str_repeat('-', 78) . "\n";

foreach ($counts as $n) {
    /* Build the corpus as plain PHP arrays (the pure-PHP baseline's own data). */
    $rssBeforePhp = processRssBytes();
    $vectors = [];
    for ($i = 0; $i < $n; $i++) {
        $v = [];
        for ($d = 0; $d < $dim; $d++) {
            $v[] = sin($i * 12.9898 + $d * 78.233);
        }
        $vectors[] = $v;
    }
    $query = $vectors[0];

    /* --- baseline: exact full scan in userland --- */
    $reps = max(3, (int) (4000 / $n));
    $t0 = microtime(true);
    for ($r = 0; $r < $reps; $r++) {
        bruteForce($vectors, $query, $k);
    }
    $t1 = microtime(true);
    $phpSec = ($t1 - $t0) / $reps;
    $phpRssDelta = max(0, processRssBytes() - $rssBeforePhp);

    /* --- usearch: in-process --- */
    $rssBeforeExt = processRssBytes();
    $idx = new Index(['dimensions' => $dim, 'metric' => Metric::Cosine]);
    $idx->reserve($n);
    foreach ($vectors as $key => $v) {
        $idx->add($key, $v);
    }
    $extRssDelta = max(0, processRssBytes() - $rssBeforeExt);
    for ($r = 0; $r < 20; $r++) {
        $idx->search($query, $k);
    }
    $repsU = max(200, (int) (200000 / $n));
    $t0 = microtime(true);
    for ($r = 0; $r < $repsU; $r++) {
        $idx->search($query, $k);
    }
    $t1 = microtime(true);
    $extSec = ($t1 - $t0) / $repsU;

    printf(
        "%-10s %-18s %-18s %-18s %s\n",
        number_format($n),
        sprintf('%.1f ms', $phpSec * 1e3),
        sprintf('%.0f us', $extSec * 1e6),
        sprintf('%.0fx', $phpSec / $extSec),
        sprintf(
            '%s / %s',
            number_format($phpRssDelta / 1048576, 1) . ' MiB',
            number_format($extRssDelta / 1048576, 1) . ' MiB'
        )
    );

    unset($idx, $vectors);
    gc_collect_cycles();
}

/* --- the external-service baseline: one loopback HTTP round-trip --- */
echo "\n";
echo str_repeat('=', 78) . "\n";
echo "external vector service round-trip (loopback, no TLS, best case)\n";
echo str_repeat('=', 78) . "\n";

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    echo "skipped: {$errstr}\n";
} else {
    $name = stream_socket_get_name($server, false);
    $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

    $samples = 300;
    $t0 = microtime(true);
    for ($i = 0; $i < $samples; $i++) {
        $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $e2, 1.0);
        if ($client !== false) {
            fwrite($client, "POST /search HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n");
            $conn = @stream_socket_accept($server, 1.0);
            if ($conn !== false) {
                fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n{}");
                fclose($conn);
                fread($client, 64);
            }
            fclose($client);
        }
    }
    $t1 = microtime(true);
    $rtt = ($t1 - $t0) / $samples;

    /* Measure the matching in-process query on the same corpus size the table
     * above ended at, so both figures describe the same job. */
    $nLast = end($counts);
    $vectors = [];
    for ($i = 0; $i < $nLast; $i++) {
        $v = [];
        for ($d = 0; $d < $dim; $d++) {
            $v[] = sin($i * 12.9898 + $d * 78.233);
        }
        $vectors[] = $v;
    }
    $idx = new Index(['dimensions' => $dim, 'metric' => Metric::Cosine]);
    $idx->reserve($nLast);
    foreach ($vectors as $key => $v) {
        $idx->add($key, $v);
    }
    $query = array_fill(0, $dim, 0.25);
    for ($r = 0; $r < 50; $r++) {
        $idx->search($query, $k);
    }
    $t0 = microtime(true);
    for ($r = 0; $r < 500; $r++) {
        $idx->search($query, $k);
    }
    $t1 = microtime(true);
    $nativeUs = ($t1 - $t0) / 500 * 1e6;

    printf("loopback connect+request+read: %.0f us per call\n", $rtt * 1e6);
    printf("usearch in-process, %s x %d d:  %.0f us per query\n", number_format($nLast), $dim, $nativeUs);
    printf("=> the empty round-trip alone costs %.1fx a full native search,\n", ($rtt * 1e6) / $nativeUs);
    echo "and a real deployment adds TLS, JSON vector encoding, and network\n";
    echo "distance on top. Treat this as the best case for the daemon model.\n";

    fclose($server);
}
