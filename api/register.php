<?php
// Приём заявок с kutyurnaya.ru: заявка → amoCRM (контакт + сделка) → сообщение в чат организаторов → переход на оплату.
declare(strict_types=1);

$config = require dirname(__DIR__, 2) . '/kutyurnaya-config.php';
require dirname(__DIR__, 2) . '/kutyurnaya-app/lib.php';   // amo(), log_line(), tg_queue(), тексты бота

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
$newsletter = isset($_POST['newsletter']);
const NEWSLETTER_TAG = 'согласие на рассылку';
const NEWSLETTER_CONSENT_VERSION = 'soglasie-rassylka 2026-11-05';

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

$reg = ['event' => $eventKey, 'name' => $name, 'phone' => $phone, 'telegram' => $telegram,
        'newsletter' => $newsletter ? NEWSLETTER_CONSENT_VERSION : false,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)];
log_line($config, 'registrations.jsonl', $reg);   // копия заявки на хостинге — на случай сбоя amoCRM

$info = "Имя: $name\nНомер телефона: $phone\nТелеграм: " . ($telegram !== '-' ? $telegram : 'нет')
      . "\nРассылка анонсов: " . ($newsletter ? 'согласие дано ' . date('d.m.Y H:i') : 'нет');

$notice = MSG_AMO_FAILED;   // в чат организаторов; без персональных данных
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

    $tags = $newsletter ? [['name' => NEWSLETTER_TAG]] : [];
    if ($contactId && $newsletter) {
        amo($config, 'PATCH', "/contacts/$contactId", ['tags_to_add' => $tags]);
    }
    $contact = $contactId ? ['id' => $contactId] : [
        'name' => $name,
        '_embedded' => ['tags' => $tags],
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
        '_embedded' => ['contacts' => [$contact], 'tags' => $tags],
    ];
    $res = amo($config, 'POST', '/leads/complex', [$lead]);
    log_line($config, 'amo-ok.jsonl', ['event' => $eventKey, 'lead_id' => $res[0]['id'] ?? null, 'contact_existing' => (bool) $contactId]);
    $notice = msg_new_lead($config, $event['title'], isset($res[0]['id']) ? (int) $res[0]['id'] : null, (bool) $contactId);
} catch (Throwable $e) {
    // Заявка уже сохранена в registrations.jsonl; человека не задерживаем
    log_line($config, 'amo-errors.jsonl', ['event' => $eventKey, 'phone' => $phone, 'error' => $e->getMessage()]);
}
tg_queue($config, $notice);  // мгновенно; отправит cron (server/tg-flush.php) — гость Telegram не ждёт

$target = $event['payment_url'] !== '' ? $event['payment_url'] : '/spasibo/';
header('Location: ' . $target, true, 303);
