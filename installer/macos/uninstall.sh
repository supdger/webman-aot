#!/bin/sh

set -eu

aot_home="${HOME}/Library/Application Support/webman-aot"
bin_dir="${HOME}/.local/bin"
purge=0
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
        --purge)
            purge=1
            ;;
        --no-path)
            update_path=0
            ;;
        *)
            echo "Unknown uninstaller option: $1" >&2
            exit 64
            ;;
    esac
    shift
done

case "$aot_home" in
    ''|'/'|"$HOME")
        echo "Refusing unsafe Webman AOT home: $aot_home" >&2
        exit 78
        ;;
esac

rm -f -- "$bin_dir/webman-aot"
if [ "$purge" -eq 1 ]; then
    rm -rf -- "$aot_home"
else
    rm -rf -- "$aot_home/current" "$aot_home/versions" "$aot_home/.install-candidates"
fi

if [ "$update_path" -eq 1 ] && [ -f "${HOME}/.zprofile" ]; then
    temporary="${HOME}/.zprofile.webman-aot.$$"
    awk '
        $0 == "# >>> webman-aot >>>" { skip = 1; next }
        $0 == "# <<< webman-aot <<<" { skip = 0; next }
        !skip { print }
    ' "${HOME}/.zprofile" >"$temporary"
    mv "$temporary" "${HOME}/.zprofile"
fi

echo "Webman AOT uninstalled."
