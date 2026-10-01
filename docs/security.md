# Security Guide — usearch-php

Threat model, boundary rules, and operational safeguards for in-process vector search.

---

## 1. Threat model

The extension runs in the host PHP process (CLI or php-fpm worker). Vectors and index files are
treated as **untrusted input** when they originate from callers or disk.

| Asset | Attack vector | Mitigation |
|---|---|---|
| Process memory | Corrupt/malicious index file | Header validation; size and bounds checks in upstream loader; fail-closed on corrupt metadata. |
| Host heap | Enormous capacity reservation | `reserve()` and index creation fail closed when memory allocation fails; raises `Usearch\Exception`, never terminates the process. |
| Stability | Mutation on `view()` (mmap) | Checked at the Zend layer (`read_only` guard) before any C API call; converts SIGSEGV on `PROT_READ` into a catchable exception. |
| Data integrity | Vector length mismatches | Strict length check against index `dimensions` before touching memory; raises `ValueError` with actual and expected sizes. |
| Floating-point | NaN / Inf components | Accepted and passed to upstream; the distance function determines output. Upstream bounds distance values per metric space. |
| Process safety | C++ exceptions | Wrapped in the bridge; every C++ exception is caught and converted to a `usearch_error_t` string before returning to C. **No C++ exception ever crosses into PHP.** |

---

## 2. Safe loading of untrusted index files

- **Inspect metadata first:** call `Index::metadata($path)` before `load()` or `view()`. It validates
  the file header without reading the full vector graph.
- **Prefer `view()` over `load()` for shared serving:** memory-mapped pages cannot be modified,
  and the kernel discards them under memory pressure without swapping.
- **Verify file sizes:** `serializedLength()` on a healthy index matches the file size. Abnormally
  small or truncated files fail in `usearch_load` and raise `Usearch\Exception`.

---

## 3. Resource ceilings

- Each vector requires `(dimensions * scalar_bytes) + (connectivity * 8) + overhead` bytes. A 768-dim
  index with 1,000,000 vectors under `F16` quantization consumes roughly ~2.5 GB of RAM.
- Use `memoryUsage()` to inspect true native allocations; do not rely on PHP's `memory_get_usage()`,
  which tracks Zend MM only and is blind to the C++ allocator.
- Limit max capacity on public endpoints: reject user requests asking for unbounded `count` or
  oversized `capacity` values.
