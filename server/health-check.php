<?php
// Раз в час: работает ли регистрация на сайте. Тревога в чат организаторов — только при смене состояния.
// Cron в панели Timeweb: 0 * * * *   (путь от домашней папки: /kutyurnaya-app/health-check.php). -v — печать результата.
declare(strict_types=1);
require dirname(__DIR__) . '/public_html/.private/lib.php';
$config = require dirname(__DIR__) . '/public_html/.private/kutyurnaya-config.php';

function fetch(string $url, ?array $post = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_USERAGENT => 'kutyurnaya-health-check']);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    curl_exec($ch);
    $r = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'location' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
    curl_close($ch);
    return $r;
}

function check_site(): array {
    $base = 'https://kutyurnaya.ru';
    return health_problems([
        'home' => fetch("$base/")['code'],
        'reg'  => fetch("$base/registraciya/?tarif=ekzemplyar")['code'],
        // антиспам-поле заполнено: форма загружает настройки и функции, но сделку не создаёт
        'form' => fetch("$base/api/register.php", ['website' => 'health-check']),
    ]);
}

$problems = check_site();
if ($problems) { sleep(60); $problems = check_site(); }   // не тревожить из-за разового сбоя

$history = data_lines(data_file($config, 'health.jsonl'));
$last = $history ? json_decode((string) end($history), true) : null;
$wasOk = is_array($last) && isset($last['ok']) ? (bool) $last['ok'] : null;
log_line($config, 'health.jsonl', ['ok' => !$problems, 'problems' => $problems]);

$msg = health_message($wasOk, $problems);
if ($msg !== null) { tg_queue($config, $msg); tg_flush($config); }
if (in_array('-v', $argv, true)) { echo $problems ? implode("\n", $problems) : 'всё работает', "\n", $msg ? "в чат: $msg\n" : ''; }
