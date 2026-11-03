<?php

/**
 * tools/benchmark.php — measurable performance and resource profile.
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 *
 * Reports real OS memory from /proc/self/status, never Zend MM alone: PHP's
 * tracker is blind to the C++ allocator and the HNSW graph, which is where
 * nearly all of an index's memory actually lives.
 *
 * Usage: php -n -d extension=modules/usearch.so tools/benchmark.php [suite]
 *   suite: quick (default) | full | ratchet
 *
 * `parity` (php tools/benchmark.php parity <reference.so> <candidate.so> [rounds])
 * runs the ratchet suite for two builds of the same source in alternating child
 * processes and fails when the candidate leaves the ratchet bands relative to the
 * reference; it needs no loaded extension and never reads the baseline.
 *
 * `ratchet` measures a fixed deterministic suite and compares it against the
 * committed baseline in tests/quality/benchmark-baseline.json: exit 1 on any
 * regression past its band, exit 2 when the baseline is missing, malformed, or
 * recorded on a different host class. Append `--record` to rewrite the baseline
 * from this run (deliberate improvements, machine changes).
 */

declare(strict_types=1);

use Usearch\Index;
use Usearch\Metric;

/* PHP's default 128 MB memory_limit throttles large builds through the Zend
 * allocator long before the native index is the bottleneck, which silently
 * understates build throughput. Raise it for the run and report it. */
$limit = ini_get('memory_limit');
if ($limit !== '-1') {
    $bytes = match (strtoupper(substr($limit, -1))) {
        'G' => (int) $limit * 1073741824,
        'M' => (int) $limit * 1048576,
        'K' => (int) $limit * 1024,
        default => (int) $limit,
    };
    if ($bytes < 2147483648) {
        ini_set('memory_limit', '4G');
    }
}

/**
 * Current process resident set size and high-water mark, in bytes.
 *
 * @return array{rss: int, peak: int}
 */
function rss(): array
{
    $out = ['rss' => 0, 'peak' => 0];
    $status = @file_get_contents('/proc/self/status');
    if ($status === false) {
        return $out;
    }
    if (preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $matches) === 1) {
        $out['rss'] = (int) $matches[1] * 1024;
    }
    if (preg_match('/^VmHWM:\s+(\d+)\s+kB$/m', $status, $matches) === 1) {
        $out['peak'] = (int) $matches[1] * 1024;
    }
    return $out;
}

/**
 * Deterministic pseudo-random vectors, so runs are comparable.
 *
 * @return list<list<float>>
 */
function makeVectors(int $count, int $dim): array
{
    $vectors = [];
    for ($i = 0; $i < $count; $i++) {
        $v = [];
        for ($d = 0; $d < $dim; $d++) {
            $val = (sin($i * 12.9898 + $d * 78.233) * 43758.5453);
            $v[] = $val - floor($val);
        }
        $vectors[] = $v;
    }
    return $vectors;
}

function bytes(int $n): string
{
    if ($n >= 1073741824) {
        return sprintf('%.2f GiB', $n / 1073741824);
    }
    if ($n >= 1048576) {
        return sprintf('%.1f MiB', $n / 1048576);
    }
    if ($n >= 1024) {
        return sprintf('%.1f KiB', $n / 1024);
    }
    return $n . ' B';
}

function hline(string $title): void
{
    echo "\n" . str_repeat('=', 74) . "\n" . $title . "\n" . str_repeat('=', 74) . "\n";
}

/* ------------------------------------------------------------- ratchet */

/** Committed baseline the ratchet suite compares against. */
const RATCHET_BASELINE = __DIR__ . '/../tests/quality/benchmark-baseline.json';

/** A metric fails when current exceeds its baseline by this factor. */
const RATCHET_BANDS = [
    'add_per_vector_us' => 1.25,
    'query_us' => 1.20,
    'memory_bytes' => 1.02,
    'serialized_bytes_per_vector' => 1.02,
];

/** The ratchet suite, in run order. */
const RATCHET_CASES = [
    'build-search-5000-128',
    'build-search-2000-768',
    'quantize-f16-5000-768',
    'quantize-i8-5000-768',
    'view-20000-128',
    'load-20000-128',
];

/**
 * Identity of the machine class a baseline is valid for. The ratchet never
 * compares across host classes: different hardware is a re-record, not a gate.
 *
 * @return array{php: string, arch: string, isa: string}
 */
function ratchetHost(): array
{
    return [
        'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        'arch' => php_uname('m'),
        'isa' => Index::hardwareAccelerationAvailable(),
    ];
}

/**
 * Per-query latency in microseconds over a warmed index: best of five timed
 * passes, because a single pass on a shared host occasionally catches a
 * scheduler hiccup or power scaling ramp that would read as a regression.
 *
 * @param list<float> $query
 */
function timedQueries(Index $idx, array $query, int $reps): float
{
    for ($r = 0; $r < 20; $r++) {
        $idx->search($query, 10);
    }
    $best = INF;
    for ($pass = 0; $pass < 5; $pass++) {
        $t0 = microtime(true);
        for ($r = 0; $r < $reps; $r++) {
            $idx->search($query, 10);
        }
        $best = min($best, (microtime(true) - $t0) / $reps * 1e6);
    }
    return $best;
}

/**
 * Serialized 20,000 x 128 index shared by the view/load cases, built once per
 * process.
 */
function ratchetViewFile(): string
{
    static $path = null;
    if ($path === null) {
        $vectors = makeVectors(20000, 128);
        $idx = new Index(['dimensions' => 128, 'metric' => Metric::Cosine, 'connectivity' => 16]);
        $idx->reserve(20000);
        foreach ($vectors as $k => $v) {
            $idx->add($k, $v);
        }
        $path = tempnam(sys_get_temp_dir(), 'usearch-ratchet-');
        $idx->save($path);
        unset($idx, $vectors);
        gc_collect_cycles();
    }
    return $path;
}

/**
 * Measure one ratchet case. Corpora come from the deterministic sin-fract
 * family, so run-to-run differences are machine noise plus real code changes.
 *
 * @return array<string, float>
 */
function ratchetCase(string $name): array
{
    switch ($name) {
        case 'build-search-5000-128':
        case 'build-search-2000-768':
            [$n, $d] = $name === 'build-search-5000-128' ? [5000, 128] : [2000, 768];
            $vectors = makeVectors($n, $d);
            $idx = new Index(['dimensions' => $d, 'metric' => Metric::Cosine, 'connectivity' => 16]);
            $idx->reserve($n);
            $t0 = microtime(true);
            foreach ($vectors as $k => $v) {
                $idx->add($k, $v);
            }
            $buildSec = microtime(true) - $t0;
            return [
                'add_per_vector_us' => round($buildSec / $n * 1e6, 2),
                'query_us' => round(timedQueries($idx, $vectors[0], 200), 2),
                'memory_bytes' => round((float) $idx->memoryUsage(), 1),
            ];
        case 'quantize-f16-5000-768':
        case 'quantize-i8-5000-768':
            $scalar = $name === 'quantize-f16-5000-768' ? \Usearch\Scalar::F16 : \Usearch\Scalar::I8;
            $vectors = makeVectors(5000, 768);
            $idx = new Index(['dimensions' => 768, 'metric' => Metric::Cosine, 'quantization' => $scalar]);
            $idx->reserve(5000);
            foreach ($vectors as $k => $v) {
                $idx->add($k, $v);
            }
            /* memoryUsage() is scalar-blind at this scale (reserved tape chunks
             * dominate), so quantization is ratcheted through the serialized
             * size, which tracks the scalar width exactly. */
            return [
                'query_us' => round(timedQueries($idx, $vectors[0], 100), 2),
                'serialized_bytes_per_vector' => round($idx->serializedLength() / 5000, 1),
            ];
        case 'view-20000-128':
            $query = makeVectors(1, 128)[0];
            $idx = new Index(['dimensions' => 128]);
            $idx->view(ratchetViewFile());
            return ['query_us' => round(timedQueries($idx, $query, 300), 2)];
        case 'load-20000-128':
            $query = makeVectors(1, 128)[0];
            $idx = new Index(['dimensions' => 128]);
            $idx->load(ratchetViewFile());
            return [
                'query_us' => round(timedQueries($idx, $query, 300), 2),
                'memory_bytes' => round((float) $idx->memoryUsage(), 1),
            ];
    }
    fwrite(STDERR, "FAIL: unknown ratchet case {$name}\n");
    exit(2);
}

/**
 * Run the ratchet suite: measure, then compare against the committed baseline
 * (or rewrite it with --record). Exit 0 on pass/record, 1 on regression, 2 on
 * baseline or host-class problems.
 */
function runRatchet(bool $record): int
{
    $host = ratchetHost();

    if ($record) {
        $baseline = ['format' => 1, 'recorded' => gmdate('c'), 'host' => $host, 'cases' => []];
        foreach (RATCHET_CASES as $name) {
            $baseline['cases'][$name] = ratchetCase($name);
            printf("recorded %s\n", $name);
        }
        $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents(RATCHET_BASELINE, $json, LOCK_EX) === false) {
            fwrite(STDERR, 'FAIL: cannot write ' . RATCHET_BASELINE . "\n");
            return 2;
        }
        @unlink(ratchetViewFile());
        echo 'RATCHET BASELINE RECORDED (' . count(RATCHET_CASES) . " cases)\n";
        return 0;
    }

    $raw = @file_get_contents(RATCHET_BASELINE);
    if ($raw === false) {
        fwrite(STDERR, 'FAIL: baseline missing at ' . RATCHET_BASELINE . " — run `ratchet --record` first\n");
        return 2;
    }
    try {
        $baseline = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        fwrite(STDERR, 'FAIL: baseline is not valid JSON: ' . $e->getMessage() . "\n");
        return 2;
    }
    if (!is_array($baseline) || !is_array($baseline['cases'] ?? null)) {
        fwrite(STDERR, "FAIL: baseline is malformed (no cases map)\n");
        return 2;
    }
    $baseHost = $baseline['host'] ?? null;
    if (!is_array($baseHost)) {
        fwrite(STDERR, "FAIL: baseline is malformed (no host block)\n");
        return 2;
    }
    foreach (['php', 'arch', 'isa'] as $key) {
        $recorded = $baseHost[$key] ?? '?';
        if ($recorded !== $host[$key]) {
            fwrite(STDERR, sprintf(
                "FAIL: baseline host %s=%s, this host %s=%s — a different machine is a re-record, not a comparison\n",
                $key,
                (string) $recorded,
                $key,
                $host[$key],
            ));
            return 2;
        }
    }
    $baseCases = $baseline['cases'];

    hline('RATCHET: ' . count(RATCHET_CASES) . ' cases vs ' . basename(RATCHET_BASELINE));
    printf(
        "baseline %s (php %s, %s, %s)\n",
        (string) ($baseline['recorded'] ?? '?'),
        (string) ($baseHost['php'] ?? '?'),
        (string) ($baseHost['arch'] ?? '?'),
        (string) ($baseHost['isa'] ?? '?'),
    );
    printf("%-24s %-18s %11s %11s %5s  %s\n", 'case', 'metric', 'baseline', 'current', 'band', 'verdict');
    echo str_repeat('-', 84) . "\n";

    $regressions = 0;
    $improved = 0;
    foreach (RATCHET_CASES as $name) {
        $current = ratchetCase($name);
        $expected = $baseCases[$name] ?? null;
        if (!is_array($expected)) {
            fwrite(STDERR, "FAIL: baseline has no case {$name} — re-record the baseline\n");
            return 2;
        }
        foreach ($current as $metric => $value) {
            $base = $expected[$metric] ?? null;
            if (!is_float($base) && !is_int($base)) {
                fwrite(STDERR, "FAIL: baseline case {$name} has no metric {$metric}\n");
                return 2;
            }
            $band = RATCHET_BANDS[$metric] ?? null;
            if ($band === null) {
                fwrite(STDERR, "FAIL: no band defined for metric {$metric}\n");
                return 2;
            }
            if ($value > $base * $band) {
                $verdict = 'REGRESSION';
                $regressions++;
            } elseif ($value < $base * 0.85) {
                $verdict = 'BETTER';
                $improved++;
            } else {
                $verdict = 'ok';
            }
            printf(
                "%-24s %-18s %11.2f %11.2f %5.2f  %s\n",
                $name,
                $metric,
                (float) $base,
                $value,
                $band,
                $verdict,
            );
        }
    }
    @unlink(ratchetViewFile());

    if ($regressions > 0) {
        printf("\nRATCHET FAILED: %d regression(s), %d improved\n", $regressions, $improved);
        return 1;
    }
    printf("\nRATCHET PASSED: %d case(s), 0 regressions, %d improved\n", count(RATCHET_CASES), $improved);
    if ($improved > 0) {
        echo "improvements beyond 15%: consider re-recording with `ratchet --record`\n";
    }
    return 0;
}

/**
 * Measure one ratchet case and print the result as JSON. The parity driver runs
 * this in a child process per module and case, so each module is loaded alone and
 * the two builds are measured seconds apart rather than minutes apart.
 */
function runMeasure(string $case): int
{
    if (!in_array($case, RATCHET_CASES, true)) {
        fwrite(STDERR, "FAIL: unknown ratchet case {$case}\n");
        return 2;
    }
    $cases = [$case => ratchetCase($case)];
    @unlink(ratchetViewFile());
    echo json_encode([
        'host' => ratchetHost() + ['compiled' => Index::hardwareAccelerationCompiled()],
        'cases' => $cases,
    ], JSON_THROW_ON_ERROR) . "\n";
    return 0;
}

/**
 * Measure one case in a child process that loads only the given module.
 *
 * @return array{host: array<string, string>, cases: array<string, array<string, float>>}
 */
function measureModule(string $module, string $case): array
{
    // The child's stderr goes to a file, so a chatty child can never fill a pipe while
    // this process is still draining stdout.
    $errFile = tempnam(sys_get_temp_dir(), 'usearch-measure-');
    if ($errFile === false) {
        fwrite(STDERR, "FAIL: cannot create a temporary file\n");
        exit(2);
    }
    $process = proc_open(
        [PHP_BINARY, '-n', '-d', 'extension=' . $module, __FILE__, 'measure', $case],
        [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        unlink($errFile);
        fwrite(STDERR, "FAIL: cannot start a child PHP for {$module}\n");
        exit(2);
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    $err = (string) file_get_contents($errFile);
    unlink($errFile);
    if ($status !== 0) {
        fwrite(STDERR, "FAIL: measuring {$module} failed:\n{$err}");
        exit(2);
    }
    try {
        /** @var array{host: array<string, string>, cases: array<string, array<string, float>>} */
        return json_decode($out, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "FAIL: {$module} produced unreadable measurements: {$e->getMessage()}\n{$out}{$err}");
        exit(2);
    }
}

/**
 * Tiers of a comma-separated ISA list that the reference build was compiled with.
 *
 * @param list<string> $reference
 * @return list<string>
 */
function tiersWithin(string $list, array $reference): array
{
    return array_values(array_filter(
        array_map('trim', explode(',', $list)),
        static fn (string $tier): bool => in_array($tier, $reference, true),
    ));
}

/**
 * Paired speed-parity gate: a reference build and a candidate build of the same
 * source run the ratchet suite in alternating child processes on this machine,
 * the best of the rounds per metric is kept for each, and the candidate must stay
 * inside the ratchet bands relative to the reference. Never compares against the
 * committed baseline, which is machine-local. Exit 0 pass, 1 regression, 2 when
 * the two builds are not comparable (different PHP, arch, or CPU-visible tiers).
 */
function runParity(string $reference, string $candidate, int $rounds): int
{
    foreach ([$reference, $candidate] as $module) {
        if (!is_file($module)) {
            fwrite(STDERR, "FAIL: module {$module} not found\n");
            return 2;
        }
    }
    if (realpath($reference) === realpath($candidate)) {
        fwrite(STDERR, "FAIL: reference and candidate are the same file; nothing to compare\n");
        return 2;
    }
    if ($rounds < 1 || $rounds > 9) {
        fwrite(STDERR, "FAIL: rounds must be 1..9\n");
        return 2;
    }

    /** @var array{reference: array<string, array<string, float>>, candidate: array<string, array<string, float>>} $best */
    $best = ['reference' => [], 'candidate' => []];
    $hosts = [];
    for ($round = 1; $round <= $rounds; $round++) {
        foreach (RATCHET_CASES as $index => $name) {
            $order = ($round + $index) % 2 === 0 ? ['reference', 'candidate'] : ['candidate', 'reference'];
            foreach ($order as $side) {
                $run = measureModule($side === 'reference' ? $reference : $candidate, $name);
                if (isset($hosts[$side]) && $hosts[$side] !== $run['host']) {
                    fwrite(STDERR, "FAIL: the {$side} build reported a different host between runs; not comparable\n");
                    return 2;
                }
                $hosts[$side] = $run['host'];
                foreach ($run['cases'][$name] as $metric => $value) {
                    $best[$side][$name][$metric] = min($best[$side][$name][$metric] ?? INF, $value);
                }
            }
        }
        printf("round %d/%d done\n", $round, $rounds);
    }

    if (!isset($hosts['reference'], $hosts['candidate'])) {
        fwrite(STDERR, "FAIL: no measurements were taken\n");
        return 2;
    }
    foreach (['php', 'arch'] as $key) {
        if ($hosts['reference'][$key] !== $hosts['candidate'][$key]) {
            fwrite(STDERR, "FAIL: builds differ in {$key}; not comparable\n");
            return 2;
        }
    }
    $referenceTiers = array_map('trim', explode(',', $hosts['reference']['compiled']));
    $missing = array_diff($referenceTiers, array_map('trim', explode(',', $hosts['candidate']['compiled'])));
    if ($missing !== []) {
        fwrite(STDERR, 'FAIL: candidate lacks compiled tiers: ' . implode(', ', $missing) . "\n");
        return 2;
    }
    $referenceIsa = tiersWithin($hosts['reference']['isa'], $referenceTiers);
    $candidateIsa = tiersWithin($hosts['candidate']['isa'], $referenceTiers);
    if ($referenceIsa !== $candidateIsa) {
        fwrite(STDERR, sprintf(
            "FAIL: usable tiers differ (reference %s, candidate %s)\n",
            implode(',', $referenceIsa),
            implode(',', $candidateIsa),
        ));
        return 2;
    }

    hline('PARITY: candidate vs reference, best of ' . $rounds);
    printf(
        "reference tiers: %s\ncandidate tiers: %s\n",
        $hosts['reference']['compiled'],
        $hosts['candidate']['compiled'],
    );
    printf("%-24s %-18s %11s %11s %5s  %s\n", 'case', 'metric', 'reference', 'candidate', 'band', 'verdict');
    echo str_repeat('-', 84) . "\n";
    $regressions = 0;
    foreach (RATCHET_CASES as $name) {
        foreach ($best['reference'][$name] as $metric => $base) {
            $value = $best['candidate'][$name][$metric] ?? null;
            $band = RATCHET_BANDS[$metric] ?? null;
            if ($value === null || $band === null) {
                fwrite(STDERR, "FAIL: metric {$metric} of {$name} is missing from the candidate or has no band\n");
                return 2;
            }
            $verdict = $value > $base * $band ? 'REGRESSION' : 'ok';
            $regressions += $verdict === 'ok' ? 0 : 1;
            printf("%-24s %-18s %11.2f %11.2f %5.2f  %s\n", $name, $metric, $base, $value, $band, $verdict);
        }
    }
    if ($regressions > 0) {
        printf("\nPARITY FAILED: %d regression(s)\n", $regressions);
        return 1;
    }
    printf("\nPARITY PASSED: %d case(s), 0 regressions\n", count(RATCHET_CASES));
    return 0;
}

$suite = $argv[1] ?? 'quick';
if (!in_array($suite, ['quick', 'full', 'ratchet', 'measure', 'parity'], true)) {
    fwrite(STDERR, "FAIL: unknown suite '{$suite}' (quick | full | ratchet | measure | parity)\n");
    exit(2);
}

if ($suite === 'ratchet') {
    exit(runRatchet(in_array('--record', $argv, true)));
}

if ($suite === 'measure') {
    exit(runMeasure($argv[2] ?? ''));
}

if ($suite === 'parity') {
    if (!isset($argv[2], $argv[3])) {
        fwrite(STDERR, "usage: php tools/benchmark.php parity <reference.so> <candidate.so> [rounds]\n");
        exit(2);
    }
    exit(runParity($argv[2], $argv[3], (int) ($argv[4] ?? 3)));
}

hline('usearch-php benchmark');
printf("PHP %s | extension %s\n", PHP_VERSION, Index::version());
printf("CPU ISA (compiled): %s\n", Index::hardwareAccelerationCompiled());
printf("CPU ISA (host):     %s\n", Index::hardwareAccelerationAvailable());
printf("memory_limit:       %s\n", ini_get('memory_limit'));

/* ---------------------------------------------------------------- search */
$cases = $suite === 'full'
    ? [[1000, 128], [10000, 128], [10000, 768], [100000, 128], [100000, 768], [50000, 1536]]
    : [[1000, 128], [10000, 768], [100000, 768]];

hline('SEARCH THROUGHPUT  (index build + query latency)');
printf(
    "%-12s %-7s %-11s %-13s %-11s %-10s %s\n",
    'vectors',
    'dims',
    'gen (PHP)',
    'add rate',
    'query',
    'qps',
    'native mem'
);
echo str_repeat('-', 74) . "\n";

foreach ($cases as [$n, $d]) {
    /* Generate the corpus first and time it separately. Folding this into the
     * build figure would measure PHP's sin() throughput, not the extension's
     * insert path — the two are three orders of magnitude apart. */
    $tg0 = microtime(true);
    $vectors = makeVectors($n, $d);
    $tg1 = microtime(true);
    $genSec = $tg1 - $tg0;

    $t0 = microtime(true);
    $idx = new Index(['dimensions' => $d, 'metric' => Metric::Cosine, 'connectivity' => 16]);
    $idx->reserve($n);
    foreach ($vectors as $k => $v) {
        $idx->add($k, $v);
    }
    $t1 = microtime(true);
    $buildSec = $t1 - $t0;

    $query = $vectors[0];
    for ($r = 0; $r < 50; $r++) {
        $idx->search($query, 10);
    }
    $reps = max(200, (int) (2000000 / max(1, $n)));
    $t2 = microtime(true);
    for ($r = 0; $r < $reps; $r++) {
        $idx->search($query, 10);
    }
    $t3 = microtime(true);

    $perQuery = ($t3 - $t2) / $reps;

    printf(
        "%-12s %-7s %-11s %-13s %-11s %-10s %s\n",
        number_format($n),
        $d,
        sprintf('%.2fs', $genSec),
        number_format($n / $buildSec) . '/s',
        sprintf('%.0f us', $perQuery * 1e6),
        number_format(1 / $perQuery),
        bytes($idx->memoryUsage())
    );

    unset($idx, $vectors);
    gc_collect_cycles();
}

/* ----------------------------------------------------------------- memory */
hline('MEMORY: heap load vs memory-mapped view');
$tmp = tempnam(sys_get_temp_dir(), 'usearch-bench-');
$n = 200000;
$d = 128;
$idx = new Index(['dimensions' => $d, 'metric' => Metric::Cosine]);
$idx->reserve($n);
for ($k = 0; $k < $n; $k++) {
    $v = [];
    for ($j = 0; $j < $d; $j++) {
        $v[] = (($k * 31 + $j * 17) % 1000) / 1000.0;
    }
    $idx->add($k, $v);
}
$idx->save($tmp);
$fileSize = filesize($tmp);
if ($fileSize === false) {
    fwrite(STDERR, "FAIL: cannot stat {$tmp}\n");
    exit(1);
}
printf("index: %s vectors, %d dims, serialized file %s\n", number_format($n), $d, bytes($fileSize));
unset($idx);
gc_collect_cycles();

$beforeView = rss()['rss'];
$view = new Index(['dimensions' => $d]);
$view->view($tmp);
$afterView = rss()['rss'];
$q = array_fill(0, $d, 0.5);
$t0 = microtime(true);
for ($r = 0; $r < 2000; $r++) {
    $view->search($q, 10);
}
$t1 = microtime(true);
$viewDelta = $afterView > $beforeView ? $afterView - $beforeView : 0;
printf(
    "view()  RSS delta: %-12s search %6.0f us/q (%s vectors visible)\n",
    bytes($viewDelta),
    ($t1 - $t0) / 2000 * 1e6,
    number_format($view->size())
);
unset($view);
gc_collect_cycles();

$beforeLoad = rss()['rss'];
$loaded = new Index(['dimensions' => $d]);
$loaded->load($tmp);
$afterLoad = rss()['rss'];
$t0 = microtime(true);
for ($r = 0; $r < 2000; $r++) {
    $loaded->search($q, 10);
}
$t1 = microtime(true);
$loadDelta = $afterLoad > $beforeLoad ? $afterLoad - $beforeLoad : 0;
printf(
    "load()  RSS delta: %-12s search %6.0f us/q\n",
    bytes($loadDelta),
    ($t1 - $t0) / 2000 * 1e6
);
unset($loaded);
gc_collect_cycles();
@unlink($tmp);

/* --------------------------------------------------------- quantization */
hline('QUANTIZATION: memory vs latency trade-off  (100k x 768)');
printf("%-10s %-10s %-14s %-14s %s\n", 'format', 'metric', 'memory', 'per-vector', 'query');
echo str_repeat('-', 74) . "\n";
foreach ([\Usearch\Scalar::F32, \Usearch\Scalar::F16, \Usearch\Scalar::I8, \Usearch\Scalar::B1] as $scalar) {
    $n = 100000;
    $dd = 768;
    $metric = $scalar === \Usearch\Scalar::B1 ? Metric::Hamming : Metric::Cosine;
    $q2 = new Index(['dimensions' => $dd, 'metric' => $metric, 'quantization' => $scalar]);
    $q2->reserve($n);
    $v = array_fill(0, $dd, 0.5);
    for ($k = 0; $k < $n; $k++) {
        $q2->add($k, $v);
    }
    for ($r = 0; $r < 30; $r++) {
        $q2->search($v, 10);
    }
    $t0 = microtime(true);
    for ($r = 0; $r < 300; $r++) {
        $q2->search($v, 10);
    }
    $t1 = microtime(true);
    printf(
        "%-10s %-10s %-14s %-14s %.0f us\n",
        $scalar->name,
        $metric->name,
        bytes($q2->memoryUsage()),
        number_format($q2->memoryUsage() / $n) . ' B',
        ($t1 - $t0) / 300 * 1e6
    );
    unset($q2);
    gc_collect_cycles();
}

/* ------------------------------------------------------------ xml/CSV-ish */
hline('METRIC SPACES  (10k x 256)');
printf("%-14s %s\n", 'metric', 'query');
echo str_repeat('-', 74) . "\n";
$n = 10000;
$d = 256;
$vecs = [];
for ($k = 0; $k < $n; $k++) {
    $v = [];
    for ($j = 0; $j < $d; $j++) {
        $v[] = (($k * 7 + $j * 13) % 251) / 251.0;
    }
    $vecs[] = $v;
}
foreach (\Usearch\Metric::cases() as $metric) {
    if ($metric === \Usearch\Metric::Haversine) {
        continue; /* needs 2-d geo coordinates */
    }
    try {
        $m = new Index(['dimensions' => $d, 'metric' => $metric]);
        $m->reserve($n);
        foreach ($vecs as $k => $v) {
            $m->add($k, $v);
        }
        for ($r = 0; $r < 20; $r++) {
            $m->search($vecs[0], 10);
        }
        $t0 = microtime(true);
        for ($r = 0; $r < 200; $r++) {
            $m->search($vecs[0], 10);
        }
        $t1 = microtime(true);
        printf("%-14s %.0f us\n", $metric->name, ($t1 - $t0) / 200 * 1e6);
        unset($m);
    } catch (\Throwable $e) {
        printf("%-14s unsupported: %s\n", $metric->name, $e->getMessage());
    }
    gc_collect_cycles();
}

$peak = rss()['peak'];
hline('SUMMARY');
printf("peak process RSS during run: %s\n", bytes($peak));
printf("extension: %s\n", Index::version());
printf("dispatch: %s\n", Index::hardwareAccelerationAvailable());
