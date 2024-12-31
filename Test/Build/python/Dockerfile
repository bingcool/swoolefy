#基于Dockerfile生成的php镜像作为基础镜像
FROM mytest:v1.5
LABEL maintainer=bingcool<bingcoolhuang@gmail.com> version=1.0 license=MIT

RUN /bin/sh -c set -ex \
    && apk update \
    && apk add --no-cache \
    python3 py3-pip \
    && pip3 config set global.index-url https://pypi.tuna.tsinghua.edu.cn/simple
