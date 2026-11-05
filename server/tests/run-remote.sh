#!/bin/bash
# Тесты запускаются на хостинге: локально PHP нет. Запуск из корня репо: bash server/tests/run-remote.sh
set -e
cd "$(dirname "$0")/../.."
# Все ssh/scp скрипта идут через ОДНО соединение (ControlMaster): защита Timeweb блокирует IP
# за десятки SSH-подключений за пару минут (40 за 3 минуты — уже бан).
K="-o BatchMode=yes -o ConnectTimeout=20 -o IdentitiesOnly=yes -i $HOME/.ssh/kutyurnaya_timeweb -o ControlMaster=auto -o ControlPath=$HOME/.ssh/cm-kutyurnaya-%C -o ControlPersist=120"
H=ct757322@vh460.timeweb.ru
trap 'ssh $K -O exit $H 2>/dev/null' EXIT
# Отдельная папка: живой ~/kutyurnaya-app/lib.php (его подключает форма) тесты не трогают.
ssh $K $H true && ssh $K -O check $H 2>&1 | sed "s/^/соединение: /"
ssh $K $H 'rm -rf ~/kutyurnaya-test && mkdir -p ~/kutyurnaya-test/tests'
scp $K -q server/lib.php $H:kutyurnaya-test/
scp $K -q server/tests/run.php $H:kutyurnaya-test/tests/
ssh $K $H 'php -l ~/kutyurnaya-test/lib.php >/dev/null && php ~/kutyurnaya-test/tests/run.php'
