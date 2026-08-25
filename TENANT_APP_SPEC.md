# LOOKDO — Tenant App: единое техническое задание

Это канонический документ **Части 2 LOOKDO**. Он объединяет общую архитектуру мобильных приложений мастеров: templates, Request, Booking, Calendar, Customers, Portfolio, Communication, AI, UI и branding.

## 1. Для кого продукт

LOOKDO в первую очередь создаётся для **частных мастеров, самозанятых/Einzelunternehmer и очень маленьких сервисов**, которым нужен собственный простой клиентский App без тяжёлой CRM, отдельной команды и сложного сайта.

Типичные пользователи: brow/beauty-мастер, парикмахер, маникюр, фотограф, мастер ремонта, монтажник, реставратор, мастер мебели, автосервисный специалист и другие одиночные специалисты. Поддержка нескольких сотрудников возможна как расширение, но UI и продуктовые решения прежде всего оптимизируются под одного мастера.

## 2. Mobile-first

Tenant App — app-like PWA. Основной сценарий — смартфон как для мастера, так и для его клиента. Desktop поддерживается, но не определяет UX. После входа мастер использует рабочие функции внутри того же Tenant App.

## 3. Базовое ядро и профессиональные шаблоны

`templates/BASE_REQUEST_TEMPLATE.md` — универсальное fallback-ядро Request Flow. Профессиональный template наследует его и может включать дополнительные общие capabilities. «Ядро» означает стабильную универсальную основу, а не запрет расширений.

Новая профессия не должна автоматически порождать новый frontend/controller/table. Если ей нужна новая полезная возможность, сначала проверить возможность сделать её общим reusable capability LOOKDO. Профессиональный template задаёт конфигурацию и композицию общих компонентов.

## 4. Tenant-configurable flow

Мастер получает широкие настройки. Для универсальных элементов можно задавать `visible`, `required`, label/help, порядок и limits.

Это относится, в частности, к имени, телефону, e-mail, комментарию, фото, видео, отдельным media slots, preferred channel и дополнительным полям. Если media выключено, камера не показывается. Если включено — tenant/template определяет optional/required. Для обращения, требующего ответа, должен оставаться хотя бы один реальный способ связи.

## 5. Два основных engine

### Request Engine
Фото/видео при необходимости → минимальные поля → контакт → заявка → ответ/communication.

### Booking Engine
Услуга → optional мастер → свободная дата → слот → требуемые контактные данные → запись → confirmation/reminders/reschedule/cancel.

Один tenant может использовать оба.

## 6. Services, Calendar, Appointments

Tenant создаёт услуги: name/description/duration/optional price/currency/repeat interval/booking enabled/media allowed. Calendar учитывает working hours, breaks, exceptions, vacation, blocked intervals, service duration, optional buffers и staff/resource. Backend атомарно предотвращает double booking.

Appointments: pending/confirmed/completed/cancelled/no-show без лишнего раздувания статусов.

## 7. Customers

Лёгкая customer entity нужна для истории и повторных обращений, но LOOKDO не становится тяжёлой CRM. Хранить только практически полезные данные: name, contacts, locale, preferred channel, consent, activity и связи с requests/appointments/messages/media/notes/segments.

Request может иметь `customer_id` и snapshot фактически введённых контактных данных, чтобы история заявки не менялась задним числом.

## 8. Reminders, repeat visit, vacancy fill

Общий reminder engine обслуживает appointments. Repeat interval задаётся service/tenant. После отмены мастер может использовать `Заполнить окно`: система готовит текст/card/deep booking link и предлагает подходящие сегменты/каналы. Доступность слота всегда повторно проверяется backend.

## 9. Segments

Ручные группы и динамические сегменты: постоянные, новые, давно не были, service, language, channel, staff, repeat due, marketing consent и т. п. Внутренний LOOKDO segment не равен внешней группе мессенджера.

## 10. Единый Communication Engine

Никаких отдельных систем уведомлений для каждой функции. Один abstraction layer обслуживает service messages, booking reminders, request replies, repeat invitations, vacancy fill и разрешённый marketing.

Каналы:

- Web Push/PWA;
- **Instagram**;
- WhatsApp;
- Viber;
- Telegram;
- VK;
- e-mail;
- SMS позже при необходимости.

Для каждого tenant показываются только подключённые каналы.

### Instagram — приоритетный канал

Instagram считается одной из ключевых площадок LOOKDO, особенно для мастеров-одиночек и визуальных профессий.

Поддержать на уровне общей архитектуры:

- ссылку/кнопку Instagram в профиле Tenant App;
- переход в профиль/Direct там, где это технически доступно;
- подготовку AI-текста публикации;
- подготовку Before/After и portfolio media для Instagram;
- форматы Story 9:16 и feed/post;
- branded image/card;
- booking/deep link, который мастер может использовать в bio/story/post/message;
- sharing из Tenant App через системный share flow;
- подключение Instagram/Meta API там, где официальный API и тип аккаунта реально разрешают нужную операцию.

**Не обещать автоматическую публикацию или массовую отправку в Instagram Direct, если официальный API/permissions конкретного аккаунта этого не позволяют.** В таком случае LOOKDO готовит media/text/link и открывает максимально короткий share/publish flow.

Instagram важен не только для Beauty: фотографы, мастера ремонта/реставрации, мебель, авто, декор и другие одиночные специалисты также используют визуальное портфолио.

### Другие provider APIs

То же правило действует для WhatsApp/Viber/Telegram/VK: если API позволяет — adapter выполняет операцию и логирует результат; если нет — подготовить content + share/deep link. Не изображать успешную автоматическую отправку, которой не было.

## 11. Consent

Service communication и marketing consent разделяются. Напоминание о существующей записи — service message; рекламные/возвратные массовые сообщения требуют соответствующего основания/consent там, где это необходимо. Publication consent для клиентских Before/After хранить отдельно.

## 12. AI

AI используется как помощник, а не источник истины.

Общие возможности: текст/перевод, reminders, repeat invitation, vacancy post, social caption, CTA, portfolio content, image alignment/crop/normalization по template media profile.

AI не придумывает услуги, цены, скидки, свободные слоты, визиты или обещания. Calendar/availability/permissions проверяет обычный backend. Для Before/After запрещено изменять фактический результат работы.

## 13. Social Content Engine

Из portfolio/Before-After мастер может получить готовые материалы для:

- Instagram Story;
- Instagram feed/post;
- WhatsApp/Viber/Telegram/VK sharing;
- другие поддерживаемые share targets.

Генерируются подходящий crop/layout, tenant branding, optional service/price, caption на выбранном языке и booking link/QR там, где это уместно. Мастер подтверждает публикацию.

## 14. UI

Глобальный `/UI` определяет design system, navigation principles, reusable components и app-like UX.

`/templates/.../UI` определяет **композицию** общих компонентов для профессии. Поэтому Brow, ремонт техники, двери, автомобиль, уборка и т. д. могут заметно отличаться визуальной компоновкой, оставаясь одним приложением и одним набором компонентов.

Каждый существенный template должен иметь собственный UI reference.

## 15. Branding/colors

Tenant выбирает primary color и optional secondary color при настройке шаблона и может менять их позже без смены template. Использовать semantic design tokens; hover/soft/on-primary выводятся безопасно с проверкой контраста. Цвет reference-картинки template — только пример.

## 16. Языки

Базовые platform/template locales: `de`, `en`, `ru`, `uk`. Tenant включает нужные языки. Platform translations, tenant content и template content разделяются.

## 17. Template data

Профессия задаёт code/parent/name, CTA, capability defaults, fields/media slots/hints, video settings, AI phrases, localized texts, recommended composition/UI reference и recommended preview palette.

Не создавать profession-specific DB schema без необходимости.

## 18. Master Today

Владельцу-одиночке важнее действия, чем корпоративные dashboards: заявки/записи сегодня, следующий клиент, новые сообщения, свободные окна, repeat candidates, неопубликованные работы и быстрые действия `Ответить`, `Напомнить`, `Заполнить окно`, `Создать публикацию`.

## 19. Главное продуктовое правило

LOOKDO должен экономить мастеру время. Настройки могут быть широкими, но ежедневный интерфейс должен оставаться простым. Мастер занимается своей работой; приложение помогает получить клиента, договориться, записать, напомнить, показать результат и вернуть клиента снова.
