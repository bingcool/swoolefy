#依赖扩展阶段：构建扩展阶段
FROM alpine:3.20.3 as ext-build
LABEL maintainer=bingcool<bingcoolhuang@gmail.com> version=1.0 license=MIT

#swoole6.x最高只支持到php81,php82,php83,php84.
#根据实际构建来设置环境变量
ENV MY_SWOOLE_VERSION=6.0.2 \
    MY_PHP_VERSION=83 \
    SWOOLEFY_CLI_ENV=dev


ENV TZ=Asia/Shanghai
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
    postgresql-dev \
    sqlite-dev \
    libpq-dev \
    php${MY_PHP_VERSION}-dev \
    php${MY_PHP_VERSION} \
    php${MY_PHP_VERSION}-openssl \
    php${MY_PHP_VERSION}-sockets \
    php${MY_PHP_VERSION}-pdo \
    php${MY_PHP_VERSION}-pdo_pgsql \
    php${MY_PHP_VERSION}-pgsql \
    php${MY_PHP_VERSION}-pdo_sqlite \
    php${MY_PHP_VERSION}-sqlite3 \
    php${MY_PHP_VERSION}-mysqlnd \
    && wget https://github.com/swoole/swoole-src/archive/refs/tags/v${MY_SWOOLE_VERSION}.tar.gz -O - -q | tar -xz \
    && cd swoole-src-${MY_SWOOLE_VERSION} && /usr/bin/phpize${MY_PHP_VERSION} && ./configure --with-php-config=/usr/bin/php-config${MY_PHP_VERSION} \
    --enable-mysqlnd \
    --enable-openssl \
    --enable-sockets \
    --enable-swoole_curl \
    --enable-cares \
    --enable-swoole-pgsql \
    --enable-swoole-sqlite \
    && make && make install \
    && apk del --purge *-dev \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/* /usr/share/man /usr/share/doc /usr/share/php${MY_PHP_VERSION}



#运行时目标阶段：创建目标镜像
FROM alpine:3.20.3
LABEL maintainer=bingcool<bingcoolhuang@gmail.com> version=1.0 license=MIT

#根据实际构建来设置环境变量
ENV MY_SWOOLE_VERSION=6.0.2 \
    MY_PHP_VERSION=83 \
    SWOOLEFY_CLI_ENV=dev


ENV TZ=Asia/Shanghai
#安装必要的依赖和PHP及其扩展
RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories \
    && /bin/sh -c set -ex \
    && apk update \
    && apk add --no-cache \
    # 基础工具和库gcc、g++、make等集合，运行时阶段不需要
    #build-base \
    bash curl git wget tar xz tzdata pcre ca-certificates \
    inotify-tools jq libstdc++ openssl procps tini \
    php${MY_PHP_VERSION}-dev \
    php${MY_PHP_VERSION} \
    php${MY_PHP_VERSION}-opcache \
    php${MY_PHP_VERSION}-openssl \
    php${MY_PHP_VERSION}-curl \
    php${MY_PHP_VERSION}-zip \
    php${MY_PHP_VERSION}-mbstring \
    php${MY_PHP_VERSION}-gd \
    php${MY_PHP_VERSION}-intl \
    php${MY_PHP_VERSION}-pdo \
    php${MY_PHP_VERSION}-pdo_mysql \
    php${MY_PHP_VERSION}-pdo_pgsql \
    php${MY_PHP_VERSION}-mysqli \
    php${MY_PHP_VERSION}-pgsql \
    php${MY_PHP_VERSION}-pdo_sqlite \
    php${MY_PHP_VERSION}-sqlite3 \
    php${MY_PHP_VERSION}-mysqlnd \
    php${MY_PHP_VERSION}-bcmath \
    php${MY_PHP_VERSION}-ctype \
    php${MY_PHP_VERSION}-dom \
    php${MY_PHP_VERSION}-fileinfo \
    php${MY_PHP_VERSION}-json \
    php${MY_PHP_VERSION}-simplexml \
    php${MY_PHP_VERSION}-xmlreader \
    php${MY_PHP_VERSION}-xmlwriter \
    php${MY_PHP_VERSION}-tokenizer \
    php${MY_PHP_VERSION}-xml \
    php${MY_PHP_VERSION}-phar \
    php${MY_PHP_VERSION}-session \
    php${MY_PHP_VERSION}-ftp \
    php${MY_PHP_VERSION}-gettext \
    php${MY_PHP_VERSION}-iconv \
    php${MY_PHP_VERSION}-imap \
    php${MY_PHP_VERSION}-sodium \
    php${MY_PHP_VERSION}-sysvshm \
    php${MY_PHP_VERSION}-sysvmsg \
    php${MY_PHP_VERSION}-sysvsem \
    php${MY_PHP_VERSION}-pear \
    php${MY_PHP_VERSION}-posix \
    php${MY_PHP_VERSION}-sockets \
    php${MY_PHP_VERSION}-pcntl \
    php${MY_PHP_VERSION}-pecl-redis \
    php${MY_PHP_VERSION}-pecl-imagick \
    php${MY_PHP_VERSION}-pecl-xlswriter \
    php${MY_PHP_VERSION}-pecl-amqp \
    php${MY_PHP_VERSION}-pecl-rdkafka \
    php${MY_PHP_VERSION}-pecl-mongodb \
    && echo "opcache.enable_cli='Off'" >> /etc/php${MY_PHP_VERSION}/conf.d/00_opcache.ini \
    && echo "extension=swoole" >> /etc/php${MY_PHP_VERSION}/conf.d/99_swoole.ini \
    && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    && ln -sf /usr/bin/php${MY_PHP_VERSION} /usr/bin/php \
    && ln -sf /usr/bin/php-config${MY_PHP_VERSION} /usr/bin/php-config \
    && ln -sf /usr/bin/phpize${MY_PHP_VERSION} /usr/bin/phpize \
    && apk del --purge *-dev \
    && rm -rf /var/cache/apk/* /tmp/* /usr/share/man /usr/share/doc /usr/share/php${MY_PHP_VERSION} \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/ \
    && php -v && php -m \
    && echo -e "\033[42;37m Build Completed :).\033[0m\n"

#copy编译好的swoole扩展
COPY --from=ext-build /usr/lib/php${MY_PHP_VERSION}/modules/swoole.so /usr/lib/php${MY_PHP_VERSION}/modules/swoole.so

# 设置工作目录
WORKDIR /home/wwwroot

#设置 tini 作为入口点
ENTRYPOINT ["/sbin/tini", "--"]

#设置默认命令和参数
CMD ["/bin/sh", "-c", "while true; do sleep 30; done"]

#服务作为容器来编排容器，可以shell来监听信号，先退出应用，然后容器再退出
#COPY . /home/wwwroot/swoolefy
#CMD ["/home/wwwroot/swoolefy/docker-start.sh"]