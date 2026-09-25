# Source licensing and upstream attribution

The original Webman AOT application code is provided under this repository's
[MIT license](LICENSE).

The files in `toolchain/patches/typephp/0.9.2/` are diffs against
[TypePHP v0.9.2](https://github.com/swoole/typephp/tree/v0.9.2).
That version declares `GPL-3.0-only` in its `composer.json`, and the diffs
include context from TypePHP source files. The repository's MIT license does
not relicense that upstream material. The patch manifest records the exact
upstream file hashes expected before each modification.

Toolchains downloaded by the CLI, locally assembled installer archives, and
generated Linux executables contain additional third-party components with
their own license terms. The v0.1.0 Mac installer includes PHP 8.4.25 built
with static-php-cli 2.8.5 and its collected runtime notices; its separate
compiler-driver uses macOS system libraries. The Windows installer includes
a selected subset of the official PHP 8.4.25 x64 distribution and its license,
redist notice, and upstream SBOM. Both installers include TypePHP's GPL-3.0
license beside the patch set. TypePHP and static SDK binaries are downloaded
on demand from the versions and SHA-256 values in `toolchain.lock.json`, not
bundled in these installers.

The release [Assets](https://github.com/supdger/webman-aot/releases/tag/v0.1.0)
contain checksums. The [verification record](docs/verification.md) describes
what was tested; a passing package check is not a claim about every target
server or third-party plugin.
