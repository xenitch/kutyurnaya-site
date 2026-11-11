<?php
// Общие функции сайта Кутюрной: amoCRM, Telegram, журнал, тексты бота.
// На сервере: ~/kutyurnaya-app/lib.php (вне public_html).
declare(strict_types=1);

const MSG_AMO_FAILED    = '⚠️ Заявка с сайта не попала в amoCRM, копия сохранена на хостинге. Напишите Claude, чтобы перенести её вручную.';
const MSG_REPORT_FAILED = '⚠️ Утренний отчёт не собран: amoCRM не ответил';

// Данные (журналы, очередь) лежат внутри public_html: с 11.11.2026 PHP сайта на Timeweb не видит файлов вне
// папки сайта. Статичные файлы оттуда отдаются в интернет, поэтому все файлы данных — .php с заглушкой в первой
// строке: при запросе из браузера PHP выполняет её и ничего не показывает.
const DATA_GUARD = "<?php http_response_code(404); exit; ?>\n";

function data_file(array $config, string $name): string {
    $dir = $config['log_dir'];
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    return "$dir/$name.php";
}

function data_append(string $path, string $line): void {
    $fh = @fopen($path, 'a');
    if (!$fh) { return; }
    flock($fh, LOCK_EX);
    if (fstat($fh)['size'] === 0) { fwrite($fh, DATA_GUARD); }
    fwrite($fh, $line . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

// Строки данных без заглушки.
function data_lines(string $path): array {
    $guard = rtrim(DATA_GUARD);
    return array_values(array_filter(@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], fn(string $l): bool => $l !== $guard));
}

function log_line(array $config, string $file, array $data): void {
    $data['ts'] = date('c');
    data_append(data_file($config, $file), json_encode($data, JSON_UNESCAPED_UNICODE));
}

// HTTP/1.1: по HTTP/2 соединение с amoCRM с хостинга иногда рвётся («Error in the HTTP2 framing layer»).
function amo_curl_options(array $config, string $method): array {
    return [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $config['amo_token'], 'Content-Type: application/json'],
    ];
}

// Повторяет $fn до $tries раз с паузой $pauseSec; последняя ошибка пробрасывается.
function with_retries(callable $fn, int $tries, int $pauseSec) {
    for ($i = 1; ; $i++) {
        try { return $fn(); }
        catch (Throwable $e) { if ($i >= $tries) { throw $e; } if ($pauseSec > 0) { sleep($pauseSec); } }
    }
}

function amo(array $config, string $method, string $path, ?array $body = null): array {
    $ch = curl_init("https://{$config['amo_subdomain']}.amocrm.ru/api/v4$path");
    curl_setopt_array($ch, amo_curl_options($config, $method));
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

// Сделки, билеты, выручка (поле «Бюджет») и бесплатные места (бюджет 0) воронки в статусе.
// amoCRM отдаёт максимум 250 сделок за раз. Пустое поле «Билетов» = 1.
function amo_count_tickets(array $config, int $statusId, ?callable $amoFn = null): array {
    $amoFn ??= 'amo';
    $p = (int) $config['pipeline_id'];
    $field = (int) ($config['amo_field_tickets'] ?? 0);
    $leads = 0;
    $tickets = 0;
    $revenue = 0;
    $free = 0;
    for ($page = 1; $page <= 40; $page++) {
        $res = $amoFn($config, 'GET', "/leads?filter[statuses][0][pipeline_id]=$p&filter[statuses][0][status_id]=$statusId&limit=250&page=$page");
        $got = $res['_embedded']['leads'] ?? [];
        foreach ($got as $lead) {
            $t = $field ? lead_tickets($lead, $field) : 1;
            $price = (int) ($lead['price'] ?? 0);
            $leads++;
            $tickets += $t;
            $revenue += $price;
            if ($price === 0) { $free += $t; }
        }
        if (count($got) < 250) { break; }
    }
    return ['leads' => $leads, 'tickets' => $tickets, 'revenue' => $revenue, 'free' => $free];
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

function sum_counts(array ...$counts): array {
    $sum = ['leads' => 0, 'tickets' => 0, 'revenue' => 0, 'free' => 0];
    foreach ($counts as $c) { foreach ($sum as $k => $_) { $sum[$k] += (int) ($c[$k] ?? 0); } }
    return $sum;
}

// $taken — «оплатил» + «отправили билет», $waiting — «заявка с сайта».
function msg_report(string $title, array $taken, array $waiting): string {
    $revenue = 'Выручка: ' . number_format($taken['revenue'], 0, '', "\u{00A0}") . ' ₽'
             . ($taken['free'] ? " · бесплатных мест: {$taken['free']}" : '');
    return "☀️ $title\nЗанято мест: {$taken['tickets']} (заявок: {$taken['leads']})\n$revenue\nЖдут оплаты: {$waiting['tickets']}";
}

// Очередь сообщений в чат: с сервера Telegram доступен через раз, поэтому форма только кладёт
// сообщение в очередь (мгновенно), а tg_flush() по cron досылает. Сообщения старше суток выбрасываются.
const TG_MAX_AGE = 86400;

function tg_queue(array $config, string $text): void {
    data_append(data_file($config, 'tg-outbox.jsonl'), json_encode(['text' => $text, 'queued' => time()], JSON_UNESCAPED_UNICODE));
}

// Досылает очередь по порядку; при первом сбое Telegram останавливается до следующего запуска.
// Возвращает ['sent' => отправлено, 'left' => осталось] или left = -1, если уже идёт другой запуск.
function tg_flush(array $config, ?callable $post = null): array {
    $lock = @fopen(data_file($config, 'tg-outbox.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { return ['sent' => 0, 'left' => -1]; }
    $box  = data_file($config, 'tg-outbox.jsonl');
    $work = data_file($config, 'tg-outbox.processing.jsonl');   // то, что разбираем сейчас (или осталось с прошлого раза)
    $sent = 0;
    $rest = [];
    foreach ([false, true] as $takeBox) {
        if ($takeBox) {
            if (!file_exists($box)) { break; }
            rename($box, $work);   // атомарно: новые заявки пишутся уже в новый tg-outbox.jsonl
        }
        if (!file_exists($work)) { continue; }
        $failed = false;
        foreach (data_lines($work) as $line) {
            $item = json_decode($line, true);
            if (!is_array($item) || !isset($item['text'])) { continue; }
            if ($failed) { $rest[] = $line; continue; }
            if (time() - (int) ($item['queued'] ?? 0) > TG_MAX_AGE) {
                log_line($config, 'tg-errors.jsonl', ['dropped' => 'старше суток', 'queued' => (int) ($item['queued'] ?? 0)]);
                continue;
            }
            if (tg_send($config, (string) $item['text'], $post)) { $sent++; } else { $failed = true; $rest[] = $line; }
        }
        if ($rest) { file_put_contents($work, DATA_GUARD . implode("\n", $rest) . "\n"); break; }
        unlink($work);
    }
    $left = count($rest) + count(data_lines($box));
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

// Проверка сайта (server/health-check.php): $r — коды ответов главной, страницы регистрации и формы
// (форма получает «пустышку» с заполненным антиспам-полем и должна перевести на /spasibo/, не создавая сделку).
function health_problems(array $r): array {
    $codeText = fn(int $c): string => $c === 0 ? 'нет ответа' : "ответ $c";
    $problems = [];
    if ((int) $r['home'] !== 200) { $problems[] = 'главная: ' . $codeText((int) $r['home']); }
    if ((int) $r['reg'] !== 200) { $problems[] = 'страница регистрации: ' . $codeText((int) $r['reg']); }
    $form = $r['form'];
    if ((int) $form['code'] === 0) { $problems[] = 'форма: нет ответа'; }
    elseif ((int) $form['code'] !== 303 || !str_ends_with((string) $form['location'], '/spasibo/')) {
        $problems[] = 'форма: ' . $codeText((int) $form['code']) . ' вместо перехода на /spasibo/';
    }
    return $problems;
}

// Сообщение в чат только при смене состояния. $wasOk: прошлый результат (null — проверок ещё не было).
function health_message(?bool $wasOk, array $problems): ?string {
    if ($problems && $wasOk !== false) {
        return "🚨 Регистрация на сайте kutyurnaya.ru не работает\n· " . implode("\n· ", $problems) . "\nНапишите Claude.";
    }
    if (!$problems && $wasOk === false) { return '✅ Регистрация на сайте снова работает.'; }
    return null;
}
