<?php

declare(strict_types=1);

namespace Test\Module\Cron\Service;

use Test\Module\Cron\Dto\CronTaskManager\CronTaskPayloadBuildResultDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskPayloadDto;
use Test\Module\Cron\Request\CronTaskManager\CronTaskCreateRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskUpdateRequest;

class CronTaskPayloadBuilder
{
    public function buildFromCreate(CronTaskCreateRequest $request): CronTaskPayloadBuildResultDto
    {
        return $this->build($request->toPayloadArray(), true);
    }

    public function buildFromUpdate(CronTaskUpdateRequest $request): CronTaskPayloadBuildResultDto
    {
        return $this->build($request->toPayloadArray(), false);
    }

    /**
     * @param array<string, mixed> $payload
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
     * @return array<string, mixed>|null
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
