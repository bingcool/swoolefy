#依赖扩展阶段：构建扩展阶段
FROM alpine:3.15 as ext-build
LABEL maintainer=bingcool<bingcoolhuang@gmail.com> version=1.0 license=MIT

# 设置环境变量以避免交互式配置提示
ENV SWOOLE_VERSION=4.8.13 \
    PHP_VERSION=7 \
    SWOOLEFY_CLI_ENV=dev \
    TZ=Asia/Shanghai

RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories \
    && /bin/sh -c set -ex \
    && apk update \
    && apk add --no-cache --virtual .build-deps \
    # build-base包含基础工具和库gcc、g++、make等集合，构建阶段需要依赖编译swoole
    build-base \
    curl make wget tar xz \
    curl-dev \
    c-ares-dev \
    librdkafka-dev \
    openssl-dev \
    php${PHP_VERSION}-dev \
    php${PHP_VERSION} \
    php${PHP_VERSION}-openssl \
    php${PHP_VERSION}-sockets \
    php${PHP_VERSION}-pdo \
    php${PHP_VERSION}-pdo_pgsql \
    php${PHP_VERSION}-pgsql \
    php${PHP_VERSION}-mysqlnd \
    && wget https://github.com/swoole/swoole-src/archive/refs/tags/v${SWOOLE_VERSION}.tar.gz -O - -q | tar -xz \
    && cd swoole-src-${SWOOLE_VERSION} && phpize${PHP_VERSION} && ./configure --with-php-config=/usr/bin/php-config${PHP_VERSION} \
    --enable-mysqlnd \
    --enable-openssl \
    --enable-sockets \
    --enable-swoole_curl \
    && make && make install \
    && apk del --purge *-dev \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/* /usr/share/man /usr/share/doc /usr/share/php${PHP_VERSION}



#运行时目标阶段：创建目标镜像
FROM alpine:3.15
LABEL maintainer=bingcool<bingcoolhuang@gmail.com> version=1.0 license=MIT

# 设置环境变量
ENV SWOOLE_VERSION=4.8.13 \
    PHP_VERSION=7 \
    SWOOLEFY_ENV=dev \
    TZ=Asia/Shanghai

#安装必要的依赖和PHP及其扩展
RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories  \
    && /bin/sh -c set -ex \
    && apk update \
    && apk add --no-cache \
    # 基础工具和库gcc、g++、make等集合，运行时阶段不需要
    #build-base \
    bash curl git wget tar xz tzdata pcre ca-certificates \
    inotify-tools jq libstdc++ openssl procps tini \
    php${PHP_VERSION}-dev \
    php${PHP_VERSION} \
    php${PHP_VERSION}-opcache \
    php${PHP_VERSION}-openssl \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-zip \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-gd \
    php${PHP_VERSION}-intl \
    php${PHP_VERSION}-pdo \
    php${PHP_VERSION}-pdo_mysql \
    php${PHP_VERSION}-pdo_pgsql \
    php${PHP_VERSION}-mysqli \
    php${PHP_VERSION}-pgsql \
    php${PHP_VERSION}-pdo_sqlite \
    php${PHP_VERSION}-sqlite3 \
    php${PHP_VERSION}-mysqlnd \
    php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-ctype \
    php${PHP_VERSION}-dom \
    php${PHP_VERSION}-fileinfo \
    php${PHP_VERSION}-json \
    php${PHP_VERSION}-simplexml \
    php${PHP_VERSION}-xmlreader \
    php${PHP_VERSION}-xmlwriter \
    php${PHP_VERSION}-tokenizer \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-phar \
    php${PHP_VERSION}-session \
    php${PHP_VERSION}-ftp \
    php${PHP_VERSION}-gettext \
    php${PHP_VERSION}-iconv \
    php${PHP_VERSION}-imap \
    php${PHP_VERSION}-sodium \
    php${PHP_VERSION}-sysvshm \
    php${PHP_VERSION}-sysvmsg \
    php${PHP_VERSION}-sysvsem \
    php${PHP_VERSION}-pear \
    php${PHP_VERSION}-posix \
    php${PHP_VERSION}-sockets \
    php${PHP_VERSION}-pcntl \
    php${PHP_VERSION}-pecl-redis \
    php${PHP_VERSION}-pecl-imagick \
    php${PHP_VERSION}-pecl-amqp \
    php${PHP_VERSION}-pecl-rdkafka \
    php${PHP_VERSION}-pecl-mongodb \
    && echo "opcache.enable_cli = 'Off'" >> /etc/php${PHP_VERSION}/conf.d/00_opcache.ini \
    && echo "extension=swoole" >> /etc/php${PHP_VERSION}/conf.d/99_swoole.ini \
    && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    && ln -sf /usr/bin/php${PHP_VERSION} /usr/bin/php \
    && ln -sf /usr/bin/php-config${PHP_VERSION} /usr/bin/php-config \
    && ln -sf /usr/bin/phpize${PHP_VERSION} /usr/bin/phpize \
    && apk del --purge *-dev \
    && rm -rf /var/cache/apk/* /tmp/* /usr/share/man /usr/share/doc /usr/share/php${PHP_VERSION} \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/ \
    && php -v && php -m \
    && echo -e "\033[42;37m Build Completed :).\033[0m\n"

#copy编译好的swoole扩展
COPY --from=ext-build /usr/lib/php${PHP_VERSION}/modules/swoole.so /usr/lib/php${PHP_VERSION}/modules/swoole.so

# 设置工作目录
WORKDIR /home/wwwroot

#设置 tini 作为入口点
ENTRYPOINT ["/sbin/tini", "--"]

#设置默认命令和参数
CMD ["/bin/sh", "-c", "while true; do sleep 30; done"]