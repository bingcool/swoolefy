CREATE DATABASE IF NOT EXISTS bingcool;

use bingcool;

create table tbl_users
(
    `user_id`     int(10) not null AUTO_INCREMENT comment '用户id',
    `user_name`   varchar(64) not null default '' comment '用户名称',
    `sex`         tinyint(1) not null default 0 comment '用户性别，0-男，1-女',
    `birthday`    datetime   default null comment '出生年月',
    `phone`       varchar(32) not null default '' comment '手机号',
    `extand_json` text        default null comment '扩展数据',
    `gmt_create`  datetime    not null default current_timestamp comment '创建时间',
    `gmt_modify`  datetime    not null default current_timestamp on update current_timestamp comment '更新时间',
    PRIMARY KEY (`user_id`)
)engine=innodb,charset=utf8mb4,comment="用户表";

create table tbl_order
(
    `order_id`    bigint(20) not null comment '订单id',
    `user_id`     int(10) not null comment '下单用户id',
    `receiver_user_name` varchar(32) not null default '' comment '收货人',
    `receiver_user_phone` varchar(32) not null default '' comment '收货人手机号',
    `order_amount`  DECIMAL(18,6) not null default 0 comment '订单金额',
    `order_product_ids`  varchar(1024) not null default '' comment '订单产品id',
    `order_status` tinyint(2) not null default 0 comment '订单状态',
    `address` varchar(256) not null default '' comment '物流地址',
    `remark` varchar(1024) not null default '' comment '评论',
    `json_data`    text       default null comment '扩展数据',
    `gmt_create`  datetime    not null default current_timestamp comment '创建时间',
    `gmt_modify`  datetime    not null default current_timestamp on update current_timestamp comment '更新时间',
    PRIMARY KEY (`order_id`)
)engine=innodb,charset=utf8mb4,comment="订单表";

CREATE TABLE `tbl_banks` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL DEFAULT '' COMMENT '银行名称',
    `address` json DEFAULT NULL COMMENT '数据',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




