#!/bin/sh
set -eu

USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
if [ "$USE_SUDO_DOCKER" = "1" ]; then
    DOCKER="sudo docker"
else
    DOCKER="${DOCKER:-docker}"
fi

ROOT="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
NETWORK="statscheckup-net"
DB_NAME="statscheckup-db"
PHP_IMAGE="statscheckup-php:5.6.40"
MARIADB_IMAGE="mariadb@sha256:f1c56f035ed268a0d7c2c2e06dc879f6319b997cb434cf822a4cfd72a7aa184d"
NETWORK_MODE="${STATSCHECKUP_NETWORK_MODE:-host}"

if [ "$USE_SUDO_DOCKER" = "1" ]; then
    if ! sudo docker version >/dev/null 2>&1; then
        echo "sudo docker unavailable" >&2
        exit 1
    fi
elif ! command -v docker >/dev/null 2>&1; then
    echo "docker not found; install docker.io or set DOCKER" >&2
    exit 1
fi

if [ "$NETWORK_MODE" = "bridge" ]; then
    $DOCKER network inspect "$NETWORK" >/dev/null 2>&1 || $DOCKER network create "$NETWORK"
    DB_NETWORK="--network $NETWORK --network-alias db"
    PHP_NETWORK="--network $NETWORK"
    DSN='mysql:host=db;dbname=statscheckup;charset=utf8mb4'
else
    DB_NETWORK="--network host"
    PHP_NETWORK="--network host"
    DSN='mysql:host=127.0.0.1;dbname=statscheckup;charset=utf8mb4'
fi

if ! $DOCKER container inspect "$DB_NAME" >/dev/null 2>&1; then
    if [ "$NETWORK_MODE" = "host" ]; then
        MARIADB_EXTRA="--bind-address=127.0.0.1"
    else
        MARIADB_EXTRA=""
    fi
    $DOCKER run -d --name "$DB_NAME" $DB_NETWORK \
        -e MARIADB_DATABASE=statscheckup \
        -e MARIADB_USER=statscheckup \
        -e MARIADB_PASSWORD=statscheckup \
        -e MARIADB_ROOT_PASSWORD=statscheckup \
        "$MARIADB_IMAGE" \
        --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci $MARIADB_EXTRA
fi

if ! $DOCKER image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
    $DOCKER build -t "$PHP_IMAGE" -f "$ROOT/tests/phpunit/Dockerfile" "$ROOT/tests/phpunit"
fi

PHPUNIT_DIR="$(mktemp -d)"
chmod 700 "$PHPUNIT_DIR"
trap 'rm -rf "$PHPUNIT_DIR"' EXIT INT TERM

curl -fsSL -o "$PHPUNIT_DIR/phpunit-4.8.36.phar" "https://phar.phpunit.de/phpunit-4.8.36.phar"
curl -fsSL -o "$PHPUNIT_DIR/phpunit-4.8.36.phar.asc" "https://phar.phpunit.de/phpunit-4.8.36.phar.asc"
GNUPGHOME="$(mktemp -d)"
chmod 700 "$GNUPGHOME"
export GNUPGHOME
gpg --batch --keyserver hkps://keyserver.ubuntu.com --recv-keys 4AA394086372C20A
gpg --batch --status-fd 1 --verify "$PHPUNIT_DIR/phpunit-4.8.36.phar.asc" "$PHPUNIT_DIR/phpunit-4.8.36.phar" | grep -q 'VALIDSIG.*D8406D0D82947747293778314AA394086372C20A'
echo 'ab8bc3017a64cf75a2112f5e089a1d5b0c4cc2116556d60c1af5e3f584e85c5e  phpunit-4.8.36.phar' | (cd "$PHPUNIT_DIR" && sha256sum -c)

set +e
$DOCKER run --rm $PHP_NETWORK \
    -v "$ROOT":/workspace:ro \
    -v "$PHPUNIT_DIR":/opt/phpunit:ro \
    -w /workspace \
    -e STATSCHECKUP_DSN="$DSN" \
    -e STATSCHECKUP_USER=statscheckup \
    -e STATSCHECKUP_PASSWORD=statscheckup \
    -e STATSCHECKUP_PHPUNIT_PHAR=/opt/phpunit/phpunit-4.8.36.phar \
    "$PHP_IMAGE" \
    php -d error_reporting=-1 -d display_errors=1 -d display_startup_errors=1 -d html_errors=0 -d date.timezone=UTC \
    /workspace/tests/phpunit/run-suite.php
SUITE_STATUS=$?
set -e
exit "$SUITE_STATUS"
