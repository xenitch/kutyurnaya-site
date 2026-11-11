<?php
// Досылает очередь сообщений в чат организаторов. Cron в панели Timeweb: */5 * * * *
declare(strict_types=1);
require dirname(__DIR__) . '/public_html/.private/lib.php';
$config = require dirname(__DIR__) . '/public_html/.private/kutyurnaya-config.php';
$r = tg_flush($config);
if (in_array('-v', $argv, true)) { echo "отправлено: {$r['sent']}, осталось: {$r['left']}\n"; }
