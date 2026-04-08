FROM php:8.2-cli
WORKDIR /app

RUN apt-get update
&& apt-get install -y --no-install-recommends libpq-dev
&& docker-php-ext-install pdo_pgsql pgsql
&& rm -rf /var/lib/apt/lists/*

COPY . .
ENV PORT=10000
EXPOSE 10000
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t ."]