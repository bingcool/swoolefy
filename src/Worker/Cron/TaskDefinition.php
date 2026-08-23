<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Worker\Cron;

use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Exception\CronException;
use Swoolefy\Worker\Dto\CronForkTaskMetaDtoWorker;
use Swoolefy\Worker\Dto\CronUrlTaskMetaDtoWorker;

/**
 * 任务配置在 Runtime 中的不可变快照（value object）。
 *
 * 同时兼容 DB cron_task 行与现有 Worker Meta（cron_name / exec_script / url）。
 * fromArray() 完成后字段不再回写；Config Update 会 new 一份替换 RuntimeJob::$definition，
 * 但已启动的 Execution 必须继续使用 ExecutionSnapshot 里冻结的本对象，
 * 不能中途回读最新 Runtime 配置（P0 Snapshot 边界）。
 *
 * 身份（jobId）由 resolveJobId() 在构造前算好：
 * 优先 cron_task_id / 数值 id → "id:{n}"，否则 cron_name / name → "name:{name}"。
 * 无稳定 id 时改名会表现为旧键 DELETE + 新键 ADD。
 *
 * fingerprint() 只覆盖调度/执行元数据，不含 status。
 * status 变化走 ConfigDiff 的 ENABLE / DISABLE，避免“只改停启用却重建 Timer 定义”。
 * retry 计入 fingerprint：改重试次数会 UPDATE（换定义，进行中的 Snapshot 仍冻结旧值）。
 * cronName、cronTaskId、nodeId、output、extend、updatedAt、cronDbLogClass、cronMetaOrigin、raw
 * 不参与 fingerprint，单独变化不会产生 UPDATE。
 *
 * @see ConfigDiff
 * @see ExecutionSnapshot
 * @see CronManager::applyRows()
 */
final class TaskDefinition
{
    /** 停用：RuntimeJob 可保留，但不得持有 Schedule Timer。 */
    public const STATUS_DISABLED = 0;

    /** 启用：Active 且未 deleted 时必须恰好一个 Schedule Timer。 */
    public const STATUS_ENABLED = 1;

    /** exec_type=1：Shell / fork / swoolefy script。 */
    public const EXEC_SHELL = CronProcess::EXEC_FORK_TYPE;

    /** exec_type=2：HTTP URL。 */
    public const EXEC_HTTP = CronProcess::EXEC_URL_TYPE;

    /**
     * @param array<string, mixed> $raw 原始配置，供日志与兼容回调使用
     * @param list<array<int, mixed>> $cronBetween
     * @param list<array<int, mixed>> $cronSkip
     * @param array<string, mixed> $httpHeaders
     * @param array<string, mixed> $httpBody
     * @param array<string, mixed> $argv
     * @param array<string, mixed> $extend
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $cronName,
        public readonly string $expression,
        public readonly int $execType,
        public readonly int $status,
        public readonly bool $withBlockLapping,
        public readonly string $command,
        public readonly int $cronTaskId = 0,
        public readonly ?int $nodeId = null,
        public readonly array $cronBetween = [],
        public readonly array $cronSkip = [],
        public readonly string $httpMethod = 'GET',
        public readonly array $httpBody = [],
        public readonly array $httpHeaders = [],
        public readonly int $httpRequestTimeOut = 120,
        public readonly string $execBinFile = '',
        public readonly string $execScript = '',
        public readonly string $url = '',
        public readonly string $runType = '',
        public readonly string $forkType = CronForkProcess::FORK_TYPE_PROC_OPEN,
        public readonly string $output = '/dev/null',
        public readonly array $argv = [],
        public readonly array $extend = [],
        public readonly string $updatedAt = '',
        public readonly string $cronDbLogClass = '',
        public readonly string $cronMetaOrigin = '',
        public readonly ?string $timezone = null,
        public readonly int $retry = 0,
        public readonly array $raw = [],
    ) {
    }

    /**
     * 从 DB 行或 Worker Meta 数组规范化为 TaskDefinition。
     *
     * 字段别名：
     * - 身份：cron_task_id / id / cron_name / name
     * - 表达式：cron_expression / expression
     * - HTTP：url / command（像 URL 时）、http_method / method、http_body / params、
     *   http_headers / headers、http_request_time_out / request_time_out
     * - Shell：command / exec_script / exec_bin_file
     * - 重试：retry（缺省 / 非法 / 负数 → 0；0 = 不重试）
     *
     * command 与 exec_script / url 会互相回填，避免历史配置只填其中一项。
     * 缺 status 时默认 STATUS_ENABLED（静态 conf 无停用字段）。
     * http_request_time_out <= 0 回退 120 秒。
     *
     * @param array<string, mixed> $item
     * @throws CronException 缺少稳定身份或表达式时
     */
    public static function fromArray(array $item): self
    {
        $jobId = self::resolveJobId($item);
        $cronName = (string) ($item['cron_name'] ?? $item['name'] ?? $jobId);
        $expression = $item['cron_expression'] ?? $item['expression'] ?? '';
        if ($expression === '' || $expression === null) {
            throw new CronException(sprintf('任务 %s 缺少 expression', $cronName));
        }

        $command = (string) ($item['command'] ?? '');
        $execScript = (string) ($item['exec_script'] ?? '');
        $url = (string) ($item['url'] ?? '');
        if ($url === '' && $command !== '' && self::looksLikeUrl($command)) {
            $url = $command;
        }
        if ($url === '' && $execScript !== '' && self::looksLikeUrl($execScript)) {
            $url = $execScript;
        }
        if ($command === '' && $execScript !== '') {
            $command = $execScript;
        }
        if ($execScript === '' && $command !== '' && !self::looksLikeUrl($command)) {
            $execScript = $command;
        }

        $execType = (int) ($item['exec_type'] ?? ($url !== '' ? self::EXEC_HTTP : self::EXEC_SHELL));
        $status = array_key_exists('status', $item) ? (int) $item['status'] : self::STATUS_ENABLED;
        $block = $item['with_block_lapping'] ?? false;

        $httpTimeout = (int) ($item['http_request_time_out'] ?? $item['request_time_out'] ?? 120);
        if ($httpTimeout <= 0) {
            $httpTimeout = 120;
        }

        $nodeId = $item['node_id'] ?? null;
        // retry=0 默认不重试；retry=N 表示首次失败后再重试 N 次（最多 1+N 次）
        $retry = (int) ($item['retry'] ?? 0);
        if ($retry < 0) {
            $retry = 0;
        }

        // !!! More important swoolefy script
        if (str_contains($execScript, 'script') && str_contains($execScript, '--c')) {
            $item['run_type'] = CronForkTaskMetaDtoWorker::RUN_TYPE;
        }

        return new self(
            jobId: $jobId,
            cronName: $cronName,
            expression: (string) $expression,
            execType: $execType,
            status: $status,
            withBlockLapping: (bool) $block,
            command: $command,
            cronTaskId: (int) ($item['cron_task_id'] ?? $item['id'] ?? 0),
            nodeId: $nodeId === null || $nodeId === '' ? null : (int) $nodeId,
            cronBetween: self::normalizeWindows($item['cron_between'] ?? []),
            cronSkip: self::normalizeWindows($item['cron_skip'] ?? []),
            httpMethod: strtoupper((string) ($item['http_method'] ?? $item['method'] ?? 'GET')),
            httpBody: self::asArray($item['http_body'] ?? $item['params'] ?? []),
            httpHeaders: self::asArray($item['http_headers'] ?? $item['headers'] ?? []),
            httpRequestTimeOut: $httpTimeout,
            execBinFile: (string) ($item['exec_bin_file'] ?? ''),
            execScript: $execScript,
            url: $url,
            runType: (string) ($item['run_type'] ?? ''),
            forkType: (string) ($item['fork_type'] ?? CronForkProcess::FORK_TYPE_PROC_OPEN),
            output: (string) ($item['output'] ?? '/dev/null'),
            argv: self::asArray($item['argv'] ?? []),
            extend: self::asArray($item['extend'] ?? []),
            updatedAt: (string) ($item['updated_at'] ?? ''),
            cronDbLogClass: (string) ($item['cron_db_log_class'] ?? ''),
            cronMetaOrigin: (string) ($item['cron_meta_origin'] ?? ''),
            timezone: isset($item['timezone']) && $item['timezone'] !== '' ? (string) $item['timezone'] : null,
            retry: $retry,
            raw: $item,
        );
    }

    /**
     * 稳定任务身份：优先 DB id，否则 cron_name。
     *
     * ConfigDiff 只认本方法产出的数组键，不在 Diff 内重算。
     * cron_task_id 优先于数值 id，避免两套主键并存时抖动。
     *
     * @param array<string, mixed> $item
     * @throws CronException 三者皆空时
     */
    public static function resolveJobId(array $item): string
    {
        if (!empty($item['cron_task_id'])) {
            return 'id:' . $item['cron_task_id'];
        }
        if (isset($item['id']) && is_numeric($item['id']) && (int) $item['id'] > 0) {
            return 'id:' . (int) $item['id'];
        }
        $name = (string) ($item['cron_name'] ?? $item['name'] ?? '');
        if ($name === '') {
            throw new CronException('任务缺少稳定身份（id / cron_name）');
        }

        return 'name:' . $name;
    }

    /**
     * 是否启用（可被调度）。
     */
    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    /**
     * 调度 / 执行相关字段指纹。status 变化走 ENABLE/DISABLE，不计入 UPDATE。
     *
     * 使用 sha1(json_encode(...))，键顺序固定为本方法字面量数组顺序。
     * 嵌套数组（cron_between / cron_skip / http_* / argv）按规范化后的 PHP 数组比较。
     * retry 计入：改重试次数必须 UPDATE，以便 Runtime 换定义。
     */
    public function fingerprint(): string
    {
        return sha1(json_encode([
            $this->expression,
            $this->execType,
            $this->withBlockLapping,
            $this->command,
            $this->cronBetween,
            $this->cronSkip,
            $this->httpMethod,
            $this->httpBody,
            $this->httpHeaders,
            $this->httpRequestTimeOut,
            $this->execBinFile,
            $this->execScript,
            $this->url,
            $this->runType,
            $this->forkType,
            $this->argv,
            $this->timezone,
            $this->retry,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * 本轮最多执行次数：首次 1 次 + retry 次重试。
     *
     * retry=0 → 1；retry=2 → 3。只作用于 FAILED，SKIPPED 不走本计数。
     */
    public function maxAttempts(): int
    {
        return 1 + max(0, $this->retry);
    }

    /**
     * 还原为现有日志 DTO，保持 CronTaskInterface::logCronTaskRuntime() 兼容。
     *
     * HTTP → CronUrlTaskMetaDtoWorker；Shell → ScheduleEvent。
     * 以 raw 为底再覆盖规范化字段，避免丢失历史扩展键。
     */
    public function toLogDto(): ScheduleEvent|CronUrlTaskMetaDtoWorker
    {
        $payload = $this->raw;
        $payload['cron_task_id'] = $this->cronTaskId;
        $payload['cron_name'] = $this->cronName;
        $payload['cron_expression'] = $this->expression;
        $payload['cron_db_log_class'] = $this->cronDbLogClass;
        $payload['cron_meta_origin'] = $this->cronMetaOrigin;
        $payload['command'] = $this->command;
        $payload['with_block_lapping'] = $this->withBlockLapping;
        $payload['retry'] = $this->retry;
        $payload['cron_between'] = $this->cronBetween;
        $payload['cron_skip'] = $this->cronSkip;
        $payload['updated_at'] = $this->updatedAt;

        if ($this->execType === self::EXEC_HTTP) {
            $payload['url'] = $this->url !== '' ? $this->url : $this->command;
            $payload['method'] = $this->httpMethod;
            $payload['params'] = $this->httpBody;
            $payload['headers'] = $this->httpHeaders;
            $payload['request_time_out'] = $this->httpRequestTimeOut;

            return CronUrlTaskMetaDtoWorker::load($payload);
        }

        $payload['exec_bin_file'] = $this->execBinFile;
        $payload['exec_script'] = $this->execScript !== '' ? $this->execScript : $this->command;
        $payload['run_type'] = $this->runType;
        $payload['fork_type'] = $this->forkType;
        $payload['argv'] = $this->argv;
        $payload['extend'] = $this->extend;
        $payload['output'] = $this->output;

        return ScheduleEvent::load($payload);
    }

    /**
     * 诊断 / 调试用的精简数组，不含 raw / extend / argv 等可能较大的载荷。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'cron_task_id' => $this->cronTaskId,
            'cron_name' => $this->cronName,
            'expression' => $this->expression,
            'exec_type' => $this->execType,
            'status' => $this->status,
            'with_block_lapping' => $this->withBlockLapping ? 1 : 0,
            'retry' => $this->retry,
            'command' => $this->command,
            'node_id' => $this->nodeId,
            'cron_between' => $this->cronBetween,
            'cron_skip' => $this->cronSkip,
            'http_method' => $this->httpMethod,
            'http_body' => $this->httpBody,
            'http_headers' => $this->httpHeaders,
            'http_request_time_out' => $this->httpRequestTimeOut,
            'url' => $this->url,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * 将 cron_between / cron_skip 规范为 list of [start, end]。
     * 兼容历史单窗 [start, end]、多窗 [[start, end], ...]，以及 Admin JSON `{start, end}`。
     *
     * @param mixed $value
     * @return list<array{0:mixed,1:mixed}>
     */
    private static function normalizeWindows(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $pair = self::parseWindowString($value);
                return $pair === null ? [] : [$pair];
            }
        }

        if (!is_array($value) || $value === []) {
            return [];
        }
        $pair = self::windowPair($value);
        if ($pair !== null) {
            return [$pair];
        }

        $windows = [];
        foreach ($value as $item) {
            $normalized = self::windowPair($item);
            if ($normalized !== null) {
                $windows[] = $normalized;
            }
        }

        return $windows;
    }

    /**
     * 单窗 → [start, end]；无法识别则 null。
     *
     * @param mixed $window
     * @return array{0:mixed,1:mixed}|null
     */
    private static function windowPair(mixed $window): ?array
    {
        if (is_string($window)) {
            return self::parseWindowString($window);
        }
        if (!is_array($window)) {
            return null;
        }
        if (isset($window['start'], $window['end'])) {
            return [$window['start'], $window['end']];
        }
        if (isset($window[0], $window[1]) && !is_array($window[0])) {
            return [$window[0], $window[1]];
        }

        return null;
    }

    /**
     * 历史字符串窗兼容：支持 `HH:MM-HH:MM`、`start - end` 及可解析 JSON 字符串。
     *
     * @return array{0:string,1:string}|null
     */
    private static function parseWindowString(string $value): ?array
    {
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $pair = self::windowPair($decoded);
            if ($pair !== null) {
                return [(string) $pair[0], (string) $pair[1]];
            }
        }

        if (preg_match('/^([0-2]?\d:[0-5]\d(?::[0-5]\d)?)\s*(?:-|~|to)\s*([0-2]?\d:[0-5]\d(?::[0-5]\d)?)$/i', $text, $m) === 1) {
            return [$m[1], $m[2]];
        }
        if (preg_match('/^(.+?)\s+-\s+(.+)$/', $text, $m) === 1) {
            $start = trim($m[1]);
            $end = trim($m[2]);
            if ($start !== '' && $end !== '') {
                return [$start, $end];
            }
        }
        if (preg_match('/^(.+?)\s*(?:,|\|)\s*(.+)$/', $text, $m) === 1) {
            $start = trim($m[1]);
            $end = trim($m[2]);
            if ($start !== '' && $end !== '') {
                return [$start, $end];
            }
        }

        return null;
    }

    /**
     * JSON 字符串或数组 → 数组；非法 JSON / 其它类型 → []。
     *
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function asArray(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * command / exec_script 是否应按 HTTP URL 回填（仅认 http:// 与 https://）。
     */
    private static function looksLikeUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }
}
