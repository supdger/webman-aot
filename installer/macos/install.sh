#!/bin/sh

set -eu
started_at=$(date +%s)

package_root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
aot_home="${HOME}/Library/Application Support/webman-aot"
bin_dir="${HOME}/.local/bin"
update_path=1

while [ "$#" -gt 0 ]; do
    case "$1" in
        --home)
            shift
            [ "$#" -gt 0 ] || { echo "Missing value for --home" >&2; exit 64; }
            aot_home=$1
            ;;
        --bin-dir)
            shift
            [ "$#" -gt 0 ] || { echo "Missing value for --bin-dir" >&2; exit 64; }
            bin_dir=$1
            ;;
        --no-path)
            update_path=0
            ;;
        *)
            echo "Unknown installer option: $1" >&2
            exit 64
            ;;
    esac
    shift
done

[ "$(uname -s)" = "Darwin" ] && [ "$(uname -m)" = "arm64" ] || {
    echo "This package requires macOS ARM64." >&2
    exit 78
}
case "$aot_home" in
    /|"$HOME"|"$package_root")
        echo "Unsafe Webman AOT installation directory: $aot_home" >&2
        exit 64
        ;;
esac
[ ! -L "$aot_home" ] || {
    echo "Webman AOT installation directory must not be a symbolic link." >&2
    exit 64
}

(
    cd "$package_root"
    shasum -a 256 -c payload-manifest.sha256
)
echo "Package contents SHA-256 verified."

candidate="${aot_home}/.install-candidates/install-$$"
backup=''
backup_root=''
new_current=0
new_toolchains=0
new_launcher=0
complete=0
full=0
if [ -f "$package_root/payload/minimal-toolchain/component.zip" ]; then
    full=1
fi
cleanup() {
    if [ "$full" -eq 1 ] && [ "$complete" -eq 0 ] && [ -n "$backup_root" ]; then
        echo "Full installation did not complete; restoring the previous installation." >&2
        [ "$new_launcher" -eq 0 ] || rm -f -- "$bin_dir/webman-aot"
        [ "$new_toolchains" -eq 0 ] || rm -rf -- "$aot_home/toolchains"
        [ "$new_current" -eq 0 ] || rm -rf -- "$aot_home/current"
        [ ! -f "$backup_root/webman-aot" ] || mv "$backup_root/webman-aot" "$bin_dir/webman-aot"
        [ ! -d "$backup_root/current" ] || mv "$backup_root/current" "$aot_home/current"
        [ ! -d "$backup_root/toolchains" ] || mv "$backup_root/toolchains" "$aot_home/toolchains"
        [ ! -d "$backup_root/versions" ] || mv "$backup_root/versions" "$aot_home/versions"
    fi
    if [ -d "$candidate" ]; then
        rm -rf -- "$candidate"
    fi
}
trap cleanup EXIT HUP INT TERM

mkdir -p "$candidate/current" "$aot_home/.install-backups" "$bin_dir"
if [ "$full" -eq 1 ]; then
    free_kib=$(df -Pk "$aot_home" | awk 'NR == 2 { print $4 }')
    if [ -z "$free_kib" ] || [ "$free_kib" -lt 4194304 ]; then
        echo "Full installation needs at least 4 GiB free at $aot_home; choose a larger volume." >&2
        exit 70
    fi
    echo "[install] Complete package: installing the locked minimal toolchain offline (no downloads)."
fi
cp -R "$package_root/payload/app" "$candidate/current/app"
cp -R "$package_root/payload/runtime" "$candidate/current/runtime"
chmod 700 "$candidate/current/runtime/bin/php"

WEBMAN_AOT_HOME="$candidate" \
    "$candidate/current/runtime/bin/php" -n \
    "$candidate/current/app/bin/webman-aot.php" --version >/dev/null

if [ "$full" -eq 1 ]; then
    WEBMAN_AOT_HOME="$candidate" \
        "$candidate/current/runtime/bin/php" -n \
        "$candidate/current/app/installer/offline-prepare.php" \
        "$package_root/payload/minimal-toolchain/component.zip"
    backup_root="${aot_home}/.install-backups/full-$(date -u +%Y%m%dT%H%M%SZ)-$$"
    mkdir -p "$backup_root"
    [ ! -d "$aot_home/current" ] || mv "$aot_home/current" "$backup_root/current"
    [ ! -d "$aot_home/toolchains" ] || mv "$aot_home/toolchains" "$backup_root/toolchains"
    [ ! -d "$aot_home/versions" ] || mv "$aot_home/versions" "$backup_root/versions"
    [ ! -f "$bin_dir/webman-aot" ] || mv "$bin_dir/webman-aot" "$backup_root/webman-aot"
    mv "$candidate/current" "$aot_home/current"
    new_current=1
    mv "$candidate/toolchains" "$aot_home/toolchains"
    new_toolchains=1
    cp "$package_root/payload/launcher/webman-aot" "$bin_dir/webman-aot"
    new_launcher=1
    chmod 700 "$bin_dir/webman-aot"
    WEBMAN_AOT_HOME="$aot_home" \
        "$aot_home/current/runtime/bin/php" -n \
        "$aot_home/current/app/installer/offline-prepare.php" \
        "$package_root/payload/minimal-toolchain/component.zip"
else
if [ -d "$aot_home/current" ]; then
    backup="${aot_home}/.install-backups/current-$(date -u +%Y%m%dT%H%M%SZ)-$$"
    mv "$aot_home/current" "$backup"
fi
if ! mv "$candidate/current" "$aot_home/current"; then
    if [ -n "$backup" ] && [ -d "$backup" ]; then
        mv "$backup" "$aot_home/current"
    fi
    echo "Unable to activate Webman AOT installation." >&2
    exit 70
fi

cp "$package_root/payload/launcher/webman-aot" "$bin_dir/webman-aot"
chmod 700 "$bin_dir/webman-aot"
fi

if [ "$update_path" -eq 1 ]; then
    profile="${HOME}/.zprofile"
    marker_begin='# >>> webman-aot >>>'
    if ! grep -F "$marker_begin" "$profile" >/dev/null 2>&1; then
        {
            printf '\n%s\n' "$marker_begin"
            printf 'export PATH="%s:$PATH"\n' "$bin_dir"
            printf '%s\n' '# <<< webman-aot <<<'
        } >>"$profile"
    fi
fi

complete=1
trap - EXIT HUP INT TERM
cleanup
echo "Webman AOT installed in: $aot_home"
echo "Command installed as: $bin_dir/webman-aot"
if [ "$full" -eq 1 ]; then
    echo "Complete offline toolchain ready. You may enter a Webman project and run webman-aot build."
fi
echo "Installation completed in $(($(date +%s) - started_at)) seconds."
