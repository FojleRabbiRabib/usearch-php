#!/usr/bin/env php
<?php

/**
 * PHPT test runner for usearch-php.
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 *
 * Executes every *.phpt in tests/phpt/ against a specific PHP binary, checking
 * that the --EXPECTF-- block matches the output exactly. Usage:
 *
 *   php tools/test-phpt.php [path-to-php-binary]
 *
 * Exits 0 when every case passes, 1 on the first failure.
 */

declare(strict_types=1);

const EXTENSION_FLAG = '-d';
const EXTENSION_LINE = 'extension=%s/usearch.so';

/**
 * @return array<string, string>
 */
function phpt_sections(string $body): array
{
    $sections = [];
    $current = null;
    $lines = preg_split('/\r?\n/', $body);
    if ($lines === false) {
        return $sections;
    }
    foreach ($lines as $line) {
        if (preg_match('/^--[A-Z]+--\s*$/', $line) === 1) {
            $current = trim($line, "- \t");
            $sections[$current] = '';
            continue;
        }
        if ($current !== null) {
            $sections[$current] .= $line . "\n";
        }
    }
    return $sections;
}

/**
 * @param string $phpBinary PHP binary to run the cases against.
 */
function main(string $phpBinary): int
{
    $root = dirname(__DIR__);
    $moduleDir = $root . '/modules';
    if (!is_file($moduleDir . '/usearch.so')) {
        fwrite(STDERR, "FAIL: {$moduleDir}/usearch.so not found; build the extension first\n");
        return 1;
    }

    $cases = glob(__DIR__ . '/../tests/phpt/*.phpt');
    if ($cases === false || $cases === []) {
        fwrite(STDERR, "FAIL: no phpt cases found\n");
        return 1;
    }
    sort($cases);

    $passed = 0;
    $failed = 0;
    foreach ($cases as $case) {
        $raw = file_get_contents($case);
        if ($raw === false) {
            fwrite(STDERR, "FAIL: cannot read {$case}\n");
            return 1;
        }
        $sections = phpt_sections($raw);
        if (!isset($sections['FILE'], $sections['EXPECTF'])) {
            printf("SKIP %-52s missing FILE or EXPECTF section\n", basename($case));
            continue;
        }

        $script = tempnam(sys_get_temp_dir(), 'usearch-phpt-');
        if ($script === false) {
            fwrite(STDERR, "FAIL: tempnam failed\n");
            return 1;
        }
        file_put_contents($script, $sections['FILE']);

        $cmd = array_merge(
            [$phpBinary, '-n', EXTENSION_FLAG, sprintf(EXTENSION_LINE, $moduleDir)],
            array_map('escapeshellarg', [$script])
        );
        $cmdLine = implode(' ', $cmd);
        $captured = shell_exec($cmdLine . ' 2>&1');
        $output = $captured === false || $captured === null ? '' : $captured;
        $exitCode = 0;
        exec($cmdLine . ' > /dev/null 2>&1', $_, $exitCode);

        $expected = rtrim($sections['EXPECTF'], "\n");
        $expectedRe = '/^' . strtr(
            preg_quote($expected, '#'),
            [
                '%s' => '.*?',
                '%d' => '[+-]?\d+',
                '%i' => '[+-]?\d+',
                '%f' => '[+-]?\d*\.?\d+(?:[Ee][+-]?\d+)?',
                '%e' => "[^\r\n]*",
                '%A' => '.+',
                '%c' => '.',
            ]
        ) . '$/ms';

        $ok = preg_match($expectedRe, rtrim($output, "\n")) === 1 && $exitCode === 0;
        unlink($script);

        if ($ok) {
            $passed++;
            printf("PASS %-52s\n", basename($case));
        } else {
            $failed++;
            printf("FAIL %-52s (exit %d)\n", basename($case), $exitCode);
            echo "--- expected ---\n" . rtrim($expected, "\n") . "\n";
            echo "--- actual ---\n" . rtrim($output, "\n") . "\n";
        }
    }

    printf("\n%d passed, %d failed\n", $passed, $failed);
    return $failed === 0 ? 0 : 1;
}

$requestedBinary = $_SERVER['argv'][1] ?? PHP_BINARY;
exit(main(is_string($requestedBinary) ? $requestedBinary : PHP_BINARY));
