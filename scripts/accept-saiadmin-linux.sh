#!/bin/sh
set -eu

# Isolated test only: reads the server-side captcha session instead of solving
# the image. Never run against a production session directory or account.
json_check() {
    python3 -c '
import json
import sys

try:
    response = json.loads(sys.stdin.buffer.read().decode("utf-8"))
except (UnicodeError, ValueError):
    sys.exit(1)
if not isinstance(response, dict):
    sys.exit(1)
data = response.get("data")
data = data if isinstance(data, dict) else {}
mode = sys.argv[1]
code = response.get("code")
message = response.get("message")

if mode == "captcha":
    uuid = data.get("uuid")
    image = data.get("image")
    valid = (
        type(code) is int and code == 200
        and type(data.get("result")) is int and data["result"] == 1
        and isinstance(uuid, str)
        and isinstance(image, str)
        and image.startswith("data:image/png;base64,")
    )
    if valid:
        print(uuid)
elif mode == "login":
    token = data.get("access_token")
    valid = type(code) is int and code == 200 and isinstance(token, str) and len(token) > 20
    if valid:
        print(token)
elif mode == "user":
    valid = (
        type(code) is int and code == 200
        and isinstance(data.get("username"), str)
        and data["username"] == sys.argv[2]
    )
elif mode == "denied":
    valid = (
        type(code) is int and code == 400
        and response.get("type") == "failed"
        and isinstance(message, str)
        and "\u6743\u9650\u4e0d\u8db3" in message
    )
else:
    sys.exit(2)
sys.exit(0 if valid else 1)
' "$@"
}

if [ "${1-}" = "--self-test" ]; then
    printf '%s' '{"code":200,"data":{"result":1,"uuid":"test","image":"data:image/png;base64,AA=="}}' |
        json_check captcha >/dev/null
    if printf '%s' '{"code":200,"data":{"result":1,"image":"data:image/png;base64,AA=="}}' |
        json_check captcha >/dev/null; then
        echo "captcha JSON guard accepted a missing UUID" >&2
        exit 1
    fi
    printf '%s' '{"code":200,"data":{"access_token":"123456789012345678901"}}' |
        json_check login >/dev/null
    if printf '%s' '{"code":200,"data":{"access_token":"short"}}' |
        json_check login >/dev/null; then
        echo "login JSON guard accepted a short token" >&2
        exit 1
    fi
    printf '%s' '{"code":200,"data":{"username":"isolated"}}' |
        json_check user isolated >/dev/null
    if printf '%s' '{"code":200,"data":{"username":"other"}}' |
        json_check user isolated >/dev/null; then
        echo "user JSON guard accepted a different account" >&2
        exit 1
    fi
    printf '%s' '{"code":400,"type":"failed","message":"权限不足"}' |
        json_check denied >/dev/null
    if printf '%s' '{"code":500,"type":"failed","message":"权限不足"}' |
        json_check denied >/dev/null; then
        echo "permission JSON guard accepted a server error" >&2
        exit 1
    fi
    if printf '%s' '{"code":200,"type":"success","message":"success"}' |
        json_check denied >/dev/null; then
        echo "permission JSON guard accepted an allowed response" >&2
        exit 1
    fi
    echo "PASS acceptance JSON parser"
    exit 0
fi

: "${AOT_TEST_USERNAME:?set an isolated SaiAdmin test username}"
: "${AOT_TEST_PASSWORD:?set its temporary password}"
: "${AOT_TEST_SESSION_DIR:?set the isolated distribution runtime/sessions directory}"

base_url=${AOT_TEST_BASE_URL:-http://127.0.0.1:8787}
for command in curl python3 perl awk grep; do
    command -v "$command" >/dev/null 2>&1 || {
        echo "missing acceptance dependency: $command" >&2
        exit 2
    }
done
printf '%s\n' "$base_url" | grep -Eq '^http://127\.0\.0\.1:[0-9]{1,5}$' || {
    echo "acceptance URL must be a local HTTP address with an explicit port" >&2
    exit 2
}
scratch_root=${AOT_TEST_TMPDIR:-/tmp}
scratch=$(mktemp -d "$scratch_root/webman-aot-accept.XXXXXXXX")
trap 'rm -f "$scratch/cookies"; rmdir "$scratch"' EXIT HUP INT TERM

captcha=$(curl --noproxy '*' --silent --show-error --max-time 10 \
    --cookie-jar "$scratch/cookies" "$base_url/core/captcha")
uuid=$(printf '%s' "$captcha" | json_check captcha) || {
    echo "captcha business response failed" >&2
    exit 1
}
test -d "$AOT_TEST_SESSION_DIR" || {
    echo "captcha session directory was not created" >&2
    exit 1
}
session_id=$(awk -F '\t' '$6 == "PHPSID" {print $7}' "$scratch/cookies")
case "$session_id" in
    ''|*[!a-f0-9]*)
        echo "captcha session cookie is invalid" >&2
        exit 1
        ;;
esac
session="$AOT_TEST_SESSION_DIR/session_$session_id"
test -f "$session" && test ! -L "$session" || {
    echo "captcha session was not persisted" >&2
    exit 1
}
code=$(AOT_CAPTCHA_UUID="$uuid" perl -ne \
    'if (/"\Q$ENV{AOT_CAPTCHA_UUID}\E";s:[0-9]+:"([A-Za-z0-9]+)"/) { print $1 }' \
    "$session")
test -n "$code" || {
    echo "captcha code is absent from the isolated session" >&2
    exit 1
}
echo "PASS captcha"

login=$(curl --noproxy '*' --silent --show-error --max-time 10 \
    --cookie "$scratch/cookies" \
    --data-urlencode "username=$AOT_TEST_USERNAME" \
    --data-urlencode "password=$AOT_TEST_PASSWORD" \
    --data-urlencode "code=$code" \
    --data-urlencode "uuid=$uuid" \
    "$base_url/core/login")
token=$(printf '%s' "$login" | json_check login) || {
    echo "login business response failed" >&2
    exit 1
}
echo "PASS login"

user=$(curl --noproxy '*' --silent --show-error --max-time 10 \
    --header "Authorization: Bearer $token" \
    "$base_url/core/system/user")
printf '%s' "$user" | json_check user "$AOT_TEST_USERNAME" >/dev/null || {
    echo "authenticated user-info response failed" >&2
    exit 1
}
echo "PASS user-info"

denied=$(curl --noproxy '*' --silent --show-error --max-time 10 \
    --header "Authorization: Bearer $token" \
    "$base_url/core/system/getResourceList")
printf '%s' "$denied" | json_check denied >/dev/null || {
    echo "permission denial was not enforced" >&2
    exit 1
}
echo "PASS permission-denied"
