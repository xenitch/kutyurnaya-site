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
- Скрипт проверяет поля, сохраняет копию заявки в `~/kutyurnaya-data/registrations.jsonl`, ищет контакт по почте,
  создаёт сделку с контактом в воронке «Покупка с сайта», этап «заявка с сайта», и перенаправляет на `payment_url`
  мероприятия (если пусто — на `/spasibo/`). Сбой amoCRM не блокирует человека: ошибка пишется в `amo-errors.jsonl`.
- Конфиг с токеном и списком мероприятий — только на хостинге: `~/kutyurnaya-config.php` (права 600),
  образец в `server/config.example.php`. Открыть регистрацию = добавить мероприятие в `events` с `open => true`.
- Токен amoCRM: долгосрочный, интеграция «Сайт kutyurnaya.ru» (внешняя). Перевыпустить до окончания срока.
- `/test-form/` — тестовая страница формы (noindex), `/spasibo/` — страница после заявки.

Загрузка скрипта и страниц:

    scp api/register.php ct757322@vh460.timeweb.ru:public_html/api/
    scp test-form/index.html ct757322@vh460.timeweb.ru:public_html/test-form/
    scp spasibo/index.html ct757322@vh460.timeweb.ru:public_html/spasibo/

## Бот организаторов (@kutyurnaya_bot)

- Новая заявка с сайта → сообщение в ветку «Продажи» чата организаторов (без личных данных, ссылка на сделку).
- Каждый день в 09:00 по Кемерову — отчёт о занятых местах (`server/daily-report.php`, cron в панели Timeweb `0 5 * * *`),
  пока у мероприятия `open = true` и не прошла его `date`.
- Проверка вхолостую: `ssh … "php ~/kutyurnaya-app/daily-report.php --dry-run"`. Ошибки: `~/kutyurnaya-data/tg-errors.jsonl`, `report-errors.jsonl`.
- После лекции сделки переводить в «пришел» или закрывать, иначе они засчитаются на следующую встречу.
- Выкладка всего: `bash server/deploy.sh`; тесты: `bash server/tests/run-remote.sh`.
