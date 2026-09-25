#!/bin/sh
set -eu

# Read-only static ELF gate for an isolated Linux x86_64 acceptance host.
# It does not start the service, read .env, or access a database.
export LC_ALL=C
server=${1:-./server}

fail() {
    printf 'FAIL %s\n' "$1" >&2
    exit 1
}

test "$(uname -s)" = Linux || fail 'host is not Linux'
test "$(uname -m)" = x86_64 || fail 'host is not x86_64'
test -f "$server" && test ! -L "$server" && test -x "$server" \
    || fail 'server is missing, a symlink, or not executable'
for command in file readelf sha256sum ldd; do
    command -v "$command" >/dev/null 2>&1 \
        || fail "missing inspection command: $command"
done

header=$(readelf -hW "$server") || fail 'server is not a readable ELF'
printf '%s\n' "$header" | grep -Eq \
    'Machine:[[:space:]]*Advanced Micro Devices X86-64' \
    || fail 'ELF machine is not x86-64'
program_headers=$(readelf -lW "$server") \
    || fail 'unable to inspect ELF program headers'
if printf '%s\n' "$program_headers" \
    | grep -Eq '^[[:space:]]*INTERP[[:space:]]'; then
    fail 'ELF has a dynamic interpreter'
fi
dynamic=$(readelf -dW "$server") \
    || fail 'unable to inspect ELF dynamic section'
printf '%s\n' "$dynamic" \
    | grep -Fq 'There is no dynamic section in this file.' \
    || fail 'ELF has a dynamic section'

ldd_result=$(ldd "$server" 2>&1 || true)
case "$ldd_result" in
    *'not a dynamic executable'*|*'statically linked'*) ;;
    *) fail "ldd does not confirm a static executable: $ldd_result" ;;
esac

printf 'PASS host=%s %s\n' "$(uname -s)" "$(uname -m)"
if test -f /etc/os-release; then
    grep -E '^(ID|VERSION_ID)=' /etc/os-release || true
fi
printf 'PASS sha256=%s\n' "$(sha256sum "$server" | awk '{print $1}')"
printf 'PASS file=%s\n' "$(file -b "$server")"
printf 'PASS no ELF interpreter or dynamic section\n'
printf 'PASS ldd=%s\n' "$ldd_result"
