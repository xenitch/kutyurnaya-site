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
        'pokazy-2026-11-22' => [
            'title'       => 'История модных показов · 22 ноября',
            'price'       => 2000,
            'payment_url' => 'https://tochkaplace.com/ia/5530e5c3-9542-4ccc-be1d-18cec6d0b21d', // интернет-эквайринг Точки
            'open'        => true,
        ],
        'test' => [
            'title'       => 'Тестовая регистрация',
            'price'       => 0,
            'payment_url' => '',          // пусто → после заявки ведём на /spasibo/
            'open'        => false,
        ],
    ],
];
