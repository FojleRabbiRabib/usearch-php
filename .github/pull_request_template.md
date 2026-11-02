## Description

<!-- Provide a concise summary of the changes introduced by this pull request. -->

## Type of Change

- [ ] `feat`: New feature or capability
- [ ] `fix`: Bug fix
- [ ] `test`: New or updated tests / verification gates
- [ ] `perf`: Performance improvement or benchmark
- [ ] `docs`: Documentation updates
- [ ] `build` / `ci`: Build system, packaging, or CI workflow changes

## Verification Checklist

- [ ] All phpt tests pass (`php tools/test-phpt.php`)
- [ ] Metric correctness gate passes (`php -n -d extension=modules/usearch.so tests/quality/metrics.php`)
- [ ] Memory churn gate passes with 0 leaks under Valgrind
- [ ] Search recall quality gate passes (`tests/quality/recall.php`)
- [ ] Cross-language interop gate passes (`tests/quality/interop.php`)
- [ ] Benchmark regression ratchet passes (`tools/benchmark.php ratchet`)
- [ ] C/C++ formatting matches `.clang-format` (zero violations)
- [ ] Static analysis passes (`vendor/bin/phpcs` and `vendor/bin/phpstan analyse`)
- [ ] No private or planning identifiers in tracked files
