# Security Policy

## Supported versions

The extension is validated on PHP 8.3, 8.4, and 8.5 (NTS builds). Security fixes
land on `main` and ship in the next release; there are no long-lived support
branches yet.

## Reporting a vulnerability

**Do not open a public issue for a security report.**

Use GitHub's private vulnerability reporting on this repository
(Security → Report a vulnerability). Reports are triaged as they arrive; you
will get a response with an assessment and, once confirmed, a fix timeline.

Include what you can of: the affected version (`Usearch\Index::version()`),
a minimal script that triggers the issue, and the deployment posture (do the
index files or query vectors come from untrusted sources?).

## What is in scope

- Malformed or adversarial index files causing a crash, hang, unbounded
  allocation, or information disclosure through `load()` / `view()`.
- Corrupt or truncated vector data reaching the native core through `add()`,
  `search()`, or `distance()`.
- The read-only guarantee on `view()` indexes failing open.
- Native faults: segfaults, C++ exceptions escaping into PHP, use-after-free,
  or double-free anywhere in the extension boundary.
- Quantization surprises that turn into correctness bugs: the B1 threshold
  rule, scalar-kind conversions, metric/quantization combinations upstream
  does not implement.

## What is out of scope

- Denial of service through `memory_limit` exhaustion under PHP's own
  accounting (size `memory_limit` for your deployment).
- Approximate-search recall being lower than exact search by design — HNSW is
  approximate; the recall gate pins a floor, not exactness.
- Vulnerabilities in PHP itself, or in upstream USearch that reproduce
  identically in the upstream CLI — those belong upstream
  (https://github.com/unum-cloud/usearch/security), though we appreciate a
  heads-up.

## Guidance for untrusted input

Before loading index files or accepting vectors from untrusted sources, read
[docs/security.md](docs/security.md): treat `load()`/`view()` input as
hostile, keep `memory_limit` sized for your worst query, and prefer `view()`
for read-only serving so a corrupted file cannot be written back.
