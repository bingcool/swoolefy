<?php

declare(strict_types=1);

namespace Test\Module\Cron\Service;

use Test\Module\Cron\Dto\CronTaskManager\CronTaskPayloadBuildResultDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskPayloadDto;

/**
 * 将原始 payload 数组规范为 {@see CronTaskPayloadDto}。
 *
 * ## 设计
 * - **不依赖** HTTP Request；入参为 snake_case 数组（与 Request::toPayloadArray() 对齐）
 * - 创建态（$isCreate=true）：强制 name/expression/command、合法 exec_type、node_id
 * - 更新态：仅 put 有值/合法字段，空更新由上层根据 {@see CronTaskPayloadDto::isEmpty} 拒绝
 * - 成功/失败统一包在 {@see CronTaskPayloadBuildResultDto}，不抛异常
 */
class CronTaskPayloadBuilder
{
    /**
     * 构建可持久化的任务字段集合。
     *
     * @param array<string, mixed> $payload snake_case：name、expression、command、exec_type、node_id、
     *        description、status、with_block_lapping、http_*、cron_between、cron_skip 等
     * @param bool $isCreate true=创建校验；false=部分更新（缺省字段不写入 DTO）
     */
    public function build(array $payload, bool $isCreate): CronTaskPayloadBuildResultDto
    {
        $name = trim((string)($payload['name'] ?? ''));
        $expression = trim((string)($payload['expression'] ?? ''));
        $command = trim((string)($payload['command'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $nodeId = isset($payload['node_id']) ? (int)$payload['node_id'] : null;
        $execType = isset($payload['exec_type']) ? (int)$payload['exec_type'] : null;
        $status = isset($payload['status']) ? (int)$payload['status'] : null;
        $withBlockLapping = isset($payload['with_block_lapping']) ? (int)$payload['with_block_lapping'] : null;
        $httpMethod = strtoupper(trim((string)($payload['http_method'] ?? 'GET')));
        $httpTimeout = isset($payload['http_request_time_out']) ? (int)$payload['http_request_time_out'] : null;
        $cronBetween = $this->normalizeTimeRanges($payload['cron_between'] ?? null);
        $cronSkip = $this->normalizeTimeRanges($payload['cron_skip'] ?? null);
        $httpBody = $this->normalizeJsonField($payload['http_body'] ?? null);
        $httpHeaders = $this->normalizeJsonField($payload['http_headers'] ?? null);

        if ($isCreate) {
            if ($name === '' || $expression === '' || $command === '') {
                return CronTaskPayloadBuildResultDto::fail('name/expression/command为必填');
            }
            if (!in_array($execType, [CronTaskPayloadDto::EXEC_TYPE_SHELL, CronTaskPayloadDto::EXEC_TYPE_HTTP], true)) {
                return CronTaskPayloadBuildResultDto::fail('exec_type仅支持1(shell)和2(http)');
            }
            if ($nodeId <= 0) {
                return CronTaskPayloadBuildResultDto::fail('node_id为必填');
            }
        }

        $dto = new CronTaskPayloadDto();

        if ($name !== '') {
            $dto->putName($name);
        }
        if ($expression !== '') {
            $dto->putExpression($expression);
        }
        if ($command !== '') {
            $dto->putCommand($command);
        }
        if ($description !== '' || $isCreate) {
            $dto->putDescription($description);
        }
        if ($nodeId !== null && $nodeId > 0) {
            $dto->putNodeId($nodeId);
        }
        if ($execType !== null && in_array($execType, [CronTaskPayloadDto::EXEC_TYPE_SHELL, CronTaskPayloadDto::EXEC_TYPE_HTTP], true)) {
            $dto->putExecType($execType);
        }
        if ($status !== null && in_array($status, [0, 1], true)) {
            $dto->putStatus($status);
        }
        if ($withBlockLapping !== null && in_array($withBlockLapping, [0, 1], true)) {
            $dto->putWithBlockLapping($withBlockLapping);
        }

        if ($httpMethod !== '' || $isCreate) {
            $dto->putHttpMethod($httpMethod === '' ? 'GET' : $httpMethod);
        }
        if ($httpTimeout !== null && $httpTimeout >= 0) {
            $dto->putHttpRequestTimeOut($httpTimeout);
        } elseif ($isCreate) {
            $dto->putHttpRequestTimeOut(30);
        }

        if ($cronBetween !== null || $isCreate) {
            $dto->putCronBetween($cronBetween);
        }
        if ($cronSkip !== null || $isCreate) {
            $dto->putCronSkip($cronSkip);
        }
        if ($httpBody !== null || $isCreate) {
            $dto->putHttpBody(is_array($httpBody) ? $httpBody : null);
        }
        if ($httpHeaders !== null || $isCreate) {
            $dto->putHttpHeaders(is_array($httpHeaders) ? $httpHeaders : null);
        }

        return CronTaskPayloadBuildResultDto::ok($dto);
    }

    /**
     * 规范化 JSON 类字段：空 → null；字符串尝试 json_decode；数组原样返回。
     *
     * @return array<string, mixed>|mixed|null
     */
    protected function normalizeJsonField(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * 规范化时间段列表为 `[['start'=>..., 'end'=>...], ...]`。
     *
     * 支持已是数组，或 JSON 字符串；缺 start/end 的项丢弃；全无效则 null。
     *
     * @return array<int, array{start: string, end: string}>|null
     */
    protected function normalizeTimeRanges(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }
        if (!is_array($value)) {
            return null;
        }

        $ranges = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $start = trim((string)($item['start'] ?? ''));
            $end = trim((string)($item['end'] ?? ''));
            if ($start === '' || $end === '') {
                continue;
            }
            $ranges[] = [
                'start' => $start,
                'end' => $end,
            ];
        }

        return !empty($ranges) ? $ranges : null;
    }
}
