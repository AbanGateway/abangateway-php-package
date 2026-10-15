#!/bin/bash
# Runs the Laravel bridge tests on Laravel 10, 11, 12 and 13 (Orchestra
# Testbench 8, 9, 10, 11) in Docker: 10 to 12 on PHP 8.2 and 8.3, 13 on PHP
# 8.3 and 8.4 (it does not install on 8.2). The package is mounted at /pkg
# and installed into a throwaway app through a path repository.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PKG="$(cd "$HERE/../.." && pwd)"
WORK="${LARAVEL_TEST_WORK:-${TMPDIR:-/tmp}/abangateway-laravel-tests}"
status=0
# laravel : testbench : the lowest PHP its dependencies resolve for : the PHPs it runs on
for row in "10:^8.0:8.2:8.2 8.3" "11:^9.0:8.2:8.2 8.3" "12:^10.0:8.2:8.2 8.3" "13:^11.0:8.3:8.3 8.4"; do
  IFS=: read -r laravel testbench platform phps <<<"$row"
  app="$WORK/laravel-$laravel"
  rm -rf "$app" && mkdir -p "$app/tests"
  # Tests in their own folder: PHPUnit scans recursively, and vendor/ holds a
  # symlink back to /pkg, which contains these same files.
  cp "$HERE"/*.php "$app/tests/"
  cp "$HERE/phpunit.xml" "$HERE/composer.json" "$app/"
  docker run --rm -v "$PKG":/pkg:ro -v "$app":/app -w /app composer:2 sh -c \
    "composer config platform.php $platform.0 && composer require --dev 'orchestra/testbench:$testbench' --no-interaction --no-progress -q" >/dev/null
  for php in $phps; do
    printf '== Laravel %s (testbench %s) on PHP %s: ' "$laravel" "$testbench" "$php"
    out=$(docker run --rm -v "$PKG":/pkg:ro -v "$app":/app -w /app "php:$php-cli" php vendor/bin/phpunit 2>&1) || status=1
    summary=$(echo "$out" | grep -E '^(OK|Tests:|FAILURES|ERRORS)' | tail -1 || true)
    if [ -n "$summary" ]; then echo "$summary"; else echo "no summary"; echo "$out" | tail -15; status=1; fi
    echo "$out" | grep -E '^[0-9]+\) |Deprecat|Warning' | head -5 || true
  done
  docker run --rm -v "$app":/app -w /app "php:$platform-cli" php -r 'echo "   laravel/framework ", json_decode(file_get_contents("vendor/composer/installed.json"), true)["packages"][array_search("laravel/framework", array_column(json_decode(file_get_contents("vendor/composer/installed.json"), true)["packages"], "name"))]["version"], "\n";'
done
exit $status
