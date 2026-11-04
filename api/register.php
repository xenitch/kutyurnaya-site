<?php
// Приём заявок с kutyurnaya.ru: заявка → amoCRM (контакт + сделка) → переход на оплату.
declare(strict_types=1);

$config = require dirname(__DIR__, 2) . '/kutyurnaya-config.php';

const AMO_FIELD_PHONE    = 705935;
const AMO_FIELD_TELEGRAM = 705981;
const AMO_FIELD_INFO     = 705991;  // «Информация»
const AMO_FIELD_SOURCE   = 705995;  // «Источник заказа»

function fail_page(string $message, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html><html lang=\"ru\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
       . "<meta name=\"robots\" content=\"noindex\"><title>Заявка не отправлена — Кутюрная</title><link rel=\"stylesheet\" href=\"/style.css\"></head>"
       . "<body><main class=\"note\"><h1>Заявка не отправлена</h1><p>$m</p><p><a href=\"javascript:history.back()\">← Вернуться к форме</a></p></main></body></html>";
    exit;
}

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /', true, 303);
    exit;
}

// Антиспам: скрытое поле должно остаться пустым
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    header('Location: /spasibo/', true, 303);
    exit;
}

$name     = trim((string) ($_POST['name'] ?? ''));
$phoneRaw = trim((string) ($_POST['phone'] ?? ''));
$telegram = trim((string) ($_POST['telegram'] ?? ''));
$eventKey = (string) ($_POST['event'] ?? '');
$consent  = isset($_POST['consent']);

$event = $config['events'][$eventKey] ?? null;
if (!$event || empty($event['open'])) { fail_page('Регистрация на это мероприятие закрыта.'); }
if ($name === '' || mb_strlen($name) > 100) { fail_page('Укажите имя.'); }
$digits = preg_replace('/\D+/', '', $phoneRaw);
if (strlen($digits) === 11 && $digits[0] === '8') { $digits = '7' . substr($digits, 1); }
if (strlen($digits) === 10) { $digits = '7' . $digits; }
if (strlen($digits) < 11 || strlen($digits) > 15) { fail_page('Проверьте номер телефона.'); }
$phone = '+' . $digits;
$telegram = str_replace(['—', '–'], '-', $telegram);
if ($telegram === '') { fail_page('Укажите ник в телеграме или поставьте прочерк.'); }
if (mb_strlen($telegram) > 64) { fail_page('Слишком длинный ник в телеграме.'); }
if ($telegram !== '-') {
    $telegram = preg_replace('~^(https?://)?(t\.me/|telegram\.me/)~i', '', $telegram);
    $telegram = '@' . ltrim($telegram, '@');
}
if (!$consent) { fail_page('Нужно согласие на обработку персональных данных.'); }

$reg = ['event' => $eventKey, 'name' => $name, 'phone' => $phone, 'telegram' => $telegram];
log_line($config, 'registrations.jsonl', $reg);   // копия заявки на хостинге — на случай сбоя amoCRM

$info = "Имя: $name\nНомер телефона: $phone\nТелеграм: " . ($telegram !== '-' ? $telegram : 'нет');

try {
    // Ищем существующий контакт по телефону (последние 10 цифр), чтобы не плодить дубли
    $contactId = null;
    $tail = substr($digits, -10);
    $found = amo($config, 'GET', '/contacts?query=' . rawurlencode($tail) . '&limit=10');
    foreach ($found['_embedded']['contacts'] ?? [] as $c) {
        foreach ($c['custom_fields_values'] ?? [] as $f) {
            if ((int) $f['field_id'] !== AMO_FIELD_PHONE) { continue; }
            foreach ($f['values'] as $v) {
                if (substr(preg_replace('/\D+/', '', (string) $v['value']), -10) === $tail) { $contactId = (int) $c['id']; break 3; }
            }
        }
    }

    $contact = $contactId ? ['id' => $contactId] : [
        'name' => $name,
        'custom_fields_values' => array_values(array_filter([
            ['field_id' => AMO_FIELD_PHONE, 'values' => [['value' => $phone, 'enum_code' => 'WORK']]],
            $telegram !== '-' ? ['field_id' => AMO_FIELD_TELEGRAM, 'values' => [['value' => $telegram]]] : null,
        ])),
    ];

    $lead = [
        'name'        => 'Регистрация: ' . $event['title'],
        'price'       => (int) $event['price'],
        'pipeline_id' => (int) $config['pipeline_id'],
        'status_id'   => (int) $config['status_id'],
        'custom_fields_values' => [
            ['field_id' => AMO_FIELD_INFO,   'values' => [['value' => $info]]],
            ['field_id' => AMO_FIELD_SOURCE, 'values' => [['value' => 'https://kutyurnaya.ru/ · ' . $eventKey]]],
        ],
        '_embedded' => ['contacts' => [$contact]],
    ];
    $res = amo($config, 'POST', '/leads/complex', [$lead]);
    log_line($config, 'amo-ok.jsonl', ['event' => $eventKey, 'lead_id' => $res[0]['id'] ?? null, 'contact_existing' => (bool) $contactId]);
} catch (Throwable $e) {
    // Заявка уже сохранена в registrations.jsonl; человека не задерживаем
    log_line($config, 'amo-errors.jsonl', ['event' => $eventKey, 'phone' => $phone, 'error' => $e->getMessage()]);
}

$target = $event['payment_url'] !== '' ? $event['payment_url'] : '/spasibo/';
header('Location: ' . $target, true, 303);
