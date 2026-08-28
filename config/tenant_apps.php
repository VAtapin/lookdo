<?php

return [
    'templates' => [
        'automotive.steering-wheel-upholstery' => [
            'engine' => 'request',
            'layout' => 'steering',
            'navigation' => ['home', 'works', 'action', 'activity'],
            'hero' => [
                'image' => '/brand/steering-wheel-placeholder.svg',
                'eyebrow' => ['de' => 'LENKRADBEZUG', 'en' => 'STEERING WHEEL UPHOLSTERY', 'ru' => 'ПЕРЕТЯЖКА РУЛЕЙ', 'uk' => 'ПЕРЕТЯЖКА КЕРМА'],
                'title' => ['de' => 'Ihr Lenkrad kann wieder wie neu aussehen', 'en' => 'Your steering wheel can look new again', 'ru' => 'Ваш руль снова может выглядеть как новый', 'uk' => 'Ваше кермо знову може виглядати як нове'],
                'text' => ['de' => 'Zeigen Sie den Zustand mit Fotos. Der Meister prüft alles und antwortet persönlich.', 'en' => 'Show its condition with photos. The specialist will review everything and reply personally.', 'ru' => 'Покажите состояние на фото. Мастер всё изучит и ответит лично.', 'uk' => 'Покажіть стан на фото. Майстер усе перегляне й відповість особисто.'],
                'action' => ['de' => 'Lenkrad bewerten', 'en' => 'Assess my steering wheel', 'ru' => 'Оценить мой руль', 'uk' => 'Оцінити моє кермо'],
            ],
            'trust' => [
                ['icon' => 'shield', 'label' => ['de' => 'Qualitätsmaterialien', 'en' => 'Quality materials', 'ru' => 'Качественные материалы', 'uk' => 'Якісні матеріали']],
                ['icon' => 'tool', 'label' => ['de' => 'Handarbeit', 'en' => 'Handmade work', 'ru' => 'Ручная работа', 'uk' => 'Ручна робота']],
                ['icon' => 'star', 'label' => ['de' => 'Garantie', 'en' => 'Warranty', 'ru' => 'Гарантия', 'uk' => 'Гарантія']],
                ['icon' => 'clock', 'label' => ['de' => 'Klare Termine', 'en' => 'Clear timing', 'ru' => 'Понятные сроки', 'uk' => 'Зрозумілі строки']],
            ],
            'starter_portfolio' => [],
        ],
        'repair-finishing-installation.door-installation' => [
            'engine' => 'request',
            'layout' => 'doors',
            'navigation' => ['home', 'works', 'action', 'activity', 'profile'],
            'hero' => [
                'image' => '/brand/service-door.webp',
                'eyebrow' => ['de' => 'TÜRMONTAGE', 'en' => 'DOOR INSTALLATION', 'ru' => 'УСТАНОВКА ДВЕРЕЙ', 'uk' => 'ВСТАНОВЛЕННЯ ДВЕРЕЙ'],
                'title' => ['de' => 'Zeigen Sie die Öffnung, bevor der Meister kommt', 'en' => 'Show the doorway before the specialist arrives', 'ru' => 'Покажите проём до выезда мастера', 'uk' => 'Покажіть отвір до виїзду майстра'],
                'text' => ['de' => 'Fotos helfen, Maße, Demontage und Montageaufwand vorab zu verstehen.', 'en' => 'Photos help clarify measurements, removal and installation work in advance.', 'ru' => 'Фотографии помогут заранее понять размеры, демонтаж и сложность установки.', 'uk' => 'Фотографії допоможуть заздалегідь зрозуміти розміри, демонтаж і складність монтажу.'],
                'action' => ['de' => 'Türöffnung zeigen', 'en' => 'Show the doorway', 'ru' => 'Показать дверной проём', 'uk' => 'Показати дверний отвір'],
            ],
            'trust' => [
                ['icon' => 'measure', 'label' => ['de' => 'Maße vorab', 'en' => 'Measurements first', 'ru' => 'Размеры заранее', 'uk' => 'Розміри заздалегідь']],
                ['icon' => 'tool', 'label' => ['de' => 'Saubere Montage', 'en' => 'Clean installation', 'ru' => 'Аккуратный монтаж', 'uk' => 'Акуратний монтаж']],
                ['icon' => 'clock', 'label' => ['de' => 'Klare Termine', 'en' => 'Clear timing', 'ru' => 'Понятные сроки', 'uk' => 'Зрозумілі строки']],
            ],
            'starter_portfolio' => [
                ['title' => ['de' => 'Innentür', 'en' => 'Interior door', 'ru' => 'Межкомнатная дверь', 'uk' => 'Міжкімнатні двері'], 'image' => '/brand/service-door.webp', 'featured' => true],
                ['title' => ['de' => 'Eingangstür', 'en' => 'Entrance door', 'ru' => 'Входная дверь', 'uk' => 'Вхідні двері'], 'image' => '/brand/service-renovation.webp'],
                ['title' => ['de' => 'Montage nach Renovierung', 'en' => 'Installation after renovation', 'ru' => 'Установка после ремонта', 'uk' => 'Монтаж після ремонту'], 'image' => '/brand/service-renovation.webp'],
            ],
        ],
        'beauty.brows' => [
            'engine' => 'booking',
            'layout' => 'brows',
            'navigation' => ['home', 'works', 'action', 'activity', 'profile'],
            'hero' => [
                'image' => '/brand/service-brows.webp',
                'eyebrow' => ['de' => 'BROW ARTIST', 'en' => 'BROW ARTIST', 'ru' => 'BROW-МАСТЕР', 'uk' => 'BROW-МАЙСТЕР'],
                'title' => ['de' => 'Natürliche Form, passend zu Ihrem Gesicht', 'en' => 'A natural shape made for your face', 'ru' => 'Естественная форма именно для вашего лица', 'uk' => 'Природна форма саме для вашого обличчя'],
                'text' => ['de' => 'Wählen Sie eine Leistung und buchen Sie sofort einen freien Termin.', 'en' => 'Choose a service and book an available time instantly.', 'ru' => 'Выберите услугу и сразу запишитесь на свободное время.', 'uk' => 'Оберіть послугу та одразу запишіться на вільний час.'],
                'action' => ['de' => 'Termin buchen', 'en' => 'Book an appointment', 'ru' => 'Записаться', 'uk' => 'Записатися'],
            ],
            'trust' => [
                ['icon' => 'star', 'label' => ['de' => 'Natürliches Ergebnis', 'en' => 'Natural result', 'ru' => 'Естественный результат', 'uk' => 'Природний результат']],
                ['icon' => 'shield', 'label' => ['de' => 'Sicher und sauber', 'en' => 'Safe and clean', 'ru' => 'Безопасно и чисто', 'uk' => 'Безпечно та чисто']],
                ['icon' => 'clock', 'label' => ['de' => 'Online-Termin', 'en' => 'Online booking', 'ru' => 'Онлайн-запись', 'uk' => 'Онлайн-запис']],
            ],
            'starter_services' => [
                ['duration' => 45, 'name' => ['de' => 'Augenbrauenkorrektur', 'en' => 'Brow shaping', 'ru' => 'Коррекция бровей', 'uk' => 'Корекція брів'], 'description' => ['de' => 'Form und saubere Kontur', 'en' => 'Shape and clean contour', 'ru' => 'Форма и аккуратный контур', 'uk' => 'Форма й акуратний контур']],
                ['duration' => 60, 'name' => ['de' => 'Färben und Korrektur', 'en' => 'Tint and shaping', 'ru' => 'Окрашивание и коррекция', 'uk' => 'Фарбування та корекція'], 'description' => ['de' => 'Farbe, Form und Pflege', 'en' => 'Color, shape and care', 'ru' => 'Цвет, форма и уход', 'uk' => 'Колір, форма й догляд']],
                ['duration' => 75, 'name' => ['de' => 'Brow-Laminierung', 'en' => 'Brow lamination', 'ru' => 'Ламинирование бровей', 'uk' => 'Ламінування брів'], 'description' => ['de' => 'Styling mit langem Halt', 'en' => 'Long-lasting styling', 'ru' => 'Долговременная укладка', 'uk' => 'Довготривале укладання']],
            ],
            'starter_portfolio' => [
                ['title' => ['de' => 'Korrektur', 'en' => 'Shaping', 'ru' => 'Коррекция', 'uk' => 'Корекція'], 'image' => '/brand/service-brows.webp', 'featured' => true],
                ['title' => ['de' => 'Färben', 'en' => 'Tinting', 'ru' => 'Окрашивание', 'uk' => 'Фарбування'], 'image' => '/brand/service-brows.webp'],
                ['title' => ['de' => 'Laminierung', 'en' => 'Lamination', 'ru' => 'Ламинирование', 'uk' => 'Ламінування'], 'image' => '/brand/service-brows.webp'],
            ],
            'booking' => ['timezone' => 'Europe/Berlin', 'start' => '09:00', 'end' => '18:00', 'interval' => 30, 'days' => [1, 2, 3, 4, 5, 6]],
        ],
        'general-services.general' => [
            'engine' => 'request',
            'layout' => 'general',
            'navigation' => ['home', 'works', 'action', 'activity', 'profile'],
            'hero' => [
                'image' => '/brand/lookdo-service-workspace.webp',
                'eyebrow' => ['de' => 'SERVICE', 'en' => 'SERVICE', 'ru' => 'УСЛУГИ', 'uk' => 'ПОСЛУГИ'],
                'title' => ['de' => 'Zeigen Sie, wobei Sie Hilfe brauchen', 'en' => 'Show what you need help with', 'ru' => 'Покажите, что нужно сделать', 'uk' => 'Покажіть, що потрібно зробити'],
                'text' => ['de' => 'Senden Sie Fotos oder ein kurzes Video und erhalten Sie eine persönliche Antwort.', 'en' => 'Send photos or a short video and receive a personal reply.', 'ru' => 'Отправьте фото или короткое видео и получите личный ответ.', 'uk' => 'Надішліть фото або коротке відео й отримайте особисту відповідь.'],
                'action' => ['de' => 'Anfrage senden', 'en' => 'Send a request', 'ru' => 'Отправить заявку', 'uk' => 'Надіслати запит'],
            ],
            'trust' => [],
            'starter_portfolio' => [],
        ],
    ],
];
