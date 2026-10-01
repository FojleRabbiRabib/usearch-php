dnl usearch-php extension configuration.
dnl
dnl A plain `phpize && ./configure && make && make install` — the path PIE and
dnl PECL run — never invokes an external build tool, so this file drives the
dnl whole build itself: it checks the toolchain here (where build-environment
dnl checks belong), compiles the vendored USearch C++ core as one translation
dnl unit alongside the extension sources, and links the result into usearch.so.

PHP_ARG_ENABLE([usearch],
  [whether to enable the usearch extension],
  [AS_HELP_STRING([--enable-usearch],
    [Enable usearch (vector similarity search on a vendored USearch core)])],
  [no])

if test "$PHP_USEARCH" != "no"; then

  dnl The vendored core lives in a pinned, hash-verified tree that is gitignored
  dnl and fetched only by tools/fetch-usearch.sh. Without this check a clean
  dnl checkout dies mid-make with a cryptic "file not found" from the C++
  dnl compiler. The version is a configure-time constant rather than an
  dnl environment lookup so a source build is reproducible by default.
  USEARCH_VERSION="${USEARCH_VERSION:-2.26.2}"
  USEARCH_VENDOR_DIR="${USEARCH_VENDOR_DIR:-$abs_srcdir/vendor-src/USearch-$USEARCH_VERSION}"
  if test ! -f "$USEARCH_VENDOR_DIR/c/usearch.h"; then
    AC_MSG_ERROR([vendored USearch source is missing at $USEARCH_VENDOR_DIR. It is fetched and SHA-256-verified by tools/fetch-usearch.sh; run that once (or fetch it there) before building.])
  fi

  dnl NumKong and StringZilla are git submodules of USearch and arrive empty in
  dnl the release tarball; they are fetched separately by the same script.
  for dep in "numkong/include/numkong/numkong.h" "stringzilla/include/stringzilla/stringzilla.h"; do
    if test ! -f "$USEARCH_VENDOR_DIR/$dep"; then
      AC_MSG_ERROR([vendored USearch dependency missing at $USEARCH_VENDOR_DIR/$dep. Run tools/fetch-usearch.sh to fetch and verify it.])
    fi
  done

  dnl USearch's C++ core is C++11 at minimum and takes advantage of later
  dnl standards where available; ask for C++17 and let the macro fall back.
  PHP_REQUIRE_CXX()
  PHP_CXX_COMPILE_STDCXX([17], [mandatory])

  dnl Headers: the extension's own bridge header, the upstream C API, and the
  dnl two dependency include roots the core pulls in.
  PHP_ADD_INCLUDE([$abs_srcdir])
  PHP_ADD_INCLUDE([$USEARCH_VENDOR_DIR])
  PHP_ADD_INCLUDE([$USEARCH_VENDOR_DIR/c])
  PHP_ADD_INCLUDE([$USEARCH_VENDOR_DIR/include])
  PHP_ADD_INCLUDE([$USEARCH_VENDOR_DIR/numkong/include])
  PHP_ADD_INCLUDE([$USEARCH_VENDOR_DIR/stringzilla/include])

  dnl The C hardening belongs here, not only in tools/build.sh: this configure
  dnl path is the canonical artifact path, so a PIE/PECL/distro source build must
  dnl produce the same hardened object as the driver. No -O2 is added here —
  dnl PHP's own default CFLAGS already carry it, and adding it would override a
  dnl DEBUG build's -O0.
  USEARCH_HARDEN_CFLAGS="-fstack-protector-strong -fvisibility=hidden"

  dnl Stack-clash protection where the toolchain supports the flag. Apple clang
  dnl accepts the flag on arm64 but warns "argument unused"; the extension builds
  dnl with -Werror, so the probe must too, or a warning-level rejection becomes a
  dnl hard build failure.
  AC_MSG_CHECKING([whether the C toolchain supports -fstack-clash-protection])
  usearch_save_CFLAGS="$CFLAGS"
  CFLAGS="$CFLAGS -Werror -fstack-clash-protection"
  AC_COMPILE_IFELSE(
    [AC_LANG_PROGRAM([], [[return 0;]])],
    [usearch_clash=yes], [usearch_clash=no])
  CFLAGS="$usearch_save_CFLAGS"
  AC_MSG_RESULT([$usearch_clash])
  if test "$usearch_clash" = "yes"; then
    USEARCH_HARDEN_CFLAGS="$USEARCH_HARDEN_CFLAGS -fstack-clash-protection"
  fi

  dnl _FORTIFY_SOURCE=3 where the toolchain supports it, =2 otherwise. The -U is
  dnl required: distro GCC predefines it, and redefining to a different value is
  dnl a diagnostic on its own.
  AC_MSG_CHECKING([whether the C toolchain supports _FORTIFY_SOURCE=3])
  usearch_save_CFLAGS="$CFLAGS"
  CFLAGS="$CFLAGS -O2 -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=3"
  AC_COMPILE_IFELSE(
    [AC_LANG_PROGRAM([[#include <string.h>]],
      [[char b[16]; strcpy(b, "abc"); return (int)strlen(b);]])],
    [USEARCH_FORTIFY=3], [USEARCH_FORTIFY=2])
  CFLAGS="$usearch_save_CFLAGS"
  AC_MSG_RESULT([$USEARCH_FORTIFY])
  USEARCH_HARDEN_CFLAGS="$USEARCH_HARDEN_CFLAGS -U_FORTIFY_SOURCE -D_FORTIFY_SOURCE=$USEARCH_FORTIFY"

  dnl CET is an x86-64 facility on ELF platforms; applying it on another Tier 1
  dnl target is meaningless, and Apple clang (darwin x86_64) rejects the flag.
  case "$host_cpu:$host_os" in
    x86_64:darwin*|amd64:darwin*) ;;
    x86_64:*|amd64:*) USEARCH_HARDEN_CFLAGS="$USEARCH_HARDEN_CFLAGS -fcf-protection=full" ;;
  esac

  dnl The vendored core is compiled through the bridge translation unit rather
  dnl than as a bare .cpp in the source list: the PHP build system computes an
  dnl object path by stripping at the first dot, so a filename carrying a version
  dnl number would collide with a sibling. The bridge also isolates the -Werror
  dnl bar from upstream's own warning profile.
  dnl
  dnl The flag list here is passed per-source; the include roots come from the
  dnl PHP_ADD_INCLUDE calls above, so the bridge resolves `c/lib.cpp` against the
  dnl vendored tree without hard-coding a version in the source.
  dnl
  dnl The source path is `.` on purpose. PHP_ADD_SOURCES_X expands `$1` into
  dnl `case $1 in` *without* m4 quoting, so an empty argument produces
  dnl `case  in` — a shell syntax error — and an absolute path has its leading
  dnl slash stripped (`cut -c 2-`), leaving an unroutable relative build dir.
  dnl `.` is the quoted form of the in-tree convention: ac_srcdir becomes
  dnl `$abs_srcdir/.`/ and ac_bdir `./`, which both resolve correctly.
  USEARCH_BRIDGE_FLAGS="-std=c++17 -Wall -Wextra -Werror -Wno-unused-parameter -Wno-maybe-uninitialized -Wno-unknown-pragmas"

  dnl Hardware-accelerated metrics. Upstream ships its SIMD kernels as a
  dnl dynamic-dispatch library: `c/numkong.c` plus one `dispatch_<dtype>.c` per
  dnl scalar type. Without it, every metric falls back to the auto-vectorised
  dnl serial path and `hardware_acceleration()` reports "serial" — measured 4x
  dnl slower on a 768-d cosine kernel (992 ns vs 247 ns per call).
  dnl
  dnl Each kernel enables its own ISA internally through `#pragma GCC target`,
  dnl gated by an `NK_TARGET_*` macro, so the only work here is to decide which
  dnl targets this toolchain can emit and pass the matching defines. The probe
  dnl mirrors upstream CMake's pass 1 ("compile all the compiler supports"), not
  dnl pass 2 (build-host-only): a shipped binary must dispatch at runtime on
  dnl whatever CPU loads it, and the extension itself stays at the x86-64 base
  dnl ISA. Rejecting an unsupported target yields `-DNK_TARGET_X=0`, which
  dnl compiles the kernel out rather than breaking the build.
  USEARCH_ISA_DEFS="-DUSEARCH_USE_NUMKONG=1 -DNK_DYNAMIC_DISPATCH=1"
  USEARCH_ISA_ENABLED=""
  usearch_save_CFLAGS="$CFLAGS"
  for usearch_isa in \
    "HASWELL:-mavx2 -mfma -mf16c" \
    "SKYLAKE:-mavx512f -mavx512bw -mavx512dq -mavx512vl" \
    "ICELAKE:-mavx512vnni -mavx512vl" \
    "GENOA:-mavx512bf16 -mavx512vl" \
    "SAPPHIRE:-mavx512fp16 -mavx512vl" \
    "TURIN:-mavx512vp2intersect" \
    "ALDER:-mavxvnni" \
    "SIERRA:-mavxvnniint8"; do
    usearch_isa_name=`echo "$usearch_isa" | cut -d: -f1`
    usearch_isa_flags=`echo "$usearch_isa" | cut -d: -f2-`
    CFLAGS="$usearch_save_CFLAGS $usearch_isa_flags -Werror"
    AC_COMPILE_IFELSE([AC_LANG_PROGRAM([], [[return 0;]])],
      [USEARCH_ISA_DEFS="$USEARCH_ISA_DEFS -DNK_TARGET_$usearch_isa_name=1"
       USEARCH_ISA_ENABLED="$USEARCH_ISA_ENABLED $usearch_isa_name"],
      [USEARCH_ISA_DEFS="$USEARCH_ISA_DEFS -DNK_TARGET_$usearch_isa_name=0"])
  done
  CFLAGS="$usearch_save_CFLAGS"
  AC_MSG_CHECKING([which CPU ISA targets this toolchain can emit])
  AC_MSG_RESULT([$USEARCH_ISA_ENABLED])

  dnl The bridge sees the same defines, so its headers declare the dispatched
  dnl kernel API and route calls through it instead of the serial fallback.
  USEARCH_BRIDGE_FLAGS="$USEARCH_BRIDGE_FLAGS $USEARCH_ISA_DEFS"
  dnl The source path is `.` on purpose. PHP_ADD_SOURCES_X expands `$1` into
  dnl `case $1 in` *without* m4 quoting, so an empty argument produces
  dnl `case  in` — a shell syntax error — and an absolute path has its leading
  dnl slash stripped (`cut -c 2-`), leaving an unroutable relative build dir.
  dnl `.` is the quoted form of the in-tree convention: ac_srcdir becomes
  dnl `$abs_srcdir/.`/ and ac_bdir `./`, which both resolve correctly.
  PHP_ADD_SOURCES_X([.], [usearch_bridge.cpp],
    [$USEARCH_BRIDGE_FLAGS $USEARCH_HARDEN_CFLAGS], shared_objects_usearch, yes)

  dnl The kernel sources stay pristine in the vendored tree: PHP_ADD_SOURCES_X
  dnl takes a relative source dir, so objects land beside the sources in the
  dnl already-gitignored vendor-src/ tree. Upstream's warning profile applies —
  dnl no -Werror here, unlike our own translation units.
  dnl
  dnl Filename note: `dispatch_e2m3.c` and friends carry no extra dots, so the
  dnl first-dot object naming that forbids versioned bridge filenames is safe.
  PHP_ADD_SOURCES_X([vendor-src/USearch-$USEARCH_VERSION/numkong/c], [numkong.c parallel.c dispatch_bf16.c dispatch_bf16c.c dispatch_e2m3.c dispatch_e3m2.c dispatch_e4m3.c dispatch_e5m2.c dispatch_f16.c dispatch_f16c.c dispatch_f32.c dispatch_f32c.c dispatch_f64.c dispatch_f64c.c dispatch_i16.c dispatch_i32.c dispatch_i4.c dispatch_i64.c dispatch_i8.c dispatch_other.c dispatch_u16.c dispatch_u1.c dispatch_u32.c dispatch_u4.c dispatch_u64.c dispatch_u8.c],
    [$USEARCH_ISA_DEFS], shared_objects_usearch, yes)

  PHP_NEW_EXTENSION([usearch], [usearch.c usearch_index.c usearch_vector.c usearch_enums.c usearch_exception.c], [$ext_shared],,
    [-Wall -Wextra -Werror -Wformat -Wformat-security $USEARCH_HARDEN_CFLAGS], [cxx])

  dnl Link hardening and the export map, so the canonical link hides the internal
  dnl symbols and matches tools/build.sh's relink. On ELF (Linux/musl)
  dnl `usearch.map` exports exactly `get_module`; on Mach-O (macOS) ld64 takes
  dnl `-exported_symbols_list` over `usearch.exp` (same single entry), with
  dnl `-dead_strip` and `-bind_at_load` in place of the GNU -z pair.
  dnl
  dnl `-undefined dynamic_lookup` is required on Mach-O: unlike ELF, ld64 resolves
  dnl every undefined symbol at link time unless told otherwise, and the Zend API
  dnl symbols this extension calls are provided by the php binary that loads it,
  dnl not by any library on the link line.
  case "$host_os" in
    darwin*)
      USEARCH_EXPORTS_LIST="$abs_srcdir/usearch.exp"
      if test ! -f "$USEARCH_EXPORTS_LIST"; then
        AC_MSG_ERROR([usearch.exp not found at $USEARCH_EXPORTS_LIST; it is part of the extension sources])
      fi
      USEARCH_SHARED_LIBADD="$USEARCH_SHARED_LIBADD -Wl,-dead_strip -Wl,-bind_at_load -Wl,-undefined,dynamic_lookup -Wl,-exported_symbols_list,$USEARCH_EXPORTS_LIST"
      ;;
    *)
      USEARCH_LINK_SCRIPT="$abs_srcdir/usearch.map"
      if test ! -f "$USEARCH_LINK_SCRIPT"; then
        AC_MSG_ERROR([usearch.map not found at $USEARCH_LINK_SCRIPT; it is part of the extension sources])
      fi
      USEARCH_SHARED_LIBADD="$USEARCH_SHARED_LIBADD -Wl,--version-script=$USEARCH_LINK_SCRIPT -Wl,--gc-sections -Wl,-z,relro,-z,now,-z,noexecstack"
      ;;
  esac

  PHP_SUBST(USEARCH_VENDOR_DIR)
  PHP_SUBST(USEARCH_SHARED_LIBADD)
fi
