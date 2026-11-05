<?php
// Тесты общих функций. Запуск: bash server/tests/run-remote.sh
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

$failed = 0;
function check(string $name, bool $ok): void {
    global $failed;
    echo ($ok ? 'ok    ' : 'FAIL  ') . $name . "\n";
    if (!$ok) { $failed++; }
}

$logDir = sys_get_temp_dir() . '/kut-test-' . getmypid();
$cfg = [
    'amo_subdomain' => 'kutyurnaya', 'pipeline_id' => 10976106, 'log_dir' => $logDir,
    'telegram_token' => '123:SECRET-TOKEN', 'telegram_chat_id' => -1003787864393, 'telegram_thread_id' => 5789,
];

// --- tg_send ---
$sent = null;
$okPost = function (string $url, array $fields) use (&$sent): array {
    $sent = ['url' => $url, 'fields' => $fields];
    return ['code' => 200, 'body' => '{"ok":true,"result":{}}', 'error' => ''];
};
check('tg_send: успех → true', tg_send($cfg, 'привет', $okPost) === true);
check('tg_send: адрес sendMessage с токеном', $sent['url'] === 'https://api.telegram.org/bot123:SECRET-TOKEN/sendMessage');
check('tg_send: чат', $sent['fields']['chat_id'] === '-1003787864393');
check('tg_send: ветка «Продажи»', $sent['fields']['message_thread_id'] === '5789');
check('tg_send: текст', $sent['fields']['text'] === 'привет');
check('tg_send: без превью ссылок', $sent['fields']['disable_web_page_preview'] === 'true');

$cfgNoThread = $cfg; unset($cfgNoThread['telegram_thread_id']);
tg_send($cfgNoThread, 'x', $okPost);
check('tg_send: без ветки — поле не передаётся', !isset($sent['fields']['message_thread_id']));

$called = false;
$cfgNoToken = $cfg; $cfgNoToken['telegram_token'] = '';
$r = tg_send($cfgNoToken, 'x', function () use (&$called) { $called = true; return ['code' => 200, 'body' => '', 'error' => '']; });
check('tg_send: без токена — ничего не шлёт и false', $r === false && $called === false);

$downPost = fn(string $url, array $fields): array =>
    ['code' => 0, 'body' => '', 'error' => "Failed to connect to api.telegram.org: timeout ($url)"];
check('tg_send: Telegram недоступен → false без исключения', tg_send($cfg, 'x', $downPost) === false);
$badPost = fn(string $url, array $fields): array =>
    ['code' => 400, 'body' => '{"ok":false,"description":"Bad Request: message thread not found"}', 'error' => ''];
check('tg_send: ответ с ошибкой → false', tg_send($cfg, 'x', $badPost) === false);
$log = (string) @file_get_contents("$logDir/tg-errors.jsonl");
check('tg_send: ошибки пишутся в tg-errors.jsonl', substr_count($log, "\n") === 2 && str_contains($log, 'thread not found'));
check('tg_send: токена нет в журнале', !str_contains($log, 'SECRET-TOKEN'));

// --- тексты ---
check('msg_new_lead: новая заявка', msg_new_lead($cfg, 'История модных показов · 22 ноября', 31642077, false)
    === "🆕 Новая заявка · История модных показов · 22 ноября\nhttps://kutyurnaya.amocrm.ru/leads/detail/31642077");
check('msg_new_lead: контакт уже был', msg_new_lead($cfg, 'Т', 5, true)
    === "🆕 Новая заявка · Т\nhttps://kutyurnaya.amocrm.ru/leads/detail/5\nконтакт уже был в базе");
check('msg_new_lead: нет id сделки — без ссылки', msg_new_lead($cfg, 'Т', null, false) === '🆕 Новая заявка · Т');
check('MSG_AMO_FAILED', MSG_AMO_FAILED === '⚠️ Заявка с сайта не попала в amoCRM, копия сохранена на хостинге. Напишите Claude, чтобы перенести её вручную.');

// --- отчёт ---
$events = [
    'a' => ['title' => 'А', 'open' => true,  'date' => '2026-11-22'],
    'b' => ['title' => 'Б', 'open' => false, 'date' => '2026-11-30'],
    'c' => ['title' => 'В', 'open' => true],
    'd' => ['title' => 'Г', 'open' => true,  'date' => '2026-12-05'],
];
check('report_events: до лекции', array_keys(report_events($events, '2026-11-05')) === ['a', 'd']);
check('report_events: в день лекции ещё приходит', array_keys(report_events($events, '2026-11-22')) === ['a', 'd']);
check('report_events: на следующий день — нет', array_keys(report_events($events, '2026-11-23')) === ['d']);
check('report_events: без date и закрытые не берутся', report_events(['c' => $events['c'], 'b' => $events['b']], '2026-11-01') === []);

$calls = [];
$fakeAmo = function (array $c, string $m, string $path) use (&$calls): array {
    $calls[] = $path;
    parse_str((string) parse_url($path, PHP_URL_QUERY), $q);
    $sizes = [1 => 250, 2 => 250, 3 => 7];
    $n = $sizes[(int) $q['page']] ?? 0;
    return $n ? ['_embedded' => ['leads' => array_fill(0, $n, ['id' => 1])]] : [];
};
check('amo_count_leads: обходит все страницы', amo_count_leads($cfg, 86289814, $fakeAmo) === 507);
check('amo_count_leads: три запроса', count($calls) === 3);
check('amo_count_leads: фильтр по воронке и статусу',
    str_contains($calls[0], 'filter[statuses][0][pipeline_id]=10976106') && str_contains($calls[0], 'filter[statuses][0][status_id]=86289814')
    && str_contains($calls[0], 'limit=250') && str_contains($calls[0], 'page=1'));
check('amo_count_leads: пустой статус (204) → 0', amo_count_leads($cfg, 1, fn() => []) === 0);

check('msg_report', msg_report('История модных показов · 22 ноября', 6, 12, 4)
    === "☀️ История модных показов · 22 ноября\nЗанято мест: 18\n· оплатили, билет ещё не отправлен: 6\n· билет отправлен: 12\nЖдут оплаты: 4");

array_map('unlink', glob("$logDir/*") ?: []);
@rmdir($logDir);
echo $failed ? "\nПРОВАЛЕНО: $failed\n" : "\nВСЕ ТЕСТЫ ПРОШЛИ\n";
exit($failed ? 1 : 0);
