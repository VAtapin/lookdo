# LOOKDO — первая установка на Plesk

Целевая конфигурация: Plesk Obsidian, PHP 8.5, Node.js 22, MariaDB 10.6. PostgreSQL для Части 1 не требуется: используем MariaDB, чтобы эксплуатация и резервное копирование оставались проще.

## 1. Настройки домена в Plesk

- Document root: каталог `public` внутри проекта, например `/var/www/vhosts/lookdo.app/httpdocs/public`.
- PHP handler: PHP 8.5 FPM.
- Основной домен и `www` направить на сайт.
- Создать wildcard DNS `*.lookdo.app` на тот же сервер и выпустить wildcard SSL для `lookdo.app` и `*.lookdo.app`.
- Для custom domain tenant сначала направляет A/AAAA/CNAME на сервер, затем домен проверяется в кабинете и добавляется в Plesk/SSL.

## 2. Пути Plesk

Plesk не всегда использует системные `php`, `node` и `npm`. Для этого проекта указываем бинарники явно:

```bash
PHP_BIN=/opt/plesk/php/8.5/bin/php
NODE_DIR=/opt/plesk/node/22/bin
COMPOSER_PHAR=/usr/lib/plesk-9.0/composer.phar
export PATH="$NODE_DIR:$PATH"

$PHP_BIN -v
$NODE_DIR/node -v
$NODE_DIR/npm -v
```

Если Plesk установил Node 22 в каталог с минорной версией, точный путь можно найти так:

```bash
plesk bin extension --exec nodejs node_modules/.bin/node --version
find /opt/plesk/node -maxdepth 3 -type f -name node
```

После этого заменить только значение `NODE_DIR`. Для всех следующих команд путь остаётся явным.

## 3. База данных

В Plesk создать базу и пользователя MariaDB. В `.env` использовать:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lookdo
DB_USERNAME=lookdo
DB_PASSWORD=СИЛЬНЫЙ_ПАРОЛЬ
```

Миграции совместимы с MariaDB 10.6 и не требуют возможностей MySQL 8.

## 4. Первая установка

Пример предполагает, что репозиторий уже клонирован в `/var/www/vhosts/lookdo.app/httpdocs`:

```bash
cd /var/www/vhosts/lookdo.app/httpdocs

PHP_BIN=/opt/plesk/php/8.5/bin/php
NODE_DIR=/opt/plesk/node/22/bin
COMPOSER_PHAR=/usr/lib/plesk-9.0/composer.phar
export PATH="$NODE_DIR:$PATH"

cp .env.example .env
$PHP_BIN $COMPOSER_PHAR install --no-dev --prefer-dist --optimize-autoloader --no-interaction
$PHP_BIN artisan key:generate

$NODE_DIR/npm ci --include=dev
$NODE_DIR/npm run build

$PHP_BIN artisan migrate --seed --force
$PHP_BIN artisan storage:link
$PHP_BIN artisan optimize
```

До `migrate --seed` заполнить `.env`: `APP_URL`, базу и SMTP. После миграций создать Super Admin интерактивно; пароль вводится скрыто и в `.env` не хранится:

```bash
$PHP_BIN artisan lookdo:make-super-admin
```

Каталоги `storage` и `bootstrap/cache` должны быть доступны на запись системному пользователю домена Plesk.

## 5. Обязательные production-переменные

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://lookdo.app
APP_LOCALE=de
PLATFORM_DOMAIN=lookdo.app
SESSION_DOMAIN=.lookdo.app
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

Stripe можно оставить пустым до подключения. Checkout API в этом случае возвращает понятный ответ о том, что billing ещё не настроен. Для webhook позднее указать `STRIPE_SECRET` и `STRIPE_WEBHOOK_SECRET`.

## 6. Cron и очередь

В Scheduled Tasks Plesk добавить Laravel scheduler раз в минуту:

```bash
/opt/plesk/php/8.5/bin/php /var/www/vhosts/lookdo.app/httpdocs/artisan schedule:run
```

Для database queue добавить постоянный worker через Plesk Process Manager либо cron-задачу с блокировкой. На Части 1 синхронных операций достаточно, но production-конфигурация уже готова к очереди.

## 7. Обновление после push

```bash
cd /var/www/vhosts/lookdo.app/httpdocs
git pull --ff-only

PHP_BIN=/opt/plesk/php/8.5/bin/php
NODE_DIR=/opt/plesk/node/22/bin
COMPOSER_PHAR=/usr/lib/plesk-9.0/composer.phar
export PATH="$NODE_DIR:$PATH"

$PHP_BIN artisan down
$PHP_BIN $COMPOSER_PHAR install --no-dev --prefer-dist --optimize-autoloader --no-interaction
$NODE_DIR/npm ci --include=dev
$NODE_DIR/npm run build
$PHP_BIN artisan migrate --force
$PHP_BIN artisan optimize
$PHP_BIN artisan up
```

Если команда между `artisan down` и `artisan up` завершится ошибкой, сначала исправить её и обязательно выполнить `$PHP_BIN artisan up`.

## 8. Проверка после установки

```bash
/opt/plesk/php/8.5/bin/php artisan about
/opt/plesk/php/8.5/bin/php artisan migrate:status
curl -I https://lookdo.app
curl https://lookdo.app/api/platform
curl https://lookdo.app/sitemap.xml
```

Также вручную проверить `/de`, `/en`, `/ru`, регистрацию, `/login`, tenant account и `/control/dashboard`.
