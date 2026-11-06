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

function msg_new_lead(array $config, string $title, ?array $tariff, ?int $leadId, bool $existing): string {
    $lines = ["🆕 Новая заявка · $title"];
    if ($tariff) { $lines[] = $tariff['title'] . ' · ' . tickets_word((int) $tariff['tickets']); }
    if ($leadId) { $lines[] = "https://{$config['amo_subdomain']}.amocrm.ru/leads/detail/$leadId"; }
    if ($existing) { $lines[] = 'контакт уже был в базе'; }
    return implode("\n", $lines);
}

// Мероприятия, по которым шлём утренний отчёт: регистрация открыта и день лекции не прошёл.
function report_events(array $events, string $today): array {
    return array_filter($events, fn(array $e): bool => !empty($e['open']) && isset($e['date']) && $e['date'] >= $today);
}

// Сделки и билеты воронки в статусе; amoCRM отдаёт максимум 250 сделок за раз. Пустое поле «Билетов» = 1.
function amo_count_tickets(array $config, int $statusId, ?callable $amoFn = null): array {
    $amoFn ??= 'amo';
    $p = (int) $config['pipeline_id'];
    $field = (int) ($config['amo_field_tickets'] ?? 0);
    $leads = 0;
    $tickets = 0;
    for ($page = 1; $page <= 40; $page++) {
        $res = $amoFn($config, 'GET', "/leads?filter[statuses][0][pipeline_id]=$p&filter[statuses][0][status_id]=$statusId&limit=250&page=$page");
        $got = $res['_embedded']['leads'] ?? [];
        foreach ($got as $lead) { $leads++; $tickets += $field ? lead_tickets($lead, $field) : 1; }
        if (count($got) < 250) { break; }
    }
    return ['leads' => $leads, 'tickets' => $tickets];
}

function lead_tickets(array $lead, int $fieldId): int {
    foreach ($lead['custom_fields_values'] ?? [] as $f) {
        if ((int) ($f['field_id'] ?? 0) === $fieldId) {
            $v = (int) ($f['values'][0]['value'] ?? 0);
            return $v >= 1 ? $v : 1;
        }
    }
    return 1;
}

function msg_report(string $title, array $paid, array $ticket, array $waiting): string {
    return "☀️ $title\nЗанято мест: " . ($paid['tickets'] + $ticket['tickets']) . ' (заявок: ' . ($paid['leads'] + $ticket['leads']) . ')'
         . "\n· оплатили, билет ещё не отправлен: {$paid['tickets']}\n· билет отправлен: {$ticket['tickets']}\nЖдут оплаты: {$waiting['tickets']}";
}

// Очередь сообщений в чат: с сервера Telegram доступен через раз, поэтому форма только кладёт
// сообщение в очередь (мгновенно), а tg_flush() по cron досылает. Сообщения старше суток выбрасываются.
const TG_MAX_AGE = 86400;

function tg_queue(array $config, string $text): void {
    $dir = $config['log_dir'];
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    @file_put_contents("$dir/tg-outbox.jsonl", json_encode(['text' => $text, 'queued' => time()], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// Досылает очередь по порядку; при первом сбое Telegram останавливается до следующего запуска.
// Возвращает ['sent' => отправлено, 'left' => осталось] или left = -1, если уже идёт другой запуск.
function tg_flush(array $config, ?callable $post = null): array {
    $dir = $config['log_dir'];
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $lock = @fopen("$dir/tg-outbox.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { return ['sent' => 0, 'left' => -1]; }
    $box  = "$dir/tg-outbox.jsonl";
    $work = "$dir/tg-outbox.processing.jsonl";   // то, что разбираем сейчас (или осталось с прошлого раза)
    $sent = 0;
    $rest = [];
    foreach ([false, true] as $takeBox) {
        if ($takeBox) {
            if (!file_exists($box)) { break; }
            rename($box, $work);   // атомарно: новые заявки пишутся уже в новый tg-outbox.jsonl
        }
        if (!file_exists($work)) { continue; }
        $failed = false;
        foreach (file($work, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $item = json_decode($line, true);
            if (!is_array($item) || !isset($item['text'])) { continue; }
            if ($failed) { $rest[] = $line; continue; }
            if (time() - (int) ($item['queued'] ?? 0) > TG_MAX_AGE) {
                log_line($config, 'tg-errors.jsonl', ['dropped' => 'старше суток', 'queued' => (int) ($item['queued'] ?? 0)]);
                continue;
            }
            if (tg_send($config, (string) $item['text'], $post)) { $sent++; } else { $failed = true; $rest[] = $line; }
        }
        if ($rest) { file_put_contents($work, implode("\n", $rest) . "\n"); break; }
        unlink($work);
    }
    $left = count($rest) + (file_exists($box) ? count(file($box, FILE_SKIP_EMPTY_LINES) ?: []) : 0);
    flock($lock, LOCK_UN);
    fclose($lock);
    return ['sent' => $sent, 'left' => $left];
}

function tickets_word(int $n): string {
    $m10 = $n % 10;
    $m100 = $n % 100;
    if ($m10 === 1 && $m100 !== 11) { $w = 'билет'; }
    elseif ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) { $w = 'билета'; }
    else { $w = 'билетов'; }
    return "$n $w";
}

// Тариф мероприятия по ключу из формы. Поля нет совсем (старая закэшированная главная) — «Экземпляр»;
// пустое поле (скрипт /registraciya/ не сработал) — ошибка, а не тихая подмена тарифа.
function pick_tariff(array $event, ?string $key): ?array {
    $key ??= 'ekzemplyar';
    $t = $event['tariffs'][$key] ?? null;
    return is_array($t) ? $t + ['key' => $key] : null;
}
