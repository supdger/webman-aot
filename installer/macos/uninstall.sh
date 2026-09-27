#!/bin/sh

set -eu

aot_home="${HOME}/Library/Application Support/webman-aot-builder"
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
        echo "Refusing unsafe Webman AOT Builder home: $aot_home" >&2
        exit 78
        ;;
esac

launcher="$bin_dir/webman-aot"
previous_launcher="$aot_home/.previous-launcher/webman-aot"
resolved_home=$(cd "$aot_home" 2>/dev/null && pwd -P || printf '%s' "$aot_home")
resolved_bin=$(cd "$bin_dir" 2>/dev/null && pwd -P || printf '%s' "$bin_dir")
bin_inside_home=0
case "$resolved_bin" in
    "$resolved_home"|"$resolved_home"/*) bin_inside_home=1 ;;
esac
launcher_present=0
launcher_owned=0
if [ -e "$launcher" ] || [ -L "$launcher" ]; then
    launcher_present=1
    if [ ! -L "$launcher" ] && [ -f "$launcher" ] &&
        grep -F 'WEBMAN_AOT_BUILDER_PUBLIC_LAUNCHER' "$launcher" >/dev/null 2>&1; then
        launcher_owned=1
    fi
fi
if [ "$purge" -eq 1 ] && [ -f "$previous_launcher" ] &&
    [ "$launcher_present" -eq 1 ] && [ "$launcher_owned" -eq 0 ]; then
    echo "Cannot purge Builder data: a previous webman-aot command is backed up while another command occupies $launcher" >&2
    exit 70
fi
if [ "$purge" -eq 1 ] && [ "$bin_inside_home" -eq 1 ] &&
    [ "$launcher_present" -eq 1 ] && [ "$launcher_owned" -eq 0 ]; then
    echo "Cannot purge Builder data while an unowned webman-aot command remains inside it: $launcher" >&2
    exit 70
fi
if [ "$launcher_owned" -eq 1 ]; then
    rm -f -- "$launcher"
    launcher_present=0
elif [ "$launcher_present" -eq 1 ]; then
    echo "Command is not owned by Webman AOT Builder; leaving it in place: $launcher"
fi
if [ "$launcher_present" -eq 0 ] && [ -f "$previous_launcher" ]; then
    mkdir -p "$bin_dir"
    mv "$previous_launcher" "$launcher"
    launcher_present=1
    echo "Previous webman-aot command restored: $launcher"
fi
obsolete_launcher="$bin_dir/webman-aot-builder"
if [ -f "$obsolete_launcher" ] && grep -F 'WEBMAN_AOT_BUILDER_HOME' "$obsolete_launcher" >/dev/null 2>&1; then
    rm -f -- "$obsolete_launcher"
fi
if [ "$purge" -eq 1 ]; then
    if [ "$bin_inside_home" -eq 1 ]; then
        if [ "$launcher_present" -eq 1 ]; then
            saved_dir=$(mktemp -d "${TMPDIR:-/tmp}/webman-aot-previous.XXXXXX")
            case "$saved_dir" in
                "$resolved_home"|"$resolved_home"/*)
                    rmdir "$saved_dir"
                    echo "Cannot preserve the previous command: temporary directory is inside Builder data." >&2
                    exit 70
                    ;;
            esac
            saved_launcher="$saved_dir/webman-aot"
            mv "$launcher" "$saved_launcher"
            restore_launcher() {
                if [ -e "$saved_launcher" ] || [ -L "$saved_launcher" ]; then
                    mkdir -p "$bin_dir"
                    mv "$saved_launcher" "$launcher"
                fi
                rmdir "$saved_dir" 2>/dev/null || :
            }
            trap restore_launcher EXIT
            trap 'exit 130' HUP INT TERM
            rm -rf -- "$aot_home"
            restore_launcher
            trap - EXIT HUP INT TERM
            echo "Preserved webman-aot command inside $bin_dir after purging Builder data."
        else
            rm -rf -- "$aot_home"
        fi
    else
        rm -rf -- "$aot_home"
    fi
else
    rm -rf -- "$aot_home/current" "$aot_home/versions" "$aot_home/.install-candidates"
fi

if [ "$update_path" -eq 1 ] && [ -f "${HOME}/.zprofile" ]; then
    temporary="${HOME}/.zprofile.webman-aot-builder.$$"
    awk '
        $0 == "# >>> webman-aot-builder >>>" { skip = 1; next }
        $0 == "# <<< webman-aot-builder <<<" { skip = 0; next }
        !skip { print }
    ' "${HOME}/.zprofile" >"$temporary"
    mv "$temporary" "${HOME}/.zprofile"
fi

echo "Webman AOT Builder uninstalled."
