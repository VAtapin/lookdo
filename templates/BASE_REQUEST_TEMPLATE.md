# LOOKDO — BASE REQUEST TEMPLATE

Этот документ описывает **базовое универсальное ядро Request Flow** LOOKDO.

`BASE REQUEST TEMPLATE` является fallback: если для деятельности нет более подходящего профессионального шаблона, LOOKDO всё равно может принять обычную заявку через это ядро.

**Жёсткое ядро не означает закрытый или нерасширяемый шаблон.** Профессиональный шаблон наследует базовые возможности и может добавлять/включать общие capability-модули LOOKDO: Booking, Services, Calendar, Portfolio, Before/After, Reviews, Customers, AI и т. д. Он не должен заново реализовывать эти модули своим кодом.

Общая архитектура и актуальные правила находятся в `TENANT_APP_SPEC.md`.

## Базовый Request Flow

По умолчанию:

1. клиент открывает основное действие;
2. видит короткий заголовок/подсказку;
3. при включённом media добавляет фото/короткое видео;
4. заполняет только включённые tenant/template поля;
5. при включённом комментарии пишет комментарий;
6. вводит поля контакта, которые tenant сделал обязательными;
7. отправляет заявку;
8. получает success state;
9. при необходимости получает предложение включить service push;
10. продолжает общение через доступные tenant-каналы.

## Настраиваемость tenant

Владелец бизнеса должен иметь широкую, но безопасную настройку публичного flow.

Для поддерживаемых универсальных элементов он может задавать:

- `visible` — показывать или скрыть;
- `required` — обязательно или необязательно;
- label/placeholder/help text;
- порядок в разрешённых пределах;
- min/max media count;
- photo/video allowed;
- доступные способы контакта;
- primary action/CTA.

Например, камера может быть обязательной, необязательной или полностью скрытой. Телефон также **не является глобально обязательным навсегда**: tenant определяет обязательные контактные поля. Однако система должна гарантировать, что для flow остаётся хотя бы один реальный способ обратной связи, если обращение требует ответа.

## Универсальные поля

Поддерживаются централизованно, без profession-specific Vue-компонентов:

- text;
- textarea;
- select;
- number;
- checkbox;
- phone;
- e-mail;
- locale/channel selector;
- другие типы только после добавления их в общий engine.

## Media slots

Шаблон может описывать смысловые media slots: общий вид, крупный план, шильдик, reference и т. п. Общий uploader остаётся единым.

Каждый slot может иметь label, hint, required, min/max, media type и sort order. Если media отключено tenant/template, камера/галерея не показываются.

## Универсальная модель данных

Не создавать `car_requests`, `door_requests`, `brow_requests` и подобные таблицы.

Концептуально:

```text
requests
- id
- tenant_id
- template_id
- customer_id nullable
- status
- created_at
- updated_at

request_field_values
- id
- request_id
- field_key
- value_*

media
- id
- tenant_id
- request_id nullable
- type
- role
- slot_key nullable
- sort_order
- storage_key
- metadata_json nullable
```

Контактные данные могут сохраняться как значения заявки/snapshot и при наличии customer связываться с общей customer entity. Точная общая customer architecture описана в `TENANT_APP_SPEC.md`.

## Видео

Видео является возможностью общего media engine, а не отдельным flow. Template/tenant определяет allowed/recommended/limits в пределах тарифа.

## API

Request API общий для всех профессий. Никаких `/car-requests`, `/brow-requests` и т. п.

## Super Admin

Профессиональные шаблоны редактируются централизованно: тексты, локализации, capabilities, fields, media slots, hints, AI phrases, preview, enable/disable/version/audit.

## Критерий

Новый профессиональный шаблон корректен, если общие возможности LOOKDO используются конфигурацией и композициями, а profession-specific backend/frontend появляется только тогда, когда функцию невозможно разумно выразить общим reusable capability — и такое расширение сначала добавляется в общее ядро платформы.
