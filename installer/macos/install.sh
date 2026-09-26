#!/bin/sh

set -eu

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

(
    cd "$package_root"
    shasum -a 256 -c payload-manifest.sha256
)
echo "Package contents SHA-256 verified."

candidate="${aot_home}/.install-candidates/install-$$"
backup=''
cleanup() {
    if [ -d "$candidate" ]; then
        rm -rf -- "$candidate"
    fi
}
trap cleanup EXIT HUP INT TERM

mkdir -p "$candidate/current" "$aot_home/.install-backups" "$bin_dir"
cp -R "$package_root/payload/app" "$candidate/current/app"
cp -R "$package_root/payload/runtime" "$candidate/current/runtime"
chmod 700 "$candidate/current/runtime/bin/php"

WEBMAN_AOT_HOME="$candidate" \
    "$candidate/current/runtime/bin/php" -n \
    "$candidate/current/app/bin/webman-aot.php" --version >/dev/null

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

trap - EXIT HUP INT TERM
cleanup
echo "Webman AOT installed in: $aot_home"
echo "Command installed as: $bin_dir/webman-aot"
