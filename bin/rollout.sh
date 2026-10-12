#!/usr/bin/env bash
# Installs or updates WPRoadie on many sites over SSH with WP-CLI.
#
#   bash bin/rollout.sh sites.txt [--grant=<user>] [--mode=<mode>] [--zip=<url-or-path>]
#
# sites.txt has one site per line:   <ssh-target> <wordpress-path>
#   e.g.  smithdental@35.1.2.3 -p 12345   /www/smithdental_123/public
# Blank lines and lines starting with # are skipped. The SSH target may
# include options such as -p <port>; the last field is the WordPress path.
#
# For each site it installs the pinned MCP Adapter, installs or updates
# WPRoadie from --zip (default: the latest GitHub release asset, which for
# a private repo you should download first and pass as a local path; local
# paths are copied to the server with scp), optionally grants a user and
# sets the mode, then prints `wp wproadie status`.
#
# Roll out in waves (3 staging sites, then 10, 25, the rest) and start
# production sites in read_only or production mode.

set -uo pipefail

ADAPTER_ZIP="https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip"
ZIP="https://github.com/HelloOhab/WPRoadie/releases/latest/download/wproadie.zip"
GRANT=""
MODE=""

SITES_FILE="${1:-}"
if [ -z "$SITES_FILE" ] || [ ! -f "$SITES_FILE" ]; then
	sed -n '2,20p' "$0"
	exit 1
fi
shift
for arg in "$@"; do
	case "$arg" in
		--grant=*) GRANT="${arg#*=}" ;;
		--mode=*) MODE="${arg#*=}" ;;
		--zip=*) ZIP="${arg#*=}" ;;
		*) echo "Unknown option: $arg"; exit 1 ;;
	esac
done

ok=0
failed=0
while IFS= read -r line || [ -n "$line" ]; do
	line="$(echo "$line" | sed 's/#.*//' | xargs)"
	[ -z "$line" ] && continue

	path="${line##* }"
	target="${line% *}"
	echo "==> $target ($path)"

	# shellcheck disable=SC2086
	run() { ssh -o BatchMode=yes $target "cd $(printf '%q' "$path") && $*" < /dev/null; }

	package="$ZIP"
	if [ -f "$ZIP" ]; then
		remote="/tmp/wproadie-$$.zip"
		port="$(echo "$target" | sed -n 's/.*-p \([0-9]*\).*/\1/p')"
		host="$(echo "$target" | awk '{print $1}')"
		if ! scp -q ${port:+-P "$port"} "$ZIP" "$host:$remote"; then
			echo "    FAILED to copy the zip"; failed=$((failed + 1)); continue
		fi
		package="$remote"
	fi

	if run "wp plugin install $(printf '%q' "$ADAPTER_ZIP") --force --activate --quiet" \
		&& run "wp plugin install $(printf '%q' "$package") --force --activate --quiet" \
		&& { [ -z "$GRANT" ] || run "wp wproadie grant $(printf '%q' "$GRANT")"; } \
		&& { [ -z "$MODE" ] || run "wp wproadie mode $(printf '%q' "$MODE")"; } \
		&& run "wp wproadie status"; then
		ok=$((ok + 1))
	else
		echo "    FAILED"
		failed=$((failed + 1))
	fi
	[ -f "$ZIP" ] && run "rm -f $remote"
done < "$SITES_FILE"

echo "Done: $ok succeeded, $failed failed."
[ "$failed" -eq 0 ]
