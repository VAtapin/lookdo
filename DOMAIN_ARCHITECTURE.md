# LOOKDO — архитектура доменов tenant

Этот документ является **обязательной частью технического задания Codex**.

## Основное правило

Каждый новый tenant автоматически получает собственный служебный адрес на платформе:

```text
<slug>.lookdo.app
```

Примеры:

```text
leonid.lookdo.app
mustermann.lookdo.app
demo.lookdo.app
```

Этот адрес создаётся логически сразу после регистрации tenant и работает без необходимости выпускать отдельный сертификат для каждого tenant, потому что инфраструктура LOOKDO использует wildcard DNS + wildcard SSL для:

```text
*.lookdo.app
```

## Собственный домен клиента

Собственный домен является **дополнительным**, а не заменяет платформенный адрес.

Пример:

```text
leonid.lookdo.app        — постоянный платформенный адрес tenant
leonid-deluxe.de         — собственный домен tenant
```

Оба hostname могут вести на один и тот же tenant.

Tenant Admin должен позволять:

- видеть автоматически назначенный `<slug>.lookdo.app`;
- добавить собственный домен;
- получить DNS-инструкции;
- запустить/увидеть DNS verification;
- видеть статус SSL;
- выбрать основной публичный домен (`primary_domain`).

При наличии собственного активного домена tenant может выбрать его основным. Платформенный `<slug>.lookdo.app` при этом сохраняется как технический/резервный адрес.

## Slug

При onboarding система создаёт tenant slug автоматически из названия бизнеса, но пользователь должен иметь возможность изменить его, пока slug свободен.

Требования:

- уникальность глобально внутри LOOKDO;
- lowercase ASCII;
- допустимы `a-z`, `0-9`, `-`;
- без пробелов;
- нельзя начинать/заканчивать `-`;
- зарезервированные имена запрещены.

Минимальный список reserved slugs:

```text
www
api
admin
app
mail
smtp
imap
pop
support
help
status
static
assets
cdn
demo
login
register
pricing
billing
superadmin
```

`demo` может использоваться только системным demo tenant.

## Tenant resolution

Laravel middleware/service должен определять tenant по hostname.

Порядок:

1. `lookdo.app` / `www.lookdo.app` → публичный сайт SaaS;
2. системные hostname (`api.lookdo.app`, admin и т. п.) → соответствующий системный интерфейс;
3. `<slug>.lookdo.app` → поиск tenant по slug;
4. любой подключённый custom domain → поиск в `tenant_domains`;
5. неизвестный hostname → безопасный 404/unknown-domain screen, без раскрытия данных других tenant.

Никаких запросов tenant-owned данных до успешного tenant resolution.

## DNS

Для платформенных tenant subdomains использовать wildcard DNS:

```text
*.lookdo.app -> сервер LOOKDO
```

Не создавать отдельную DNS-запись для каждого tenant, если wildcard уже настроен.

Для custom domain клиент сам создаёт необходимые A/AAAA/CNAME записи согласно инструкции LOOKDO.

## SSL

Для платформенных адресов использовать wildcard certificate:

```text
*.lookdo.app
```

Для собственных доменов tenant выпускать отдельный сертификат автоматически после успешной DNS-проверки.

Статусы домена минимум:

```text
pending
dns_detected
verifying
ssl_pending
active
failed
```

## Модель данных

В `tenants` минимум:

```text
id
name
slug
primary_domain_id nullable
...
```

В `tenant_domains` минимум:

```text
id
tenant_id
domain
type          // platform | custom
is_primary
status
verification_token nullable
verified_at nullable
ssl_status nullable
ssl_issued_at nullable
created_at
updated_at
```

Платформенный адрес можно вычислять из `tenant.slug`, но связь/тип домена всё равно должна быть представлена архитектурно так, чтобы единый domain resolver одинаково работал с platform и custom domain.

## UX после регистрации

После успешного onboarding tenant сразу должен получить рабочую ссылку вида:

```text
https://<slug>.lookdo.app
```

Не нужно ждать подключения собственного домена.

В Tenant Admin показывать:

> Ваше приложение доступно: `https://<slug>.lookdo.app`

и рядом отдельное действие:

> Подключить свой домен

## Важное ограничение

Не делать собственный домен обязательным условием запуска приложения. Tenant должен иметь возможность полноценно начать работу только на `<slug>.lookdo.app`.
