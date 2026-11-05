#!/bin/bash
# Выкладка сайта и бота на хостинг Timeweb. Запуск из любой папки: bash server/deploy.sh
# Токены читаются из локальных secrets/ и попадают только в ~/kutyurnaya-config.php (600).
set -e
S="$(cd "$(dirname "$0")/.." && pwd)"
SEC=/Users/xenitch/patterns/Кутюрная/secrets
K="-o BatchMode=yes -o ConnectTimeout=20 -o IdentitiesOnly=yes -i $HOME/.ssh/kutyurnaya_timeweb"
H=ct757322@vh460.timeweb.ru
umask 077
AMO=$(grep -oE 'eyJ[A-Za-z0-9._-]+' "$SEC/amocrm-token.md" | head -1)
TG=$(grep -oE '[0-9]{6,12}:[A-Za-z0-9_-]{30,}' "$SEC/telegram-bot-token.md" | head -1)
[ -n "$AMO" ] && [ -n "$TG" ] || { echo "СТОП: не найден токен amoCRM или бота"; exit 1; }
TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT   # временный конфиг с токенами удаляется при любом исходе
sed -e "s|'ДОЛГОСРОЧНЫЙ_ТОКЕН'|'$AMO'|" -e "s|'ТОКЕН_БОТА'|'$TG'|" "$S/server/config.example.php" > "$TMP"
ssh $K $H true || { echo "СТОП: хостинг не отвечает по SSH — ничего не выложено"; exit 1; }
ssh $K $H 'mkdir -p ~/kutyurnaya-app ~/public_html/api ~/public_html/privacy ~/public_html/soglasie ~/public_html/soglasie-rassylka ~/public_html/spasibo'
scp $K -q "$TMP" $H:kutyurnaya-config.php
scp $K -q "$S/server/lib.php" "$S/server/daily-report.php" $H:kutyurnaya-app/
scp $K -q "$S/api/register.php" $H:public_html/api/
scp $K -q "$S/index.html" "$S/style.css" $H:public_html/
for d in privacy soglasie soglasie-rassylka spasibo; do scp $K -q "$S/$d/index.html" $H:public_html/$d/; done
ssh $K $H 'chmod 600 ~/kutyurnaya-config.php; for f in ~/kutyurnaya-config.php ~/kutyurnaya-app/lib.php ~/kutyurnaya-app/daily-report.php ~/public_html/api/register.php; do php -l $f | head -1; done'
echo "ВЫЛОЖЕНО"
