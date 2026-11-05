#!/bin/bash
# Тесты запускаются на хостинге: локально PHP нет. Запуск из корня репо: bash server/tests/run-remote.sh
set -e
cd "$(dirname "$0")/../.."
K="-o BatchMode=yes -o ConnectTimeout=20 -o IdentitiesOnly=yes -i $HOME/.ssh/kutyurnaya_timeweb"
H=ct757322@vh460.timeweb.ru
# Отдельная папка: живой ~/kutyurnaya-app/lib.php (его подключает форма) тесты не трогают.
ssh $K $H 'rm -rf ~/kutyurnaya-test && mkdir -p ~/kutyurnaya-test/tests'
scp $K -q server/lib.php $H:kutyurnaya-test/
scp $K -q server/tests/run.php $H:kutyurnaya-test/tests/
ssh $K $H 'php -l ~/kutyurnaya-test/lib.php >/dev/null && php ~/kutyurnaya-test/tests/run.php'
