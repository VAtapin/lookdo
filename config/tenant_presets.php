<?php

return [
    'presets' => [
        'ivanna-brows' => [
            'template' => 'beauty.brows',
            'tenant' => [
                'name' => 'Ivanna Brows',
                'locale' => 'uk',
                'business_description' => 'Догляд, оформлення, корекція, фарбування та ламінування брів і вій у Темпліні — у студії або з виїздом до клієнта.',
            ],
            'profile' => [
                'contact_name' => 'Ivanna Pasteliak',
                'phone' => '+49 174 4812109',
                'street' => 'Ringstr. 12',
                'postal_code' => '17268',
                'city' => 'Templin',
                'primary_color' => '#f47a28',
                'secondary_color' => '#fff3e8',
                'logo_path' => '/brand/tenants/ivanna-brows/logo.webp',
                'enabled_locales' => ['uk', 'ru', 'de'],
                'branding' => [
                    'horizontal_logo_path' => '/brand/tenants/ivanna-brows/logo-horizontal.webp',
                    'hero_image_path' => '/brand/service-brows.webp',
                    'confirmed_at' => 'preset',
                    'services' => 'Корекція, оформлення, фарбування та ламінування брів і вій. Робота у студії та з виїздом до клієнта.',
                    'customers' => 'Клієнти у Темпліні та околицях, яким потрібен професійний догляд за бровами та віями.',
                    'style' => 'Світлий, теплий, кремово-помаранчевий, жіночний та професійний.',
                    'avoid' => 'Чужі логотипи, неприродна ретуш, медичні обіцянки та результати, яких немає у портфоліо.',
                    'service_modes' => ['workshop', 'on_site'],
                    'description_translations' => [
                        'uk' => 'Догляд, оформлення, корекція, фарбування та ламінування брів і вій у Темпліні — у студії або з виїздом до клієнта.',
                        'ru' => 'Уход, оформление, коррекция, окрашивание и ламинирование бровей и ресниц в Темплине — в студии или с выездом к клиенту.',
                        'de' => 'Kosmetische Behandlungen, Gestaltung und Pflege von Augenbrauen und Wimpern in Templin – im Studio oder mobil bei Kundinnen und Kunden.',
                    ],
                    'tagline_translations' => [
                        'uk' => 'Брови, які підкреслюють вашу красу',
                        'ru' => 'Брови, которые подчёркивают вашу красоту',
                        'de' => 'Augenbrauen, die Ihre natürliche Schönheit betonen',
                    ],
                ],
            ],
            'configuration' => [
                'locales' => ['uk', 'ru', 'de'],
                'theme' => ['primary' => '#f47a28', 'secondary' => '#fff3e8', 'surface' => '#fffaf6', 'text' => '#321a12'],
                'hero' => [
                    'eyebrow' => ['uk' => 'IVANNA BROWS', 'ru' => 'IVANNA BROWS', 'de' => 'IVANNA BROWS'],
                    'title' => ['uk' => 'Брови, які підкреслюють вашу красу', 'ru' => 'Брови, которые подчёркивают вашу красоту', 'de' => 'Augenbrauen, die Ihre natürliche Schönheit betonen'],
                    'text' => ['uk' => 'Оберіть послугу та зручний час. Прийом у студії або з виїздом до вас.', 'ru' => 'Выберите услугу и удобное время. Приём в студии или с выездом к вам.', 'de' => 'Wählen Sie eine Leistung und einen passenden Termin – im Studio oder mobil bei Ihnen.'],
                    'action' => ['uk' => 'Записатися', 'ru' => 'Записаться', 'de' => 'Termin buchen'],
                ],
                'trust' => [
                    ['icon' => 'star', 'label' => ['uk' => 'Індивідуальна форма', 'ru' => 'Индивидуальная форма', 'de' => 'Individuelle Form']],
                    ['icon' => 'shield', 'label' => ['uk' => 'Дбайлива робота', 'ru' => 'Бережная работа', 'de' => 'Sorgfältige Arbeit']],
                    ['icon' => 'location', 'label' => ['uk' => 'Студія або виїзд', 'ru' => 'Студия или выезд', 'de' => 'Studio oder mobil']],
                    ['icon' => 'clock', 'label' => ['uk' => 'Онлайн-запис', 'ru' => 'Онлайн-запись', 'de' => 'Online-Termin']],
                ],
                'starter_services' => [
                    ['duration' => 30, 'image' => '/brand/service-brows.webp', 'name' => ['uk' => 'Корекція та форма брів', 'ru' => 'Коррекция и форма бровей', 'de' => 'Augenbrauenkorrektur und Form'], 'description' => ['uk' => 'Індивідуальна форма та охайний контур', 'ru' => 'Индивидуальная форма и аккуратный контур', 'de' => 'Individuelle Form und saubere Kontur']],
                    ['duration' => 60, 'image' => '/brand/service-brows.webp', 'name' => ['uk' => 'Фарбування та корекція', 'ru' => 'Окрашивание и коррекция', 'de' => 'Färben und Korrektur'], 'description' => ['uk' => 'Влучний відтінок, форма та догляд', 'ru' => 'Подходящий оттенок, форма и уход', 'de' => 'Passender Farbton, Form und Pflege']],
                    ['duration' => 75, 'image' => '/brand/service-brows.webp', 'name' => ['uk' => 'Ламінування брів', 'ru' => 'Ламинирование бровей', 'de' => 'Brow-Laminierung'], 'description' => ['uk' => 'Довготривале укладання та догляд', 'ru' => 'Долговременная укладка и уход', 'de' => 'Lang anhaltendes Styling und Pflege']],
                    ['duration' => 35, 'image' => '/brand/service-brows.webp', 'name' => ['uk' => 'Фарбування вій', 'ru' => 'Окрашивание ресниц', 'de' => 'Wimpern färben'], 'description' => ['uk' => 'Виразний погляд без щоденної туші', 'ru' => 'Выразительный взгляд без ежедневной туши', 'de' => 'Ausdrucksvoller Blick ohne tägliche Mascara']],
                    ['duration' => 90, 'image' => '/brand/service-brows.webp', 'name' => ['uk' => 'Комплекс для брів і вій', 'ru' => 'Комплекс для бровей и ресниц', 'de' => 'Komplettpflege für Brauen und Wimpern'], 'description' => ['uk' => 'Форма, колір і комплексний догляд', 'ru' => 'Форма, цвет и комплексный уход', 'de' => 'Form, Farbe und umfassende Pflege']],
                ],
            ],
        ],
    ],
];
