# LOOKDO — Часть 1: платформа, публичный сайт, регистрация, подписки и Super Admin

> **LOOK. DO. — СМОТРИ. ДЕЛАЙ.**

Этот документ относится **только к Части 1**. Он не описывает реализацию профессиональных Tenant App, Request/Booking screens, календаря, клиентской базы и шаблонов. Всё это находится в `TENANT_APP_SPEC.md` и `/templates`.

## 1. Назначение Части 1

`lookdo.app` — B2B-вход в продукт для мастеров, сервисных компаний и владельцев малого бизнеса.

Нужно реализовать:

1. публичный сайт LOOKDO;
2. регистрацию/вход владельца бизнеса;
3. onboarding;
4. тарифы и Stripe-подписки;
5. базовый Tenant account для системных настроек;
6. Super Admin платформы;
7. tenant/пользователей/тарифы/лимиты;
8. домены;
9. multi-tenant основу;
10. локализацию DE / EN / RU / UK;
11. подбор существующего профессионального шаблона по описанию деятельности;
12. legal/content editor, audit и системные операции, уже реализованные в проекте.

## 2. Три контекста интерфейса

### A. Публичный B2B сайт LOOKDO

Объясняет продукт, показывает возможности/примеры/тарифы, регистрацию и вход. Это обычный responsive/SEO-friendly SaaS сайт.

### B. Platform/Tenant account

Системный кабинет владельца бизнеса: аккаунт, подписка, тариф, домен, onboarding и другие platform-level настройки. Рабочие функции мастера относятся к его мобильному Tenant App.

### C. Super Admin

Центральное управление LOOKDO: tenants, users, plans, subscriptions, domains, entitlements, templates/catalog, localization, content/legal, audit/system settings.

## 3. Стек

Laravel API + Vue 3 + Vite; MariaDB/MySQL; Queues; Redis-ready; Filesystem/S3-compatible; API-first; multi-tenant; PWA/Capacitor-ready там, где относится к Tenant App.

## 4. Публичный сайт

Минимально:

- LOOKDO branding;
- Возможности;
- Как работает;
- Для кого;
- примеры Tenant App;
- тарифы;
- FAQ;
- вход/регистрация;
- Support/Contact;
- необходимые legal pages;
- DE / EN / RU / UK.

Основной адресат — бизнес, не конечный клиент. Не превращать главную LOOKDO в каталог/маркетплейс мастеров.

## 5. Onboarding

Короткий flow владельца:

1. account credentials;
2. бизнес/имя;
3. язык/страна;
4. «Чем вы занимаетесь?»;
5. словарь + AI предлагают **существующий `request_template`/business template**, а не создают новый;
6. пользователь подтверждает;
7. выбирает тариф;
8. checkout, если требуется;
9. получает tenant и `<slug>.lookdo.app`;
10. переходит к настройке своего App.

При низкой уверенности показать несколько существующих вариантов. Если подходящего нет — предложить поддержку/запрос нового шаблона.

## 6. Языки

Платформа localization-first. Базовые platform locales:

- `de`;
- `en`;
- `ru`;
- `uk`.

Tenant позднее выбирает, какие из поддерживаемых языков включить в своём App. Не hardcode UI strings.

## 7. Тарифы

Единый backend source of truth: `plans` + `plan_entitlements`. Публичный pricing, checkout, Tenant account, Super Admin и feature gating читают одну модель.

Типичные entitlements: storage, video, custom_domain, staff_users, communication integrations, branding, push, AI, booking и другие capability-модули. Не разбрасывать plan checks по Vue.

Публичные карточки тарифов обязаны показывать различия из `plan_entitlements`, а не общий hardcoded-список. Базовая конфигурация:

- `Start`: до 100 заявок/месяц, 2 GB, 1 пользователь, без видео и собственного домена;
- `Pro`: до 500 заявок/месяц, 10 GB, 3 пользователя, видео, собственный домен, напоминания, До/После и Telegram;
- `Business`: заявки без лимита, 50 GB, 10 пользователей, расширенное видео, удержание клиентов, AI и расширенные интеграции.

Super Admin редактирует цены, публикацию, все четыре локали и полный каталог entitlements/лимитов. Интерфейс Super Admin остаётся немецким, но исходный текст тарифа можно ввести на DE / EN / RU / UK и кнопкой KI перевести на остальные языки. KI переводит только `name`, `description` и `badge_text`; цены, возможности и лимиты она не меняет. Повторный `lookdo:platform-data --repair` добавляет недостающие системные записи, но не перезаписывает тарифы и entitlements, изменённые администратором.

## 8. Домены

Здесь не дублировать архитектуру. Канонический документ: `DOMAIN_ARCHITECTURE.md`.

Каждый tenant сразу получает `<slug>.lookdo.app`; custom domain дополнительный.

## 9. Super Admin

Нужны централизованные разделы для:

- tenants/users;
- plans/entitlements/subscriptions;
- domains;
- template catalog и AI phrases;
- platform localization;
- legal/content;
- audit/system operations.

Super Admin не должен содержать profession-specific бизнес-логику. Шаблоны являются данными/конфигурацией.

## 10. SEO и безопасность

Публичный сайт индексируется: semantic HTML, title/description, canonical, hreflang DE/EN/RU/UK, OG, favicon, sitemap/robots и разумный JSON-LD.

Platform account, Tenant Admin/workspace и Super Admin — `noindex`.

Все tenant-owned данные tenant-scoped. Не выполнять tenant data query до успешного tenant resolution.

## 11. Граница Части 1

Часть 1 заканчивается, когда владелец может узнать о продукте, зарегистрироваться, выбрать/получить template, оформить тариф, получить tenant/domain и управлять системными параметрами.

**Сам мобильный App мастера/клиента — Часть 2.** Не реализовывать его архитектуру по старым/удалённым заданиям; использовать только `TENANT_APP_SPEC.md` и конкретные `/templates`.
