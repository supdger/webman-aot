# Source licensing and upstream attribution

The original Webman AOT application code is provided under this repository's
[MIT license](LICENSE).

The files in `toolchain/patches/typephp/0.9.2/` are diffs against
[TypePHP v0.9.2](https://github.com/swoole/typephp/tree/v0.9.2).
That version declares `GPL-3.0-only` in its `composer.json`, and the diffs
include context from TypePHP source files. The repository's MIT license does
not relicense that upstream material. The patch manifest records the exact
upstream file hashes expected before each modification. The modifications
prepared by this project as of 2026-09-26 are supplied under `GPL-3.0-only`.

Toolchains downloaded by the CLI, locally assembled installer archives, and
generated Linux executables contain additional third-party components with
their own license terms. The v0.1.0 Mac installer includes PHP 8.4.25 built
with static-php-cli 2.8.5 and its collected runtime notices; its separate
compiler-driver uses macOS system libraries. PHP's bundled `libmbfl` in the
Mac CLI and `libbcmath` in the compiler-driver use LGPL-2.1; their license
texts are included in the Mac installer. Corresponding source and relinking
materials are in a separate v0.1.0 Release asset, described in
[Mac runtime source and relinking](docs/macos-runtime-source.md).
The Windows installer includes
a selected subset of the official PHP 8.4.25 x64 distribution and its license,
redist notice, and upstream SBOM. Both installers include TypePHP's GPL-3.0
license beside the patch set. TypePHP and static SDK binaries are downloaded
on demand from the versions and SHA-256 values in `toolchain.lock.json`, not
bundled in these installers.

The release [Assets](https://github.com/supdger/webman-aot/releases/tag/v0.1.0)
contain checksums. The [verification record](docs/verification.md) describes
what was tested; a passing package check is not a claim about every target
server or third-party plugin.
