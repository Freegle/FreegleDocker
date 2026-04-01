#!/usr/bin/env bash
# run-parity-check.sh
#
# Runs V1 behavior extraction + V2 coverage checking across all V1 API
# endpoints and produces a markdown gap report in docs/parity/.
#
# Usage: ./scripts/parsers/run-parity-check.sh
#
# Requirements:
#   - freegle-apiv1 container running
#   - Python 3 on host (at /usr/bin/python3)
#   - scripts/parsers/php-go-mapping.json present
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MAPPING="$SCRIPT_DIR/php-go-mapping.json"
V1_API_DIR="/var/www/iznik/http/api"
PHP_EXTRACTOR_HOST="$SCRIPT_DIR/v1-behavior-extractor.php"
PHP_EXTRACTOR_CONTAINER="/var/www/iznik/scripts/parsers/v1-behavior-extractor.php"
GO_ROOT="$REPO_ROOT/iznik-server-go"
REPORT_DIR="$REPO_ROOT/docs/parity"
REPORT="$REPORT_DIR/$(date +%Y-%m-%d)-parity-report.md"
TMP_DIR="$(mktemp -d)"
CONTAINER="freegle-apiv1"

trap 'rm -rf "$TMP_DIR"' EXIT

mkdir -p "$REPORT_DIR"

echo "Copying PHP extractor into container..."
docker exec "$CONTAINER" mkdir -p "$(dirname "$PHP_EXTRACTOR_CONTAINER")"
docker cp "$PHP_EXTRACTOR_HOST" "$CONTAINER:$PHP_EXTRACTOR_CONTAINER"

# Read mapping as tab-separated pairs
declare -A PHP_TO_GO
while IFS=$'\t' read -r php go; do
    PHP_TO_GO["$php"]="$go"
done < <(python3 -c "
import json, sys
m = json.load(open('$MAPPING'))
for php, go in sorted(m.items()):
    print(php + '\t' + go)
")

# Associative arrays for report data
declare -A SUMMARY_ROWS
declare -A DETAILS

echo "Processing ${#PHP_TO_GO[@]} endpoints..."

for php_file in $(echo "${!PHP_TO_GO[@]}" | tr ' ' '\n' | sort); do
    go_pkg="${PHP_TO_GO[$php_file]}"

    if [[ "$go_pkg" == SKIP:* ]]; then
        echo "  SKIP $php_file ($go_pkg)"
        continue
    fi

    go_dir="$GO_ROOT/$go_pkg"
    if [[ ! -d "$go_dir" ]]; then
        echo "  WARN: Go dir not found: $go_dir"
        continue
    fi

    echo -n "  $php_file ... "

    ledger="$TMP_DIR/${php_file%.php}.json"
    annotated="$TMP_DIR/${php_file%.php}-annotated.json"

    # Step 1: extract V1 behaviors
    if ! docker exec "$CONTAINER" php "$PHP_EXTRACTOR_CONTAINER" \
            "$V1_API_DIR/$php_file" \
            2>/dev/null > "$ledger"; then
        echo "EXTRACT ERROR"
        continue
    fi

    # Step 2: check V2 coverage
    if ! python3 "$SCRIPT_DIR/v2-coverage-checker.py" \
            "$ledger" "$go_dir" \
            2>/dev/null > "$annotated"; then
        echo "CHECKER ERROR"
        continue
    fi

    # Step 3: count results
    counts=$(python3 - "$annotated" <<'PYEOF'
import json, sys
data = json.load(open(sys.argv[1]))
total = len(data)
not_found = sum(1 for b in data if b['v2_status'] == 'NOT_FOUND')
uncertain = sum(1 for b in data if b['v2_status'] == 'UNCERTAIN')
print(f"{total}\t{not_found}\t{uncertain}")
PYEOF
)
    total=$(echo "$counts" | cut -f1)
    not_found=$(echo "$counts" | cut -f2)
    uncertain=$(echo "$counts" | cut -f3)

    SUMMARY_ROWS["$php_file"]="| \`$php_file\` | $total | $not_found | $uncertain |"

    # Step 4: collect gap details
    details=$(python3 - "$annotated" <<'PYEOF'
import json, sys
data = json.load(open(sys.argv[1]))
gaps = [b for b in data if b['v2_status'] in ('NOT_FOUND', 'UNCERTAIN')]
if not gaps:
    print('(none)')
else:
    for b in gaps:
        status = b['v2_status']
        cat = b['category']
        desc = b['description']
        loc = f"{b['file']}:{b['line']}"
        print(f"- [{status}] **{cat}**: {desc} — `{loc}`")
PYEOF
)
    DETAILS["$php_file"]="$details"

    echo "total=$total not_found=$not_found uncertain=$uncertain"
done

# Write report header
cat > "$REPORT" <<HEADER
# V1→V2 Migration Parity Report

Generated: $(date -u '+%Y-%m-%d %H:%M UTC')

Only NOT_FOUND and UNCERTAIN behaviors are shown per endpoint.
NOT_FOUND means the extractor found no evidence of the V1 behavior in V2 Go source.
UNCERTAIN means the table name could not be extracted from the V1 SQL string.

---

## Summary

| Endpoint | Behaviors | NOT_FOUND | UNCERTAIN |
|----------|-----------|-----------|-----------|
HEADER

# Write summary table rows (sorted)
for php_file in $(echo "${!SUMMARY_ROWS[@]}" | tr ' ' '\n' | sort); do
    echo "${SUMMARY_ROWS[$php_file]}" >> "$REPORT"
done

# Write per-endpoint details
{
    echo ""
    echo "---"
    echo ""
    echo "## Per-Endpoint Gaps"
} >> "$REPORT"

for php_file in $(echo "${!DETAILS[@]}" | tr ' ' '\n' | sort); do
    {
        echo ""
        echo "### \`$php_file\`"
        echo ""
        echo "${DETAILS[$php_file]}"
    } >> "$REPORT"
done

echo ""
echo "Report written to: $REPORT"
