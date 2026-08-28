# LOOKDO — первая установка на Plesk

Целевая конфигурация: Plesk, PHP 8.5, Node.js 22 и MariaDB 10.6. Команды выполняются по SSH. Значения `.env` редактируются встроенным редактором Plesk, не через `nano`.

## 1. Получение проекта

```bash
cd /var/www/vhosts/lookdo.app/httpdocs
git clone https://github.com/VAtapin/lookdo.git .
cp .env.example .env
```

Открыть `.env` в редакторе Plesk и заполнить его до продолжения. Для LOOKDO обязательно оставить:

```dotenv
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://lookdo.app
APP_LOCALE=ru
APP_FALLBACK_LOCALE=ru
APP_TIMEZONE=Europe/Berlin

PLATFORM_DOMAIN=lookdo.app
SESSION_DOMAIN=.lookdo.app
SESSION_SECURE_COOKIE=true

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=ИМЯ_БАЗЫ_ИЗ_PLESK
DB_USERNAME=ПОЛЬЗОВАТЕЛЬ_БАЗЫ_ИЗ_PLESK
DB_PASSWORD=ПАРОЛЬ_БАЗЫ_ИЗ_PLESK

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

MAIL_MAILER=log
MAIL_FROM_ADDRESS=noreply@lookdo.app
MAIL_FROM_NAME=LOOKDO

STRIPE_SECRET=sk_КЛЮЧ_STRIPE
STRIPE_WEBHOOK_SECRET=
STRIPE_AUTOMATIC_TAX=true

OPENAI_API_KEY=sk_КЛЮЧ_OPENAI
OPENAI_TEXT_MODEL=gpt-5.6-luna
OPENAI_IMAGE_MODEL=gpt-image-2
OPENAI_MONTHLY_BUDGET=20
OPENAI_USER_DAILY_LIMIT=20
OPENAI_TIMEOUT=300
OPENAI_TEXT_INPUT_COST_PER_MILLION=0.20
OPENAI_TEXT_OUTPUT_COST_PER_MILLION=1.20
OPENAI_IMAGE_COST_LOW=0.006
OPENAI_IMAGE_COST_MEDIUM=0.053
OPENAI_IMAGE_COST_HIGH=0.211

BACKUP_PATH=/var/www/vhosts/lookdo.app/private/backups
BACKUP_KEEP=14
MYSQLDUMP_PATH=/usr/bin/mysqldump
```

`STRIPE_WEBHOOK_SECRET` заполняется владельцем проекта вручную секретом `whsec_...` из настроенного в Stripe webhook endpoint. Приложение и команды установки не создают webhook и не изменяют `.env`.

## 2. PHP, Node.js и сборка

```bash
cd /var/www/vhosts/lookdo.app/httpdocs

PHP_BIN=/opt/plesk/php/8.5/bin/php
NODE_DIR=/opt/plesk/node/22/bin
COMPOSER_PHAR=/usr/lib/plesk-9.0/composer.phar
export PATH="$NODE_DIR:$PATH"
hash -r

$PHP_BIN -v
$NODE_DIR/node -v
$NODE_DIR/npm -v

$PHP_BIN $COMPOSER_PHAR install --no-dev --prefer-dist --optimize-autoloader --no-interaction
$PHP_BIN artisan key:generate

$NODE_DIR/npm ci --include=dev
$NODE_DIR/npm run build

$PHP_BIN artisan migrate --seed --force
$PHP_BIN artisan lookdo:platform-data --repair
test -L public/storage || $PHP_BIN artisan storage:link
```

Если конкретная установка Plesk держит Node 22 в другом каталоге, меняется только значение `NODE_DIR`; дальше во всех командах используется полный путь.

## 3. Super Admin и Stripe

Super Admin создаётся только интерактивной командой. Имя, e-mail и скрытый пароль вводятся в командной строке и не хранятся в `.env`:

```bash
cd /var/www/vhosts/lookdo.app/httpdocs
PHP_BIN=/opt/plesk/php/8.5/bin/php

$PHP_BIN artisan lookdo:make-super-admin
$PHP_BIN artisan lookdo:stripe:setup
```

`lookdo:stripe:setup` только проверяет подключение Stripe и наличие webhook secret. Внешние объекты и `.env` команда не изменяет. Синхронизация тарифов выполняется исключительно вручную явной опцией `--sync-plans`.

## 4. Права, первая резервная копия и кеш

```bash
cd /var/www/vhosts/lookdo.app/httpdocs
PHP_BIN=/opt/plesk/php/8.5/bin/php

mkdir -p /var/www/vhosts/lookdo.app/private/backups
chmod -R ug+rwX storage bootstrap/cache
chmod 750 /var/www/vhosts/lookdo.app/private/backups

$PHP_BIN artisan backup:create
$PHP_BIN artisan backup:verify
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache
```

Встроенная копия содержит согласованный dump MariaDB и `storage/app`, хранит SHA-256 и автоматически оставляет последние 14 комплектов. Она дополняет, но не заменяет внешнюю резервную копию подписки Plesk. Подробности: [BACKUP.md](BACKUP.md).

## 5. Постоянные процессы

Команда Laravel scheduler в Scheduled Tasks Plesk, раз в минуту:

```bash
/opt/plesk/php/8.5/bin/php /var/www/vhosts/lookdo.app/httpdocs/artisan schedule:run
```

Команда постоянного queue worker в Process Manager Plesk:

```bash
/opt/plesk/php/8.5/bin/php /var/www/vhosts/lookdo.app/httpdocs/artisan queue:work --sleep=3 --tries=3 --timeout=300
```

Scheduler ежедневно создаёт backup в 02:30 и проверяет последнюю копию в 04:00. Команда `lookdo:reminders:send` подготовлена, но намеренно не добавлена в расписание: регулярная внешняя отправка подключается только отдельным явным решением владельца платформы.

## 6. Финальная проверка

```bash
cd /var/www/vhosts/lookdo.app/httpdocs
PHP_BIN=/opt/plesk/php/8.5/bin/php

$PHP_BIN artisan about
$PHP_BIN artisan migrate:status
$PHP_BIN artisan schedule:list
$PHP_BIN artisan backup:verify
curl -I https://lookdo.app
curl https://lookdo.app/api/platform
curl https://lookdo.app/sitemap.xml
```

После этого проверить регистрацию, `/login`, кабинет tenant и `/control/dashboard`.

## 7. Последующие обновления

```bash
cd /var/www/vhosts/lookdo.app/httpdocs

PHP_BIN=/opt/plesk/php/8.5/bin/php
NODE_DIR=/opt/plesk/node/22/bin
COMPOSER_PHAR=/usr/lib/plesk-9.0/composer.phar
export PATH="$NODE_DIR:$PATH"
hash -r

$PHP_BIN artisan backup:create
$PHP_BIN artisan backup:verify
$PHP_BIN artisan down
git pull --ff-only
$PHP_BIN $COMPOSER_PHAR install --no-dev --prefer-dist --optimize-autoloader --no-interaction
$NODE_DIR/npm ci --include=dev
$NODE_DIR/npm run build
$PHP_BIN artisan migrate --force
$PHP_BIN artisan lookdo:platform-data --repair
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache
$PHP_BIN artisan queue:restart
$PHP_BIN artisan up
$PHP_BIN artisan lookdo:platform-data
curl -I https://lookdo.app/
curl -I https://lookdo.app/favicon.png
```

Команда `lookdo:platform-data` выводит фактическое количество тарифов, категорий, вариантов, шаблонов, фраз словаря и страниц. Ключ `--repair` безопасно восстанавливает отсутствующие системные записи без дубликатов.

Если обновление выполняется с версии, в которой уже сломаны `backup:create` или миграция `000002`, сначала получить исправленный код, а резервную копию сделать сразу после миграции:

```bash
cd /var/www/vhosts/lookdo.app/httpdocs

PHP_BIN=/opt/plesk/php/8.5/bin/php
NODE_DIR=/opt/plesk/node/22/bin
COMPOSER_PHAR=/usr/lib/plesk-9.0/composer.phar
export PATH="$NODE_DIR:$PATH"
hash -r

git pull --ff-only
$PHP_BIN $COMPOSER_PHAR install --no-dev --prefer-dist --optimize-autoloader --no-interaction
$NODE_DIR/npm ci --include=dev
$NODE_DIR/npm run build
$PHP_BIN artisan migrate --force
$PHP_BIN artisan lookdo:platform-data --repair
$PHP_BIN artisan backup:create
$PHP_BIN artisan backup:verify
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache
$PHP_BIN artisan queue:restart
```

В этой установке используется явное кеширование `config`, `route`, `view` и `event`; команда `artisan optimize` не применяется.
