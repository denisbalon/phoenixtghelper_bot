#!/bin/bash
# Deploy bot files via Explicit FTPS (curl -T per-file).
#
# Connects to cp38-ga.privatesystems.net (the shared-hosting hostname
# the TLS cert is actually issued to) so certificate verification succeeds
# without --insecure. Same IP/server as ftp.phoenixsoftware.monster.
#
# Reads FTP_USER / FTP_PASS / FTP_HOST from .ftp_credentials if present,
# else from env.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -f "${SCRIPT_DIR}/.ftp_credentials" ] && source "${SCRIPT_DIR}/.ftp_credentials"

FTP_HOST="${FTP_HOST:-cp38-ga.privatesystems.net}"
FTP_PORT="${FTP_PORT:-21}"
FTP_USER="${FTP_USER:?Set FTP_USER env var or .ftp_credentials}"
FTP_PASS="${FTP_PASS:?Set FTP_PASS env var or .ftp_credentials}"

CRED="${FTP_USER}:${FTP_PASS}"
BASE="ftp://${FTP_HOST}:${FTP_PORT}"

echo "Deploying to ${FTP_HOST}..."

# Tracked files minus deploy-time excludes.
mapfile -t FILES < <(git ls-files \
    ':!:.gitignore' \
    ':!:.claude/**' \
    ':!:config.example.php' \
    ':!:deploy.sh' \
    ':!:config.php' \
    ':!:logs/**' \
    ':!:*.md' \
    ':!:set_webhook.php' \
    ':!:upload_packs.sh' \
    ':!:instructions.html' \
    ':!:pending_instructions.flag')

ok=0; fail=0
for f in "${FILES[@]}"; do
    if curl -fsS --ftp-ssl --ftp-create-dirs --max-time 60 \
            -u "$CRED" -T "$f" "$BASE/$f"; then
        printf "  %-40s OK\n" "$f"
        ok=$((ok + 1))
    else
        printf "  %-40s FAIL\n" "$f"
        fail=$((fail + 1))
    fi
done

echo "Done: $ok OK, $fail FAIL"
[ "$fail" -eq 0 ]
