#!/bin/bash
# Выкладка сайта и бота на хостинг Timeweb. Запуск из любой папки: bash server/deploy.sh
# Токены читаются из локальных secrets/ и попадают только в ~/kutyurnaya-config.php (600).
# Конфиг на сервере каждый раз собирается заново из server/config.example.php: мероприятия правим там.
# Всё сначала кладётся в ~/kutyurnaya-staging и проверяется php -l; на живые места файлы встают только после проверки.
set -e
S="$(cd "$(dirname "$0")/.." && pwd)"
SEC=/Users/xenitch/patterns/Кутюрная/secrets
# Все ssh/scp скрипта идут через ОДНО соединение (ControlMaster): защита Timeweb блокирует IP
# за десятки SSH-подключений за пару минут (40 за 3 минуты — уже бан).
K="-o BatchMode=yes -o ConnectTimeout=20 -o IdentitiesOnly=yes -i $HOME/.ssh/kutyurnaya_timeweb -o ControlMaster=auto -o ControlPath=$HOME/.ssh/cm-kutyurnaya-%C -o ControlPersist=120"
H=ct757322@vh460.timeweb.ru
umask 077
AMO=$(grep -oE 'eyJ[A-Za-z0-9._-]+' "$SEC/amocrm-token.md" | head -1)
TG=$(grep -oE '[0-9]{6,12}:[A-Za-z0-9_-]{30,}' "$SEC/telegram-bot-token.md" | head -1)
[ -n "$AMO" ] && [ -n "$TG" ] || { echo "СТОП: не найден токен amoCRM или бота"; exit 1; }
TMP=$(mktemp)
trap 'rm -f "$TMP"; ssh $K -O exit $H 2>/dev/null' EXIT   # временный конфиг с токенами удаляется при любом исходе
sed -e "s|'ДОЛГОСРОЧНЫЙ_ТОКЕН'|'$AMO'|" -e "s|'ТОКЕН_БОТА'|'$TG'|" "$S/server/config.example.php" > "$TMP"
ssh $K $H true || { echo "СТОП: хостинг не отвечает по SSH — ничего не выложено"; exit 1; }

ssh $K $H 'umask 077; rm -rf ~/kutyurnaya-staging && mkdir -p ~/kutyurnaya-staging/app ~/kutyurnaya-staging/site/api ~/kutyurnaya-staging/site/privacy ~/kutyurnaya-staging/site/soglasie ~/kutyurnaya-staging/site/soglasie-rassylka ~/kutyurnaya-staging/site/spasibo ~/kutyurnaya-staging/site/registraciya'
scp $K -q "$TMP" $H:kutyurnaya-staging/kutyurnaya-config.php
scp $K -q "$S/server/lib.php" "$S/server/daily-report.php" "$S/server/tg-flush.php" $H:kutyurnaya-staging/app/
scp $K -q "$S/api/register.php" $H:kutyurnaya-staging/site/api/
scp $K -q "$S/index.html" "$S/style.css" $H:kutyurnaya-staging/site/
for d in privacy soglasie soglasie-rassylka spasibo registraciya; do scp $K -q "$S/$d/index.html" $H:kutyurnaya-staging/site/$d/; done

ssh $K $H 'set -e; cd ~/kutyurnaya-staging
  for f in kutyurnaya-config.php app/lib.php app/daily-report.php app/tg-flush.php site/api/register.php; do php -l $f >/dev/null || { echo "СТОП: ошибка синтаксиса в $f — живой сайт не тронут"; exit 1; }; done
  chmod 600 kutyurnaya-config.php; chmod 644 app/*.php site/api/register.php site/*.html site/*.css site/*/index.html
  mkdir -p ~/kutyurnaya-app ~/public_html/api ~/public_html/privacy ~/public_html/soglasie ~/public_html/soglasie-rassylka ~/public_html/spasibo ~/public_html/registraciya
  mv -f kutyurnaya-config.php ~/kutyurnaya-config.php
  mv -f app/lib.php app/daily-report.php app/tg-flush.php ~/kutyurnaya-app/
  mv -f site/api/register.php ~/public_html/api/register.php
  mv -f site/index.html site/style.css ~/public_html/
  for d in privacy soglasie soglasie-rassylka spasibo registraciya; do mv -f site/$d/index.html ~/public_html/$d/index.html; done
  cd ~ && rm -rf ~/kutyurnaya-staging
  echo "синтаксис: ок"'
echo "ВЫЛОЖЕНО"
