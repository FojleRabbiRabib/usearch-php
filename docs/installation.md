# Installation Guide — usearch-php

This extension provides in-process vector similarity search (HNSW) for PHP 8.3, 8.4, and 8.5,
backed by a statically linked build of USearch 2.26.2.

---

## 1. Using PIE (Recommended)

[PIE (PHP Installer for Extensions)](https://github.com/php/pie) is the official modern replacement
for PECL.

```bash
pie install usearch-php/usearch
```

PIE will automatically match and download the pre-packaged binary archive for your exact PHP version,
architecture, and libc flavour (`glibc` or `musl`).

---

## 2. Compiling from Source

### Prerequisites
- PHP 8.3, 8.4, or 8.5 with development headers (`php-dev` or `php8.x-dev`)
- A C++17-capable compiler (`g++` >= 9 or `clang++` >= 10)
- `curl`, `tar`, and `sha256sum` to fetch the verified vendored USearch tree

### Build steps

```bash
# 1. Clone the repository
git clone https://github.com/FojleRabbiRabib/usearch-php.git
cd usearch-php

# 2. Fetch and SHA-256 verify the pinned USearch core + submodules
./tools/fetch-usearch.sh

# 3. Configure and build
phpize
./configure --enable-usearch
make -j$(nproc)

# 4. Run tests
php tools/test-phpt.php

# 5. Install
sudo make install
```

### Enable the extension

Add the extension directive to your `php.ini` or `/etc/php/8.x/mods-available/usearch.ini`:

```ini
extension=usearch.so
```

Verify that the extension is active:

```bash
php -m | grep usearch
php -r 'echo Usearch\Index::version() . PHP_EOL;'
```

---

## 3. Prebuilt Binaries (Manual Install)

GitHub Releases publishes standalone per-ABI `.so` binaries alongside the PIE archives:

```bash
curl -fsSL -o /tmp/usearch.so https://github.com/FojleRabbiRabib/usearch-php/releases/download/v0.1.0/usearch-php8.3-linux-x86_64.so
sudo install -m 0755 /tmp/usearch.so $(php-config --extension-dir)/usearch.so
echo "extension=usearch.so" | sudo tee /etc/php/8.3/mods-available/usearch.ini
```

---

## 4. Minimum glibc floor

Prebuilt Linux binaries require **glibc 2.34 or newer** (Ubuntu 22.04+, Debian 12+, RHEL 9+; the
provenance record shipped with every release names the exact symbol floor). Older systems should
build from source.
