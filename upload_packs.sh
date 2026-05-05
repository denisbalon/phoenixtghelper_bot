#!/bin/bash
# One-shot upload of pack media (photo + 2 videos per pack folder) to the FTP host.
# Mirrors $SRC/pack_NNN/* into assets/packs/pack_NNN/* via Explicit FTPS.
# Pack media is intentionally not committed to git (too large).
#
# Usage: ./upload_packs.sh [SRC]
#   SRC defaults to /mnt/c/Users/denny/Downloads/Lera/Test
#
# Reads FTP_USER / FTP_PASS / FTP_HOST from .ftp_credentials if present,
# else from env.

set -u

SRC="${1:-/mnt/c/Users/denny/Downloads/Lera/Test}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -f "${SCRIPT_DIR}/.ftp_credentials" ] && source "${SCRIPT_DIR}/.ftp_credentials"

FTP_HOST="${FTP_HOST:-cp38-ga.privatesystems.net}"
FTP_PORT="${FTP_PORT:-21}"
FTP_USER="${FTP_USER:?Set FTP_USER env var or .ftp_credentials}"
FTP_PASS="${FTP_PASS:?Set FTP_PASS env var or .ftp_credentials}"

CRED="${FTP_USER}:${FTP_PASS}"
BASE="ftp://${FTP_HOST}:${FTP_PORT}"

if [ ! -d "$SRC" ]; then
    echo "SRC not found: $SRC" >&2
    exit 1
fi

echo "Uploading pack media from ${SRC} to ${FTP_HOST}/assets/packs/..."

ok=0; fail=0; total=0
for d in "${SRC}"/pack_*/; do
    pn=$(basename "$d")
    for f in "$d"*; do
        [ -f "$f" ] || continue
        fn=$(basename "$f")
        total=$((total + 1))
        if curl -fsS --ftp-ssl --ftp-create-dirs --max-time 300 \
                -u "$CRED" -T "$f" "${BASE}/assets/packs/${pn}/${fn}"; then
            printf "  %-60s OK\n" "${pn}/${fn}"
            ok=$((ok + 1))
        else
            printf "  %-60s FAIL\n" "${pn}/${fn}"
            fail=$((fail + 1))
        fi
    done
done

echo "Done: ${ok}/${total} OK, ${fail} FAIL"
[ "$fail" -eq 0 ]
