ARG PHP_VERSION="registry.cn-shanghai.aliyuncs.com/wpjscc/reactphp-inotify:8.2-cli-alpine3.18"
FROM ${PHP_VERSION}

COPY  . /var/www

WORKDIR /var/www

RUN composer install --ignore-platform-reqs --no-dev --no-interaction -o -vvv


