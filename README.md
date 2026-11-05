# kutyurnaya.ru

Сайт сообщества «Кутюрная» (Кемерово). Сейчас — одностраничная заглушка:
`index.html` + `style.css` + шрифты в `fonts/` + `.htaccess`. Без сборки и зависимостей.

## Где живёт

Виртуальный хостинг Timeweb, сервер `vh460.timeweb.ru` (92.53.96.201), аккаунт `ct757322`,
папка `~/public_html`. DNS домена — на NS Timeweb, сертификат Let's Encrypt и редирект
на HTTPS включены в панели Timeweb. Этот репозиторий — исходник и история правок,
GitHub Pages для домена больше не используется.

## Как обновить текст

1. Поправить `index.html`.
2. Проверить локально: `python3 -m http.server 8899` и открыть http://localhost:8899/.
3. Закоммитить: `git add -A && git commit -m "..." && git push`.
4. Загрузить на хостинг (пароль — от панели Timeweb):

       scp index.html style.css .htaccess ct757322@vh460.timeweb.ru:public_html/
       scp -r fonts ct757322@vh460.timeweb.ru:public_html/

Изменения видны сразу.

## DNS (панель Timeweb → Домены и SSL → kutyurnaya.ru → DNS)

- `@` A → 92.53.96.201, `www` A → 92.53.96.201
- MX `10 mx.yandex.net`, TXT yandex-verification, SPF, DKIM `mail._domainkey` — почта на Яндексе, не трогать

## Регистрация на встречи → amoCRM

- Форма на странице шлёт POST на `/api/register.php` (PHP 8.2 на хостинге).
- Скрипт проверяет поля, сохраняет копию заявки в `~/kutyurnaya-data/registrations.jsonl`, ищет контакт по телефону,
  создаёт сделку с контактом в воронке «Покупка с сайта», этап «заявка с сайта», и перенаправляет на `payment_url`
  мероприятия (если пусто — на `/spasibo/`). Сбой amoCRM не блокирует человека: ошибка пишется в `amo-errors.jsonl`.
- Конфиг с токенами живёт только на хостинге: `~/kutyurnaya-config.php` (права 600). Его каждый раз собирает
  `server/deploy.sh` из `server/config.example.php` + токенов из локальной папки secrets/, поэтому **мероприятия правим
  в `server/config.example.php`** (открыть регистрацию = мероприятие в `events` с `open => true` и `date`), затем
  `bash server/deploy.sh`. Правки прямо на сервере следующая выкладка сотрёт.
- Токен amoCRM: долгосрочный, интеграция «Сайт kutyurnaya.ru» (внешняя). Перевыпустить до окончания срока.
- `/test-form/` — тестовая страница формы (noindex), `/spasibo/` — страница после заявки.

Выкладка — только `bash server/deploy.sh`: форма `api/register.php` подключает `~/kutyurnaya-app/lib.php`, и залитая
отдельно без него перестанет работать. Скрипт выкладывает страницы, форму, бота и конфиг; картинки, шрифты, иконки и
`.htaccess` по-прежнему заливаются вручную через `scp`.

## Бот организаторов (@kutyurnaya_bot)

- Новая заявка с сайта → сообщение в ветку «Продажи» чата организаторов (без личных данных, ссылка на сделку).
- С хостинга Telegram доступен через раз, поэтому все сообщения идут через очередь `~/kutyurnaya-data/tg-outbox.jsonl`:
  форма только кладёт в неё сообщение (гость не ждёт), `server/tg-flush.php` по cron `*/5 * * * *` досылает.
  Сообщения старше суток выбрасываются (отметка в `tg-errors.jsonl`).
- Каждый день в 09:00 по Кемерову — отчёт о занятых местах (`server/daily-report.php`, cron в панели Timeweb `0 5 * * *`),
  пока у мероприятия `open = true` и не прошла его `date`.
- Проверка вхолостую: `ssh … "php ~/kutyurnaya-app/daily-report.php --dry-run"`. Ошибки: `~/kutyurnaya-data/tg-errors.jsonl`, `report-errors.jsonl`.
- После лекции сделки переводить в «пришел» или закрывать, иначе они засчитаются на следующую встречу.
- Выкладка всего: `bash server/deploy.sh`; тесты: `bash server/tests/run-remote.sh`.
