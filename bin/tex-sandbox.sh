#!/bin/bash
# Run a command (XeLaTeX) in a throw-away sandbox: its own mount, user and network
# namespaces and a private root holding only /usr (read-only), the font config, a
# few device files, the job folder (read-write) and a cache HOME (read-write).
# Nothing else of the server is visible, so a LaTeX file cannot \input config files,
# API keys or other apps (TeX Live's openin_any=p does not stop that: PLAN.md §7.2).
#
#   tex-sandbox.sh JOBDIR HOMEDIR [VAR=value ...] -- command args...
#
# The job folder appears as /job (the working directory) and HOMEDIR as /home/tex.
# Environment is cleared; only the VAR=value arguments reach the command.
set -euo pipefail

if [ "${1:-}" != "--inner" ]; then
    JOB=$(readlink -f "$1"); HOMED=$(readlink -f "$2")
    [ -d "$JOB" ] && [ -d "$HOMED" ] || { echo "tex-sandbox: bad folders" >&2; exit 2; }
    shift 2
    exec unshare --user --map-root-user --mount --net --pid --fork --kill-child --propagation private \
        "$0" --inner "$JOB" "$HOMED" "$@"
fi

shift
JOB=$1; HOMED=$2; shift 2
ENVV=()
while [ $# -gt 0 ] && [ "$1" != "--" ]; do ENVV+=("$1"); shift; done
shift   # the --

export PATH=/usr/sbin:/usr/bin:/bin
R=$(mktemp -d)
mount -t tmpfs -o size=64m,mode=755 tmpfs "$R"

ro_bind() {   # source [dest]
    local s=$1 d=$R${2:-$1}
    if [ -d "$s" ]; then mkdir -p "$d"; else mkdir -p "$(dirname "$d")"; : > "$d"; fi
    mount --rbind "$s" "$d"
    mount -o remount,bind,ro "$d" 2>/dev/null || true
}

ro_bind /usr
for l in bin lib lib64 sbin; do ln -s "usr/$l" "$R/$l"; done
ro_bind /etc/fonts
[ -e /etc/ld.so.cache ] && ro_bind /etc/ld.so.cache
[ -d /var/cache/fontconfig ] && ro_bind /var/cache/fontconfig
mkdir -p "$R/dev" "$R/tmp" "$R/proc"
for n in null zero urandom random; do : > "$R/dev/$n"; mount --bind "/dev/$n" "$R/dev/$n"; done
mount -t tmpfs -o size=64m tmpfs "$R/tmp"
mkdir -p "$R/job" "$R/home/tex"
mount --bind "$JOB" "$R/job"
mount --bind "$HOMED" "$R/home/tex"

exec /usr/sbin/chroot "$R" /usr/bin/env -C /job -i "${ENVV[@]}" /usr/bin/timeout --foreground "${TEX_TIMEOUT:-120}" "$@"
