# The plugin's development tools on its lowest supported PHP version (D15).
FROM php:8.4-cli
RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip \
	&& rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
