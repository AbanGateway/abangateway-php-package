#!/bin/bash
# Runs the Laravel bridge tests on Laravel 10, 11 and 12 (Orchestra Testbench
# 8, 9, 10), each on PHP 8.2 and 8.3, in Docker. The package is mounted at
# /pkg and installed into a throwaway app through a path repository.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PKG="$(cd "$HERE/../.." && pwd)"
WORK="${LARAVEL_TEST_WORK:-${TMPDIR:-/tmp}/abangateway-laravel-tests}"
status=0
for pair in "10:^8.0" "11:^9.0" "12:^10.0"; do
  laravel="${pair%%:*}"; testbench="${pair#*:}"
  app="$WORK/laravel-$laravel"
  rm -rf "$app" && mkdir -p "$app/tests"
  # Tests in their own folder: PHPUnit scans recursively, and vendor/ holds a
  # symlink back to /pkg, which contains these same files.
  cp "$HERE"/*.php "$app/tests/"
  cp "$HERE/phpunit.xml" "$HERE/composer.json" "$app/"
  docker run --rm -v "$PKG":/pkg:ro -v "$app":/app -w /app composer:2 sh -c \
    "composer config platform.php 8.2.0 && composer require --dev 'orchestra/testbench:$testbench' --no-interaction --no-progress -q" >/dev/null
  for php in 8.2 8.3; do
    printf '== Laravel %s (testbench %s) on PHP %s: ' "$laravel" "$testbench" "$php"
    out=$(docker run --rm -v "$PKG":/pkg:ro -v "$app":/app -w /app "php:$php-cli" php vendor/bin/phpunit 2>&1) || status=1
    summary=$(echo "$out" | grep -E '^(OK|Tests:|FAILURES|ERRORS)' | tail -1 || true)
    if [ -n "$summary" ]; then echo "$summary"; else echo "no summary"; echo "$out" | tail -15; status=1; fi
    echo "$out" | grep -E '^[0-9]+\) |Deprecat|Warning' | head -5 || true
  done
  docker run --rm -v "$app":/app -w /app php:8.2-cli php -r 'echo "   laravel/framework ", json_decode(file_get_contents("vendor/composer/installed.json"), true)["packages"][array_search("laravel/framework", array_column(json_decode(file_get_contents("vendor/composer/installed.json"), true)["packages"], "name"))]["version"], "\n";'
done
exit $status
