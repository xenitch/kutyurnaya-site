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

// --- очередь сообщений ---
$qDir = "$logDir/q"; $q = $cfg; $q['log_dir'] = $qDir;
$box = "$qDir/tg-outbox.jsonl";
tg_queue($q, 'первое'); tg_queue($q, 'второе');
$lines = file($box, FILE_IGNORE_NEW_LINES);
check('tg_queue: дописывает строку на сообщение', count($lines) === 2 && json_decode($lines[1], true)['text'] === 'второе');

$texts = [];
$rec = function (string $url, array $f) use (&$texts): array { $texts[] = $f['text']; return ['code' => 200, 'body' => '{"ok":true}', 'error' => '']; };
$r = tg_flush($q, $rec);
check('tg_flush: отправляет всё по порядку', $texts === ['первое', 'второе'] && $r === ['sent' => 2, 'left' => 0]);
check('tg_flush: очередь пуста после отправки', !file_exists($box) || trim((string) file_get_contents($box)) === '');

tg_queue($q, 'а'); tg_queue($q, 'б'); tg_queue($q, 'в');
$n = 0;
$flaky = function (string $url, array $f) use (&$n): array {
    $n++;
    return $n === 1 ? ['code' => 200, 'body' => '{"ok":true}', 'error' => ''] : ['code' => 0, 'body' => '', 'error' => 'timeout'];
};
$r = tg_flush($q, $flaky);
check('tg_flush: при сбое Telegram останавливается', $n === 2 && $r === ['sent' => 1, 'left' => 2]);
$queued = fn(): array => array_map(fn($l) => json_decode($l, true)['text'], array_merge(
    @file("$qDir/tg-outbox.processing.jsonl", FILE_IGNORE_NEW_LINES) ?: [], @file($box, FILE_IGNORE_NEW_LINES) ?: []));
$left = $queued();
check('tg_flush: неотправленные остаются в очереди по порядку', $left === ['б', 'в']);
$texts = [];
tg_flush($q, $rec);
check('tg_flush: следующий запуск досылает остаток', $texts === ['б', 'в']);

file_put_contents($box, json_encode(['text' => 'старое', 'queued' => time() - 25 * 3600], JSON_UNESCAPED_UNICODE) . "\n"
    . json_encode(['text' => 'свежее', 'queued' => time()], JSON_UNESCAPED_UNICODE) . "\n");
$texts = [];
$r = tg_flush($q, $rec);
check('tg_flush: сообщения старше суток выбрасываются', $texts === ['свежее'] && $r === ['sent' => 1, 'left' => 0]);

file_put_contents("$qDir/tg-outbox.processing.jsonl", json_encode(['text' => 'после сбоя', 'queued' => time()], JSON_UNESCAPED_UNICODE) . "\n");
tg_queue($q, 'новое');
$texts = [];
tg_flush($q, $rec);
check('tg_flush: подбирает файл, оставшийся от прерванного запуска', $texts === ['после сбоя', 'новое']);

$lock = fopen("$qDir/tg-outbox.lock", 'c'); flock($lock, LOCK_EX);
tg_queue($q, 'ждёт');
$called = false;
$r = tg_flush($q, function () use (&$called) { $called = true; return ['code' => 200, 'body' => '{"ok":true}', 'error' => '']; });
check('tg_flush: если уже идёт другой запуск — сразу выходит', $called === false && $r === ['sent' => 0, 'left' => -1]);
flock($lock, LOCK_UN); fclose($lock);

array_map('unlink', glob("$qDir/*") ?: []); @rmdir($qDir);
array_map('unlink', glob("$logDir/*") ?: []);
@rmdir($logDir);
echo $failed ? "\nПРОВАЛЕНО: $failed\n" : "\nВСЕ ТЕСТЫ ПРОШЛИ\n";
exit($failed ? 1 : 0);
