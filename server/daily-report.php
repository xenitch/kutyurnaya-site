<?php
// Утренний отчёт в чат организаторов: сколько мест занято.
// Cron в панели Timeweb: 0 5 * * *  (05:00 МСК = 09:00 Кемерово)
// --dry-run — напечатать текст и ничего не отправлять. Отправка — через очередь (lib.php: tg_queue/tg_flush).
declare(strict_types=1);
require __DIR__ . '/lib.php';
$config = require dirname(__DIR__) . '/kutyurnaya-config.php';

$dry   = in_array('--dry-run', $argv, true);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Novokuznetsk')))->format('Y-m-d');
$events = report_events($config['events'], $today);
if (!$events) {
    if ($dry) { echo "нет открытых мероприятий на $today — отчёт не нужен\n"; }
    exit(0);
}

try {
    $text = msg_report(
        implode(' + ', array_column($events, 'title')),
        amo_count_leads($config, (int) $config['status_paid']),
        amo_count_leads($config, (int) $config['status_ticket']),
        amo_count_leads($config, (int) $config['status_id']),
    );
} catch (Throwable $e) {
    log_line($config, 'report-errors.jsonl', ['error' => $e->getMessage()]);
    if ($dry) { echo "ОШИБКА amoCRM: см. report-errors.jsonl\n"; exit(1); }
    $text = MSG_REPORT_FAILED;
}

if ($dry) { echo $text, "\n"; exit(0); }
// В очередь и сразу пробуем отправить: до 4 попыток за ~1,5 минуты, остальное дошлёт cron tg-flush.php
tg_queue($config, $text);
for ($i = 0; $i < 4; $i++) {
    if ($i) { sleep(30); }
    if (tg_flush($config)['left'] === 0) { exit(0); }
}
exit(1);
