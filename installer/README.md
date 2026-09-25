# User-private installers

The installer archives carry their own PHP runtime and install only into the
current user's profile. They do not invoke Homebrew, winget, Docker, sudo, an
elevated PowerShell process, or a system PHP executable.

Runtime inputs are pinned in `runtime.lock.json`. Produce both archives with:

```sh
php tools/package-installers.php \
  --mac-runtime=/path/to/static-php \
  --mac-compiler-driver=/path/to/php-compiler \
  --mac-runtime-license-dir=/path/to/static-php-licenses \
  --windows-runtime-archive=/path/to/tpc_v0.9.2_windows_x64.zip \
  --output=dist/installers \
  --revision="$(git rev-parse HEAD)"
```

The packager rejects runtime inputs whose SHA-256 digest does not match the
lock. Each archive also contains a payload manifest which the installer
verifies before it changes the current user's installation.

The macOS package targets Apple Silicon. The Windows package targets x64.
