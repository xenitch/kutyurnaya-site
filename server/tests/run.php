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
$logLines = data_lines("$logDir/tg-errors.jsonl.php");
$log = implode("\n", $logLines);
check('tg_send: ошибки пишутся в tg-errors.jsonl.php', count($logLines) === 2 && str_contains($log, 'thread not found'));
check('данные: первая строка файла — заглушка от чтения из браузера', str_starts_with((string) file_get_contents("$logDir/tg-errors.jsonl.php"), DATA_GUARD));
check('tg_send: токена нет в журнале', !str_contains($log, 'SECRET-TOKEN'));

// --- тексты ---
$para = ['title' => 'Парный тираж', 'tickets' => 2];
check('msg_new_lead: с тарифом', msg_new_lead($cfg, 'История модных показов · 22 ноября', $para, 31642077, false)
    === "🆕 Новая заявка · История модных показов · 22 ноября\nПарный тираж · 2 билета\nhttps://kutyurnaya.amocrm.ru/leads/detail/31642077");
check('msg_new_lead: контакт уже был', msg_new_lead($cfg, 'Т', $para, 5, true)
    === "🆕 Новая заявка · Т\nПарный тираж · 2 билета\nhttps://kutyurnaya.amocrm.ru/leads/detail/5\nконтакт уже был в базе");
check('msg_new_lead: без тарифа и id сделки', msg_new_lead($cfg, 'Т', null, null, false) === '🆕 Новая заявка · Т');
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

$cfgT = $cfg + ['amo_field_tickets' => 900];
$lead = fn(?int $t): array => ['id' => 1, 'custom_fields_values' => $t === null ? null : [['field_id' => 900, 'values' => [['value' => $t]]]]];
check('lead_tickets: значение поля', lead_tickets($lead(3), 900) === 3);
check('lead_tickets: поле пустое → 1', lead_tickets($lead(null), 900) === 1);
check('lead_tickets: 0 → 1', lead_tickets($lead(0), 900) === 1);
check('lead_tickets: чужое поле → 1', lead_tickets($lead(2), 901) === 1);

$calls = [];
$fakeAmo = function (array $c, string $m, string $path) use (&$calls, $lead): array {
    $calls[] = $path;
    parse_str((string) parse_url($path, PHP_URL_QUERY), $q);
    $page = (int) $q['page'];
    if ($page <= 2) { return ['_embedded' => ['leads' => array_fill(0, 250, $lead(null))]]; }
    if ($page === 3) { return ['_embedded' => ['leads' => [$lead(2), $lead(3), $lead(null)]]]; }
    return [];
};
check('amo_count_tickets: обходит все страницы', amo_count_tickets($cfgT, 86289814, $fakeAmo) === ['leads' => 503, 'tickets' => 506, 'revenue' => 0, 'free' => 506]);
check('amo_count_tickets: три запроса', count($calls) === 3);
check('amo_count_tickets: фильтр по воронке и статусу',
    str_contains($calls[0], 'filter[statuses][0][pipeline_id]=10976106') && str_contains($calls[0], 'filter[statuses][0][status_id]=86289814')
    && str_contains($calls[0], 'limit=250') && str_contains($calls[0], 'page=1'));
check('amo_count_tickets: пустой статус (204) → 0', amo_count_tickets($cfgT, 1, fn() => []) === ['leads' => 0, 'tickets' => 0, 'revenue' => 0, 'free' => 0]);
check('amo_count_tickets: без поля в конфиге — по 1 билету', amo_count_tickets($cfg, 1, fn() => ['_embedded' => ['leads' => [$lead(3)]]]) === ['leads' => 1, 'tickets' => 1, 'revenue' => 0, 'free' => 1]);

$priced = ['_embedded' => ['leads' => [$lead(2) + ['price' => 3600], $lead(null) + ['price' => 0], $lead(3) + ['price' => 5000]]]];
check('amo_count_tickets: выручка и бесплатные места', amo_count_tickets($cfgT, 1, fn() => $priced) === ['leads' => 3, 'tickets' => 6, 'revenue' => 8600, 'free' => 1]);
check('sum_counts: складывает статусы', sum_counts(['leads' => 1, 'tickets' => 2, 'revenue' => 3600, 'free' => 0], ['leads' => 2, 'tickets' => 3, 'revenue' => 2000, 'free' => 2])
    === ['leads' => 3, 'tickets' => 5, 'revenue' => 5600, 'free' => 2]);
check('msg_report: с бесплатными местами', msg_report('История модных показов · 22 ноября',
        ['leads' => 3, 'tickets' => 5, 'revenue' => 7200, 'free' => 1], ['leads' => 2, 'tickets' => 2, 'revenue' => 0, 'free' => 0])
    === "☀️ История модных показов · 22 ноября\nЗанято мест: 5 (заявок: 3)\nВыручка: 7\u{00A0}200 ₽ · бесплатных мест: 1\nЖдут оплаты: 2");
check('msg_report: без бесплатных', msg_report('Т', ['leads' => 1, 'tickets' => 1, 'revenue' => 2000, 'free' => 0], ['leads' => 0, 'tickets' => 0, 'revenue' => 0, 'free' => 0])
    === "☀️ Т\nЗанято мест: 1 (заявок: 1)\nВыручка: 2\u{00A0}000 ₽\nЖдут оплаты: 0");

// --- проверка сайта ---
$fine = ['home' => 200, 'reg' => 200, 'form' => ['code' => 303, 'location' => 'https://kutyurnaya.ru/spasibo/']];
check('health_problems: всё в порядке', health_problems($fine) === []);
check('health_problems: форма отвечает 500', health_problems(['form' => ['code' => 500, 'location' => '']] + $fine)
    === ['форма: ответ 500 вместо перехода на /spasibo/']);
check('health_problems: сайт не отвечает', health_problems(['home' => 0, 'reg' => 0, 'form' => ['code' => 0, 'location' => '']])
    === ['главная: нет ответа', 'страница регистрации: нет ответа', 'форма: нет ответа']);
check('health_message: сломалось → тревога', health_message(true, ['форма: ответ 500 вместо перехода на /spasibo/'])
    === "🚨 Регистрация на сайте kutyurnaya.ru не работает\n· форма: ответ 500 вместо перехода на /spasibo/\nНапишите Claude.");
check('health_message: первая проверка и сразу сломано → тревога', health_message(null, ['главная: нет ответа']) !== null);
check('health_message: по-прежнему сломано → молчим', health_message(false, ['главная: нет ответа']) === null);
check('health_message: починилось → сообщение', health_message(false, []) === '✅ Регистрация на сайте снова работает.');
check('health_message: всё хорошо и было хорошо → молчим', health_message(true, []) === null && health_message(null, []) === null);

// --- тарифы ---
foreach ([1 => '1 билет', 2 => '2 билета', 4 => '4 билета', 5 => '5 билетов', 11 => '11 билетов', 12 => '12 билетов', 21 => '21 билет', 22 => '22 билета', 25 => '25 билетов'] as $n => $w) {
    check("tickets_word($n)", tickets_word($n) === $w);
}
$ev = ['tariffs' => [
    'ekzemplyar' => ['title' => 'Экземпляр', 'tickets' => 1, 'price' => 2000, 'payment_url' => 'u1'],
    'para'       => ['title' => 'Парный тираж', 'tickets' => 2, 'price' => 3600, 'payment_url' => 'u2'],
]];
check('pick_tariff: по ключу', pick_tariff($ev, 'para') === ['title' => 'Парный тираж', 'tickets' => 2, 'price' => 3600, 'payment_url' => 'u2', 'key' => 'para']);
check('pick_tariff: нет ключа → Экземпляр', (pick_tariff($ev, null)['key'] ?? null) === 'ekzemplyar');
check('pick_tariff: пустой ключ (скрипт страницы не сработал) → null', pick_tariff($ev, '') === null);
check('pick_tariff: неизвестный → null', pick_tariff($ev, 'vip') === null);
check('pick_tariff: у мероприятия нет тарифов → null', pick_tariff([], 'para') === null);

// --- очередь сообщений ---
$qDir = "$logDir/q"; $q = $cfg; $q['log_dir'] = $qDir;
$box = "$qDir/tg-outbox.jsonl.php";
tg_queue($q, 'первое'); tg_queue($q, 'второе');
$lines = data_lines($box);
check('tg_queue: дописывает строку на сообщение', count($lines) === 2 && json_decode($lines[1], true)['text'] === 'второе');

$texts = [];
$rec = function (string $url, array $f) use (&$texts): array { $texts[] = $f['text']; return ['code' => 200, 'body' => '{"ok":true}', 'error' => '']; };
$r = tg_flush($q, $rec);
check('tg_flush: отправляет всё по порядку', $texts === ['первое', 'второе'] && $r === ['sent' => 2, 'left' => 0]);
check('tg_flush: очередь пуста после отправки', data_lines($box) === []);

tg_queue($q, 'а'); tg_queue($q, 'б'); tg_queue($q, 'в');
$n = 0;
$flaky = function (string $url, array $f) use (&$n): array {
    $n++;
    return $n === 1 ? ['code' => 200, 'body' => '{"ok":true}', 'error' => ''] : ['code' => 0, 'body' => '', 'error' => 'timeout'];
};
$r = tg_flush($q, $flaky);
check('tg_flush: при сбое Telegram останавливается', $n === 2 && $r === ['sent' => 1, 'left' => 2]);
$queued = fn(): array => array_map(fn($l) => json_decode($l, true)['text'], array_merge(
    data_lines("$qDir/tg-outbox.processing.jsonl.php"), data_lines($box)));
$left = $queued();
check('tg_flush: неотправленные остаются в очереди по порядку', $left === ['б', 'в']);
$texts = [];
tg_flush($q, $rec);
check('tg_flush: следующий запуск досылает остаток', $texts === ['б', 'в']);

file_put_contents($box, DATA_GUARD . json_encode(['text' => 'старое', 'queued' => time() - 25 * 3600], JSON_UNESCAPED_UNICODE) . "\n"
    . json_encode(['text' => 'свежее', 'queued' => time()], JSON_UNESCAPED_UNICODE) . "\n");
$texts = [];
$r = tg_flush($q, $rec);
check('tg_flush: сообщения старше суток выбрасываются', $texts === ['свежее'] && $r === ['sent' => 1, 'left' => 0]);

file_put_contents("$qDir/tg-outbox.processing.jsonl.php", DATA_GUARD . json_encode(['text' => 'после сбоя', 'queued' => time()], JSON_UNESCAPED_UNICODE) . "\n");
tg_queue($q, 'новое');
$texts = [];
tg_flush($q, $rec);
check('tg_flush: подбирает файл, оставшийся от прерванного запуска', $texts === ['после сбоя', 'новое']);

$lock = fopen("$qDir/tg-outbox.lock.php", 'c'); flock($lock, LOCK_EX);
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
