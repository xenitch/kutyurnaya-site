<?php
// Пример конфигурации. Настоящий файл лежит на хостинге ВНЕ public_html:
//   ~/kutyurnaya-config.php  (права 600), в репозиторий не коммитится.
return [
    'amo_subdomain'  => 'kutyurnaya',
    'amo_token'      => 'ДОЛГОСРОЧНЫЙ_ТОКЕН',     // amoCRM → амоМаркет → интеграция «Сайт kutyurnaya.ru» → Ключи
    'pipeline_id'    => 10976106,                 // «Покупка с сайта»
    'status_id'      => 86289826,                 // «заявка с сайта»
    'status_paid'    => 86289814,                 // «оплатил»
    'status_ticket'  => 86289818,                 // «отправили билет»
    'amo_field_tickets' => 1065429,              // поле сделки «Билетов»
    'telegram_token'     => 'ТОКЕН_БОТА',         // @kutyurnaya_bot, локально: secrets/telegram-bot-token.md
    'telegram_chat_id'   => -1003787864393,       // чат организаторов
    'telegram_thread_id' => 5789,                 // ветка «Продажи»
    'log_dir'        => __DIR__ . '/kutyurnaya-data',
    // Мероприятия, на которые открыта регистрация. Ключ — значение скрытого поля event в форме.
    'events' => [
        'pokazy-2026-11-22' => [
            'title'   => 'История модных показов · 22 ноября',
            'date'    => '2026-11-22',          // до этого дня включительно — утренний отчёт
            'open'    => true,
            // Цены продублированы в карточках index.html и в скрипте registraciya/index.html — менять во всех трёх местах
            'tariffs' => [
                'ekzemplyar' => ['title' => 'Экземпляр',    'tickets' => 1, 'price' => 2000, 'payment_url' => 'https://tochkaplace.com/ia/5530e5c3-9542-4ccc-be1d-18cec6d0b21d'],
                'para'       => ['title' => 'Парный тираж', 'tickets' => 2, 'price' => 3600, 'payment_url' => 'https://tochkaplace.com/ia/08d13f35-0773-484a-9927-eca8d793b700'],
                'kollekciya' => ['title' => 'Коллекция',    'tickets' => 3, 'price' => 5000, 'payment_url' => 'https://tochkaplace.com/ia/cb14f393-958d-40bb-b411-52701e149d76'],
            ],
        ],
        'test' => [
            'title'   => 'Тестовая регистрация',
            'open'    => false,
            'tariffs' => ['ekzemplyar' => ['title' => 'Экземпляр', 'tickets' => 1, 'price' => 0, 'payment_url' => '']],   // пусто → /spasibo/
        ],
    ],
];
