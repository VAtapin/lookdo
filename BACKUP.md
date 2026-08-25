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
