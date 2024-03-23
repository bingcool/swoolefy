<?php
namespace Test\Module;

use OpenApi\Attributes as OA;
#[OA\Server(
    url:WEB_SITE_HOST,description: '开发环境'
)]

#[OA\Server(
    url:WEB_SITE_HOST,description: '测试环境'
)]

// 定义认证的请求头token
#[OA\SecurityScheme(
    type: 'apiKey',description: '认证授权token',in: 'header', securityScheme: 'apiKeyAuth',name: 'token'
)]

// 定义认证的其他请求头
#[OA\SecurityScheme(
    type: 'apiKey',description: '应用ID',in: 'header', securityScheme: 'appId',name: 'app_id'
)]

#[OA\Info(
    version:'v1.0.0',
    title:'用户订单中心',
    description:'用户订单模块',
)]


class Swag
{
    const MODULE_TAG_COMMON = '公共模块';
    const MODULE_TAG_ORDER = '订单模块';
    const MODULE_TAG_LOG   = '日志模块';
}