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
