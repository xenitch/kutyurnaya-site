<?php
// Общие функции сайта Кутюрной: amoCRM, Telegram, журнал, тексты бота.
// На сервере: ~/kutyurnaya-app/lib.php (вне public_html).
declare(strict_types=1);

const MSG_AMO_FAILED    = '⚠️ Заявка с сайта не попала в amoCRM, копия сохранена на хостинге. Напишите Claude, чтобы перенести её вручную.';
const MSG_REPORT_FAILED = '⚠️ Утренний отчёт не собран: amoCRM не ответил';

function log_line(array $config, string $file, array $data): void {
    $dir = $config['log_dir'];
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $data['ts'] = date('c');
    @file_put_contents("$dir/$file", json_encode($data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

function amo(array $config, string $method, string $path, ?array $body = null): array {
    $ch = curl_init("https://{$config['amo_subdomain']}.amocrm.ru/api/v4$path");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $config['amo_token'], 'Content-Type: application/json'],
    ]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE)); }
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code >= 400) {
        throw new RuntimeException("amoCRM $method $path → HTTP $code $err " . substr((string) $raw, 0, 500));
    }
    return $code === 204 || $raw === '' ? [] : (json_decode($raw, true) ?? []);
}

function tg_http_post(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 4,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body === false ? '' : (string) $body, 'error' => $err];
}

// Сообщение в чат организаторов (ветка telegram_thread_id). Никогда не бросает исключений.
function tg_send(array $config, string $text, ?callable $post = null): bool {
    $token = (string) ($config['telegram_token'] ?? '');
    if ($token === '') { return false; }
    $fields = ['chat_id' => (string) $config['telegram_chat_id'], 'text' => $text, 'disable_web_page_preview' => 'true'];
    if (!empty($config['telegram_thread_id'])) { $fields['message_thread_id'] = (string) $config['telegram_thread_id']; }
    $post ??= 'tg_http_post';
    try {
        $res = $post("https://api.telegram.org/bot$token/sendMessage", $fields);
        $ok  = $res['code'] === 200 && (json_decode($res['body'], true)['ok'] ?? false) === true;
    } catch (Throwable $e) {
        $res = ['code' => 0, 'body' => '', 'error' => $e->getMessage()];
        $ok  = false;
    }
    if (!$ok) {
        $detail = str_replace($token, '***', trim($res['error'] . ' ' . $res['body']));
        log_line($config, 'tg-errors.jsonl', ['code' => $res['code'], 'error' => mb_substr($detail, 0, 300)]);
    }
    return $ok;
}

function msg_new_lead(array $config, string $title, ?int $leadId, bool $existing): string {
    $lines = ["🆕 Новая заявка · $title"];
    if ($leadId) { $lines[] = "https://{$config['amo_subdomain']}.amocrm.ru/leads/detail/$leadId"; }
    if ($existing) { $lines[] = 'контакт уже был в базе'; }
    return implode("\n", $lines);
}
