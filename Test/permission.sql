CREATE TABLE `staff_api_permissions` (
     `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
     `app_id` int unsigned NOT NULL COMMENT '应用Id',
     `page_id` int NOT NULL DEFAULT '0' COMMENT '页面导航ID',
     `relation_per_ids` json DEFAULT NULL COMMENT '关联的子权限ID',
     `name` varchar(128) NOT NULL DEFAULT '' COMMENT '权限名称',
     `uri` varchar(255) NOT NULL DEFAULT '' COMMENT 'api路由',
     `code` varchar(255) NOT NULL DEFAULT '' COMMENT '唯一标志',
     `method` tinyint NOT NULL DEFAULT '0' COMMENT '请求方式：0-ANY, 1-GET, 2-PUT, 3-POST, 4-DELETE',
     `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：0-删除，1-启用，2-废弃',
     `is_validate` tinyint NOT NULL DEFAULT '1' COMMENT '是否校验权限,公共接口可能不需要校验',
     `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
     `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
     `deleted_at` datetime DEFAULT NULL COMMENT '删除时间',
     PRIMARY KEY (`id`),
     UNIQUE KEY `uniq_route_method` (`app_id`,`page_id`,`uri`,`method`),
     UNIQUE KEY `uniq_code` (`code`)
) COMMENT='接口权限表';



CREATE TABLE `staff_button_elements` (
     `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
     `app_id` int unsigned NOT NULL COMMENT '应用Id',
     `name` varchar(128) NOT NULL DEFAULT '' COMMENT '名称',
     `code` varchar(255) NOT NULL DEFAULT '' COMMENT '唯一标志',
     `uri` varchar(255) NOT NULL DEFAULT '' COMMENT 'api路由',
     `page_id` int unsigned NOT NULL COMMENT '关联页面Id',
     `desc` varchar(256) NOT NULL DEFAULT '' COMMENT '描述',
     `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
     `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
     PRIMARY KEY (`id`),
     UNIQUE KEY `uniq_code` (`code`)
) COMMENT='元素-按钮表';


CREATE TABLE `staff_menu_group` (
    `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
    `app_id` int unsigned NOT NULL COMMENT '应用Id',
    `group_name` varchar(32) NOT NULL DEFAULT '' COMMENT '菜单分组名称',
    `group_desc` varchar(128) NOT NULL DEFAULT '' COMMENT '分组描述',
    `group_status` tinyint(2) NOT NULL DEFAULT '1' COMMENT '状态，0：禁用，1：启用',
    `page_ids` json DEFAULT NULL COMMENT 'pages表id集合',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_group_name` (`group_name`)
) COMMENT='菜单页面分组';

CREATE TABLE `staff_pages` (
    `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
    `app_id` int unsigned NOT NULL COMMENT '应用Id',
    `name` varchar(128) NOT NULL DEFAULT '' COMMENT '页面节点名称',
    `parent_prefix` varchar(256) NOT NULL DEFAULT '' COMMENT '父页面所有id',
    `parent_id` int DEFAULT '0' COMMENT '父页面Id',
    `uri` varchar(256) NOT NULL DEFAULT '' COMMENT '页面URI',
    `code` varchar(256) NOT NULL DEFAULT '' COMMENT '唯一标志',
    `icon` varchar(256) NOT NULL DEFAULT '' COMMENT '图标',
    `sort` int unsigned DEFAULT '0' COMMENT '排序：越大越靠前',
    `status` tinyint(2) unsigned DEFAULT '1' COMMENT '状态：0-禁用(菜单栏不展示)，1-启用，2-删除',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `delete_at` datetime DEFAULT NULL COMMENT '删除|禁用时间',
    `old_page_id` int unsigned NOT NULL DEFAULT '0' COMMENT '迁移数据时使用，迁移后忽略该字段',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_uri_appid` (`uri`,`app_id`),
    UNIQUE KEY `uniq_code` (`code`),
    KEY `idx_pid` (`parent_id`)
) COMMENT='菜单页面节点表';

CREATE TABLE `staff_role_button` (
     `app_id` int unsigned NOT NULL COMMENT '应用Id',
     `role_id` int unsigned NOT NULL COMMENT '角色Id',
     `button_id` int unsigned NOT NULL COMMENT '页面Id',
     `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
     `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
     UNIQUE KEY `uniq_key` (`app_id`,`role_id`,`button_id`)
) COMMENT='角色-按钮权限表';

CREATE TABLE `staff_role_page` (
   `app_id` int unsigned NOT NULL COMMENT '应用Id',
   `role_id` int unsigned NOT NULL COMMENT '角色Id',
   `page_id` int unsigned NOT NULL COMMENT '页面Id',
   `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
   `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
   UNIQUE KEY `uniq-key` (`app_id`,`role_id`,`page_id`)
) COMMENT='角色-页面权限表';

CREATE TABLE `staff_role_permission` (
    `app_id` int unsigned NOT NULL COMMENT '应用Id',
    `type` tinyint(2) unsigned NOT NULL COMMENT '类型1-api接口，2-关联任务接口',
    `role_id` int unsigned NOT NULL COMMENT '角色Id',
    `per_id` int unsigned NOT NULL COMMENT '权限Id',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    KEY `idx_uk-key` (`app_id`,`type`,`role_id`,`per_id`)
)COMMENT='角色-api权限表';


CREATE TABLE `staff_roles` (
   `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '自增Id',
   `app_id` int NOT NULL DEFAULT '0' COMMENT '应用id',
   `is_super_role` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否是超级管理员角色组',
   `name` varchar(64) NOT NULL DEFAULT '' COMMENT '角色名称',
   `code` varchar(128) NOT NULL DEFAULT '' COMMENT '唯一标识',
   `desc` varchar(256) NOT NULL DEFAULT '' COMMENT '角色描述',
   `status` tinyint(2) DEFAULT '1' COMMENT '状态：0-禁用，1-启用',
   `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
   `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
   PRIMARY KEY (`id`),
   UNIQUE KEY `uniq_code` (`code`)
) COMMENT='角色表';



CREATE TABLE `staff_user_role` (
   `app_id` int unsigned NOT NULL COMMENT '应用Id',
   `user_id` int unsigned NOT NULL COMMENT '用户Id',
   `role_id` int unsigned NOT NULL COMMENT '角色Id',
   `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
   `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
   UNIQUE KEY `uniq-userid-roleid-appid` (`user_id`,`role_id`,`app_id`)
) COMMENT='用户与角色关联表';