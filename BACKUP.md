# LOOKDO — резервное копирование

LOOKDO создаёт один логический комплект с общим префиксом:

- `lookdo-YYYY-MM-DD_HH-mm-ss.sql.gz` — согласованный dump MariaDB/MySQL;
- `lookdo-YYYY-MM-DD_HH-mm-ss.storage.zip` — содержимое `storage/app`;
- `lookdo-YYYY-MM-DD_HH-mm-ss.json` — manifest с размером и SHA-256 каждого файла.

По умолчанию комплекты находятся вне `httpdocs` в `/var/www/vhosts/lookdo.app/private/backups`. Количество задаёт `BACKUP_KEEP`; старые комплекты удаляются только после успешного создания новой копии.

Команды:

```bash
/opt/plesk/php/8.5/bin/php /var/www/vhosts/lookdo.app/httpdocs/artisan backup:create
/opt/plesk/php/8.5/bin/php /var/www/vhosts/lookdo.app/httpdocs/artisan backup:verify
/opt/plesk/php/8.5/bin/php /var/www/vhosts/lookdo.app/httpdocs/artisan backup:verify lookdo-2026-08-25_02-30-00
```

Создание, проверка и удаление конкретных комплектов также доступны Super Admin в разделе Backups. Все такие действия записываются в audit log.

Восстановление выполняется вручную: проверить комплект через `backup:verify`, распаковать storage archive и импортировать SQL dump в пустую базу. До восстановления сохранить отдельную копию текущей базы и `.env`.

Копии на том же сервере не защищают от потери всего сервера. В Plesk должен дополнительно работать внешний backup подписки/домена в удалённое хранилище.

## Отдельные копии клиентов

Помимо полного системного backup, каждый клиент ежедневно сохраняется в собственную папку:

- `.../tenants/tenant-ID/tenant-ID-DATE.zip` — данные клиента и только его файлы;
- `.../tenants/tenant-ID/tenant-ID-DATE.json` — manifest, SHA-256, число файлов и записей;
- по умолчанию хранится 14 состояний каждого клиента (`TENANT_BACKUP_KEEP`).

В снимок входят оформление, профиль бизнеса, услуги, работы/видео, клиенты, заявки, сообщения, календарь, ресурсы, отзывы и социальный контент. Пользователи, домены, подписки, платежи и права доступа не откатываются: восстановление контента не должно изменять биллинг или доступ к аккаунту.

Super Admin открывает раздел **Backups**, выбирает клиента и может создать, проверить, удалить или восстановить конкретный снимок. Перед восстановлением система обязательно создаёт отдельный снимок текущего состояния с причиной `pre-restore`. Для подтверждения нужно ввести точный slug клиента.

Серверные команды:

```bash
/opt/plesk/php/8.5/bin/php /var/www/vhosts/lookdo.app/httpdocs/artisan backup:tenant ivanna-brows
/opt/plesk/php/8.5/bin/php /var/www/vhosts/lookdo.app/httpdocs/artisan backup:tenant --all
```

Планировщик запускает отдельные копии всех клиентов ежедневно в 03:15. `TENANT_BACKUP_PATH` должен находиться вне `httpdocs` и также попадать во внешний Plesk backup.
