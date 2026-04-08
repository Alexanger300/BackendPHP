FROM php:8.2-cli
WORKDIR /app
RUN docker-php-ext-install pdo pdo_pgsql pgsql
COPY . .
ENV PORT=10000
EXPOSE 10000
CMD sh -c "php -S 0.0.0.0:${PORT}-t ."