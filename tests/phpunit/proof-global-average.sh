#!/bin/sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
MODULE="$ROOT/statscheckup.php"
BACKUP="$(mktemp)"
trap 'cp "$BACKUP" "$MODULE"; rm -f "$BACKUP"' EXIT INT TERM

cp "$MODULE" "$BACKUP"
sed -i '/unset($scoreTotals\['"'"'products'"'"'\]);/d' "$MODULE" || true
sed -i 's/\$totals\['"'"'average'"'"'\] = array_sum(\$scoreTotals) \/ \$divisor;/$totals['"'"'average'"'"'] = array_sum($totals) \/ $divisor;/' "$MODULE"

PHP="${PHP:-php}"
PHAR="${STATSCHECKUP_PHPUNIT_PHAR:?STATSCHECKUP_PHPUNIT_PHAR not set}"
XML="$(mktemp -d)/phpunit.xml"
cat >"$XML" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<phpunit colors="false" bootstrap="$ROOT/tests/phpunit/bootstrap.php">
  <testsuites>
    <testsuite name="proof">
      <file>$ROOT/tests/phpunit/StatscheckupScoringTest.php</file>
    </testsuite>
  </testsuites>
</phpunit>
EOF

set +e
OUTPUT="$("$PHP" -d error_reporting=-1 -d display_errors=1 \
  "$PHAR" --filter testFooterGlobalExcludesProductCount --configuration "$XML" 2>&1)"
STATUS=$?
set -e

echo "$OUTPUT"
if [ "$STATUS" -eq 0 ]; then
    echo "proof failed: test passed with bug restored" >&2
    exit 1
fi
echo "$OUTPUT" | grep -q "FAILURES!" || {
    echo "proof failed: expected test failure" >&2
    exit 1
}
