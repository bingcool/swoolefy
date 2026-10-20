<?php

declare(strict_types=1);

namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Swoolefy\Http\RequestInput;
use Swoolefy\Library\Mail\Exception\InvalidMailerException;
use Swoolefy\Library\Mail\Exception\InvalidMailMessageException;
use Swoolefy\Library\Mail\Exception\MailTransportException;
use Swoolefy\Library\Mail\Exception\PartialDeliveryException;
use Swoolefy\Library\Mail\Exception\TemplateNotFoundException;
use Swoolefy\Library\Mail\MailerManager;
use Swoolefy\Library\Mail\MailMessage;
use Swoolefy\Library\Mail\Template\TemplateRenderer;
use Swoolefy\Library\Mail\Transport\LogTransport;
use Throwable;

/**
 * bingcool/library Mail 模块 HTTP 联调。
 *
 * Test 应用只注册 log transport：MailMessage 仍走完整的编译、模板、
 * 附件与收件人校验，只是不创建 PHPMailer、不连接 SMTP、不真正投递。
 * 每个响应都带同一请求内 LogTransport::sent() 的脱敏信封，便于核对最终结果。
 */
class MailController extends BController
{
    /**
     * 发送纯文本或 HTML 邮件（默认 log transport）。
     *
     * Route: GET|POST /api/mail/send
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/mail/send?to=user@example.com&subject=hello&text=hi' | jq .
     ```
     */
    #[ApiOperation(description: 'Mail send via log transport')]
    public function send(RequestInput $requestInput): array
    {
        try {
            $message = (new MailMessage())
                ->to($this->param($requestInput, 'to', 'user@example.com'))
                ->subject($this->param($requestInput, 'subject', 'Swoolefy 邮件联调'));

            $html = $this->param($requestInput, 'html', '');
            if ($html !== '') {
                $message->html($html);
            } else {
                $message->text($this->param($requestInput, 'text', '这是一封由 MailController 发送的测试邮件。'));
            }

            $result = $this->mail()->send($message);

            return $this->ok([
                'message_id' => $result->messageId,
                'transport' => $result->transport,
                'envelope' => $this->lastEnvelope(),
                'sent_count' => count($this->sentEnvelopes()),
            ]);
        } catch (InvalidMailerException|InvalidMailMessageException|TemplateNotFoundException $e) {
            return $this->fail(400, $e->getMessage(), $e::class);
        } catch (MailTransportException|PartialDeliveryException $e) {
            return $this->fail(502, $e->getMessage(), $e::class);
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage(), $e::class);
        }
    }

    /**
     * 模板渲染并发信：{{ name }} 会被 HTML 转义，{{{ raw }}} 原样输出。
     *
     * Route: GET|POST /api/mail/template
     *
     ```bash
     curl -sG 'http://127.0.0.1:9501/api/mail/template' \
       --data-urlencode 'name=<script>alert(1)</script>' \
       --data-urlencode 'raw=<b>可信 HTML</b>' | jq .
     ```
     */
    #[ApiOperation(description: 'Mail template render + send')]
    public function template(RequestInput $requestInput): array
    {
        try {
            $name = $this->param($requestInput, 'name', '张三');
            $raw = $this->param($requestInput, 'raw', '<b>这段 HTML 按原样输出</b>');
            $vars = [
                'name' => $name,
                'raw' => $raw,
                'sent_at' => date('Y-m-d H:i:s'),
            ];

            $config = $this->mail()->config();
            $renderer = new TemplateRenderer($config->templatePath());
            $html = $renderer->render('welcome', array_merge($config->templateVars(), $vars));

            $result = $this->mail()->send(
                (new MailMessage())
                    ->to($this->param($requestInput, 'to', 'user@example.com'))
                    ->subject($this->param($requestInput, 'subject', '模板邮件联调'))
                    ->viaTemplate('welcome', $vars)
            );

            return $this->ok([
                'message_id' => $result->messageId,
                'transport' => $result->transport,
                'template' => 'welcome',
                'view_path' => $config->templatePath(),
                'name_escaped' => $name !== '' && str_contains($name, '<') ? !str_contains($html, $name) : null,
                'raw_html_kept' => $raw !== '' ? str_contains($html, $raw) : null,
                'rendered_html' => $html,
                'envelope' => $this->lastEnvelope(),
            ]);
        } catch (InvalidMailerException|InvalidMailMessageException|TemplateNotFoundException $e) {
            return $this->fail(400, $e->getMessage(), $e::class);
        } catch (MailTransportException|PartialDeliveryException $e) {
            return $this->fail(502, $e->getMessage(), $e::class);
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage(), $e::class);
        }
    }

    /**
     * 附件、CC/BCC 与跨列表去重联调。
     *
     * 先 cc 与 to 相同的地址，再 cc 一个独立地址；共享去重集合会丢弃重复项。
     * BCC 只出现在 bcc_count，不进入 sent() 的收件人列表。
     *
     * Route: GET|POST /api/mail/attachment
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/mail/attachment' | jq .
     ```
     */
    #[ApiOperation(description: 'Mail attachment + recipient dedup')]
    public function attachment(RequestInput $requestInput): array
    {
        try {
            $to = $this->param($requestInput, 'to', 'user@example.com');

            $message = (new MailMessage())
                ->to($to)
                ->cc($to)
                ->cc($this->param($requestInput, 'cc', 'cc@example.com'))
                ->bcc($this->param($requestInput, 'bcc', 'bcc@example.com'))
                ->subject($this->param($requestInput, 'subject', '附件与收件人去重联调'))
                ->html('<p>附件由 attachData 写入内存，BCC 不会进入邮件头。</p>')
                ->attachData($this->param($requestInput, 'note', 'mail attachment integration test'), 'note.txt', 'text/plain');

            $result = $this->mail()->send($message);

            return $this->ok([
                'message_id' => $result->messageId,
                'transport' => $result->transport,
                'recipient_count' => $message->recipientCount(),
                'cc_count' => count($message->ccList()),
                'bcc_count' => count($message->bccList()),
                'attachment_count' => count($message->attachmentList()),
                'envelope' => $this->lastEnvelope(),
            ]);
        } catch (InvalidMailerException|InvalidMailMessageException|TemplateNotFoundException $e) {
            return $this->fail(400, $e->getMessage(), $e::class);
        } catch (MailTransportException|PartialDeliveryException $e) {
            return $this->fail(502, $e->getMessage(), $e::class);
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage(), $e::class);
        }
    }

    /**
     * 校验失败路径联调。返回 expected=true 表示异常按预期抛出。
     *
     * case: no_recipient | bad_address | empty_subject | missing_body |
     *       too_many（配置 max_recipients=5）| template_traversal | unknown_mailer |
     *       attachment_too_large（配置 max_bytes=1MB）| header_injection | mime_injection
     *
     * Route: GET|POST /api/mail/invalid
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/mail/invalid?case=template_traversal' | jq .
     ```
     */
    #[ApiOperation(description: 'Mail validation failure cases')]
    public function invalid(RequestInput $requestInput): array
    {
        $case = $this->param($requestInput, 'case', 'no_recipient');

        try {
            $message = match ($case) {
                'no_recipient' => (new MailMessage())->subject('no recipient')->text('body'),
                'bad_address' => (new MailMessage())->to('not-an-email')->subject('bad address')->text('body'),
                'empty_subject' => (new MailMessage())->to('user@example.com')->text('body'),
                'missing_body' => (new MailMessage())->to('user@example.com')->subject('missing body'),
                'too_many' => $this->manyRecipients(),
                'template_traversal' => (new MailMessage())
                    ->to('user@example.com')->subject('bad template')->viaTemplate('../welcome'),
                'unknown_mailer' => (new MailMessage())
                    ->via('missing')->to('user@example.com')->subject('unknown mailer')->text('body'),
                'attachment_too_large' => (new MailMessage())
                    ->to('user@example.com')->subject('big attachment')->text('body')
                    ->attachData(str_repeat('a', 1024 * 1024 + 1), 'big.bin', 'application/octet-stream'),
                'header_injection' => (new MailMessage())
                    ->to('user@example.com')->subject("hello\r\nBcc: evil@example.com")->text('body'),
                'mime_injection' => (new MailMessage())
                    ->to('user@example.com')->subject('bad mime')->text('body')
                    ->attachData('x', 'x.txt', "text/plain\r\nX-Evil: 1"),
                default => throw new \InvalidArgumentException(sprintf('Unknown invalid case [%s]', $case)),
            };

            $this->mail()->send($message);

            return $this->fail(500, 'Expected validation failure but send() succeeded', null);
        } catch (InvalidMailerException|InvalidMailMessageException|TemplateNotFoundException $e) {
            return $this->ok([
                'case' => $case,
                'expected' => true,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage(), $e::class);
        }
    }

    /**
     * 当前生效的 Mail 配置（不返回 username/password）。
     *
     * Route: GET /api/mail/config
     *
     ```bash
     curl -s 'http://127.0.0.1:9501/api/mail/config' | jq .
     ```
     */
    #[ApiOperation(description: 'Mail effective config without secrets')]
    public function config(): array
    {
        try {
            $config = $this->mail()->config();
            $mailers = [];
            foreach ($config->mailers() as $name => $mailer) {
                if (!is_array($mailer)) {
                    continue;
                }
                $from = $mailer['from'] ?? null;
                $mailers[$name] = [
                    'transport' => $mailer['transport'] ?? null,
                    'host' => $mailer['host'] ?? null,
                    'port' => $mailer['port'] ?? null,
                    'encryption' => $mailer['encryption'] ?? null,
                    'from' => is_array($from) ? ($from['address'] ?? null) : $from,
                    'has_username' => trim((string) ($mailer['username'] ?? '')) !== '',
                    'has_password' => trim((string) ($mailer['password'] ?? '')) !== '',
                ];
            }

            return $this->ok([
                'default_mailer' => $config->defaultMailer(),
                'mailers' => $mailers,
                'template_view_path' => $config->templatePath(),
                'template_vars' => $config->templateVars(),
                'max_recipients' => $config->maxRecipients(),
                'attachment_max_bytes' => $config->attachmentMaxBytes(),
            ]);
        } catch (Throwable $e) {
            return $this->fail(500, $e->getMessage(), $e::class);
        }
    }

    /**
     * 构造 6 个不同收件人，用于触发测试配置里的 max_recipients=5。
     */
    private function manyRecipients(): MailMessage
    {
        $message = (new MailMessage())->subject('too many recipients')->text('body');
        for ($i = 1; $i <= 6; $i++) {
            $message->to('user' . $i . '@example.com');
        }

        return $message;
    }

    /**
     * 同一请求内 log transport 缓存的脱敏信封；非 log 传输返回空数组。
     *
     * @return list<array<string, mixed>>
     */
    private function sentEnvelopes(): array
    {
        $mailer = $this->mail()->mailer();

        return $mailer instanceof LogTransport ? $mailer->sent() : [];
    }

    /**
     * 最近一封脱敏信封，响应字段名与 LogTransport::sent() 对齐。
     *
     * @return array<string, mixed>|null
     */
    private function lastEnvelope(): ?array
    {
        $envelopes = $this->sentEnvelopes();
        if ($envelopes === []) {
            return null;
        }

        return $envelopes[array_key_last($envelopes)];
    }

    /**
     * 从请求取字符串参数并 trim；请求值统一转成 string。
     */
    private function param(RequestInput $requestInput, string $key, string $default): string
    {
        $value = $requestInput->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * 从 DI 取 MailerManager；组件定义见 Test/Config/component/mail.php。
     */
    private function mail(): MailerManager
    {
        $app = Application::getApp();
        if ($app === null) {
            throw new \RuntimeException('Application is not available');
        }

        $component = $app->get('mail');
        if ($component instanceof ContainerObjectDto) {
            $component = $component->getObject();
        }
        if (!$component instanceof MailerManager) {
            throw new \RuntimeException('mail component is not a MailerManager');
        }

        return $component;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data): array
    {
        return ['code' => 0, 'msg' => 'ok', 'data' => $data];
    }

    private function fail(int $code, string $msg, ?string $exception): array
    {
        return [
            'code' => $code,
            'msg' => $msg,
            'data' => $exception === null ? null : ['exception' => $exception],
        ];
    }
}
