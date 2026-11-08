#!/bin/sh
set -eu

SCRIPT_ROOT="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d)"
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT INT TERM

cp -a "$SCRIPT_ROOT/." "$WORK/"
MODULE="$WORK/statscheckup.php"
PHAR="${STATSCHECKUP_PHPUNIT_PHAR:?STATSCHECKUP_PHPUNIT_PHAR not set}"
PHP="${PHP:-php}"

sed -i '/unset($scoreTotals\['"'"'products'"'"'\]);/d' "$MODULE"
sed -i 's/\$totals\['"'"'average'"'"'\] = array_sum(\$scoreTotals) \/ \$divisor;/$totals['"'"'average'"'"'] = array_sum($totals) \/ $divisor;/' "$MODULE"

XML="$(mktemp -d)/phpunit.xml"
cat >"$XML" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<phpunit colors="false" bootstrap="$WORK/tests/phpunit/bootstrap.php">
  <testsuites>
    <testsuite name="proof">
      <file>$WORK/tests/phpunit/StatscheckupScoringTest.php</file>
    </testsuite>
  </testsuites>
</phpunit>
EOF

set +e
OUTPUT="$("$PHP" -d error_reporting=-1 -d display_errors=1 -d date.timezone=UTC \
  "$PHAR" --filter testFooterGlobalExcludesProductCount --configuration "$XML" 2>&1)"
STATUS=$?
set -e

echo "$OUTPUT"
if [ "$STATUS" -eq 0 ]; then
    echo "proof failed: test passed with bug restored" >&2
    exit 1
fi
if echo "$OUTPUT" | grep -Eq '(^|[^A-Za-z])(Errors|Parse error|Fatal error|Setup failed|Class not found)'; then
    echo "proof failed: failure was not a clean assertion failure" >&2
    exit 1
fi
echo "$OUTPUT" | grep -q "FAILURES!" || {
    echo "proof failed: expected test failure" >&2
    exit 1
}
