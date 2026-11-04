<?php
// Пример конфигурации. Настоящий файл лежит на хостинге ВНЕ public_html:
//   ~/kutyurnaya-config.php  (права 600), в репозиторий не коммитится.
return [
    'amo_subdomain'  => 'kutyurnaya',
    'amo_token'      => 'ДОЛГОСРОЧНЫЙ_ТОКЕН',     // amoCRM → амоМаркет → интеграция «Сайт kutyurnaya.ru» → Ключи
    'pipeline_id'    => 10976106,                 // «Покупка с сайта»
    'status_id'      => 86289826,                 // «заявка с сайта»
    'log_dir'        => __DIR__ . '/kutyurnaya-data',
    // Мероприятия, на которые открыта регистрация. Ключ — значение скрытого поля event в форме.
    'events' => [
        'test' => [
            'title'       => 'Тестовая регистрация',
            'price'       => 0,
            'payment_url' => '',          // пусто → после заявки ведём на /spasibo/
            'open'        => true,
        ],
    ],
];
