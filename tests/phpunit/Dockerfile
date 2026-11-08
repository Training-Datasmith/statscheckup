FROM php@sha256:6ce95208609dc66df163ab936c970b3b34cd901b85c747102c5999f08ade9143

COPY --from=debian@sha256:734a72b3d6142a71993b4bc580ca01d8ca687f77a2c422d96a961d3376b179bc \
    /usr/share/keyrings/debian-archive-keyring.gpg /etc/apt/trusted.gpg.d/debian-archive-keyring.gpg
COPY --from=debian@sha256:734a72b3d6142a71993b4bc580ca01d8ca687f77a2c422d96a961d3376b179bc \
    /usr/share/keyrings/debian-archive-removed-keys.gpg /etc/apt/trusted.gpg.d/debian-archive-removed-keys.gpg

RUN set -eux; \
    printf 'deb http://archive.debian.org/debian stretch main contrib non-free\n' > /etc/apt/sources.list; \
    printf 'deb http://archive.debian.org/debian-security stretch/updates main contrib non-free\n' >> /etc/apt/sources.list; \
    apt-get -o Acquire::Check-Valid-Until=false update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    docker-php-ext-install pdo_mysql mbstring; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*
