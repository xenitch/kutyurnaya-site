#!/bin/bash
# Тесты запускаются на хостинге: локально PHP нет. Запуск из корня репо: bash server/tests/run-remote.sh
set -e
cd "$(dirname "$0")/../.."
K="-o BatchMode=yes -o ConnectTimeout=20 -o IdentitiesOnly=yes -i $HOME/.ssh/kutyurnaya_timeweb"
H=ct757322@vh460.timeweb.ru
ssh $K $H 'mkdir -p ~/kutyurnaya-app/tests'
scp $K -q server/lib.php $H:kutyurnaya-app/
scp $K -q server/tests/run.php $H:kutyurnaya-app/tests/
ssh $K $H 'php -l ~/kutyurnaya-app/lib.php >/dev/null && php ~/kutyurnaya-app/tests/run.php'
