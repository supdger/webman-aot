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
their own license terms. They are not included in this Git repository. See
the [installer license preflight](https://github.com/supdger/webman-aot/blob/development/evidence/2026-09-25-exportguard-license-preflight.json)
for observed candidate-package contents; it is an inventory, not a legal
compliance determination or a binary-release approval.
