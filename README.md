# kutyurnaya.ru

Сайт сообщества «Кутюрная» (Кемерово). Сейчас — одностраничная заглушка:
`index.html` + `style.css` + шрифты в `fonts/`. Без сборки и зависимостей.

Публикуется через GitHub Pages из корня ветки `main`; домен привязан файлом `CNAME`.

## Как обновить текст

1. Поправить `index.html`.
2. Проверить локально: `python3 -m http.server 8899` и открыть http://localhost:8899/.
3. `git add -A && git commit -m "..." && git push` — через минуту-две изменения на https://kutyurnaya.ru.

## DNS (reg.ru)

- `@` A → 185.199.108.153, 185.199.109.153, 185.199.110.153, 185.199.111.153
- `www` CNAME → `xenitch.github.io.`
