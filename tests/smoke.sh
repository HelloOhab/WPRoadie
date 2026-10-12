#!/usr/bin/env bash
# End-to-end smoke test over MCP's STDIO transport.
#
# Runs against any WordPress site with MCP Adapter and WPRoadie active:
#   WP="wp --path=/path/to/site" WP_USER=admin bash tests/smoke.sh
#
# WP_USER must be an administrator who has WPRoadie access
# (`wp wproadie grant <user>`), and the site must be in staging mode or
# have the Site info tools enabled.

set -uo pipefail

WP="${WP:-wp}"
WP_USER="${WP_USER:-admin}"
TOOLS=(site-overview active-theme list-plugins versions health-check)

fails=0
pass() { printf '  ok   %s\n' "$1"; }
fail() { printf '  FAIL %s\n' "$1"; fails=$((fails + 1)); }

mcp() {
	# Sends an initialize request plus the given requests, prints responses.
	{
		echo '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"wproadie-smoke","version":"1"}}}'
		printf '%s\n' "$@"
	} | $WP mcp-adapter serve --server=wproadie --user="$WP_USER" 2>/dev/null
}

call() {
	printf '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wproadie-%s","arguments":%s}}' "$1" "${2:-{\}}"
}

echo "WPRoadie smoke test (user: $WP_USER)"

if $WP wproadie status >/dev/null 2>&1; then pass "wp wproadie status"; else fail "wp wproadie status"; fi

list=$(mcp '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}')
for tool in "${TOOLS[@]}"; do
	if grep -q "\"name\":\"wproadie-$tool\"" <<<"$list"; then pass "tools/list has $tool"; else fail "tools/list has $tool"; fi
done

for tool in "${TOOLS[@]}"; do
	out=$(mcp "$(call "$tool")" | tail -n 1)
	if grep -q '"isError":false' <<<"$out" || { grep -q '"result"' <<<"$out" && ! grep -q '"isError":true' <<<"$out"; }; then
		pass "call $tool"
	else
		fail "call $tool: ${out:0:200}"
	fi
done

out=$(mcp "$(call list-plugins '{"status":"nope"}')" | tail -n 1)
if grep -q '"isError":true' <<<"$out"; then pass "invalid input is rejected"; else fail "invalid input is rejected: ${out:0:200}"; fi

if $WP wproadie log --limit=1 --format=csv 2>/dev/null | grep -q 'wproadie/'; then pass "calls are in the audit log"; else fail "calls are in the audit log"; fi

if [ "$fails" -gt 0 ]; then
	echo "$fails check(s) failed"
	exit 1
fi
echo "All checks passed"
