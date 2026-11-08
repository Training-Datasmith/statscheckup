FROM php@sha256:6ce95208609dc66df163ab936c970b3b34cd901b85c747102c5999f08ade9143

RUN set -eux; \
    printf 'deb [trusted=yes] http://archive.debian.org/debian stretch main contrib non-free\n' > /etc/apt/sources.list; \
    printf 'deb [trusted=yes] http://archive.debian.org/debian-security stretch/updates main contrib non-free\n' >> /etc/apt/sources.list; \
    apt-get -o Acquire::Check-Valid-Until=false update; \
    apt-get install -y --no-install-recommends debian-archive-keyring; \
    rm -f /etc/apt/sources.list; \
    printf 'deb http://archive.debian.org/debian stretch main contrib non-free\n' > /etc/apt/sources.list; \
    printf 'deb http://archive.debian.org/debian-security stretch/updates main contrib non-free\n' >> /etc/apt/sources.list; \
    apt-get -o Acquire::Check-Valid-Until=false update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    docker-php-ext-install pdo_mysql mbstring; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*
