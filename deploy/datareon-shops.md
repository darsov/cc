# Приём магазинов Datareon

Datareon отправляет один магазин на `https://cc.clz.ru/datareon_shops.php` методом
`POST`, заголовок `Content-Type: application/json`. На первом этапе подпись и
ключ для этого входящего метода не требуются. Тело:

```json
{"shops":{"id":"e09e3db3-b7db-11f1-8fa6-d92d477b5769","shopId":"DII8","sapId":"DII8","updatedAt":"2026-09-24T05:50:47.493Z","name":"DII8 DI.CE PARTIAL RETURNS","organization":"КАЛЦРУ ООО","organizationId":"75fabd9c-421e-11f0-a6e2-a7bd3aad63e9","openhours":[]}}
```

Обязательны UUID `id`, код `shopId` либо `sapId` и `updatedAt` с часовым поясом.
Ответ `200` содержит `{"ok":true,"accepted":true,"datareon_shop_id":"..."}`.
Повторное старое обновление отвечает `200` с `accepted:false` и `reason:older_update`.
Ошибочное тело отвечает `422`, ошибка базы `500`. В отдельном запросе передаётся
один магазин. После публикации попросите Datareon повторно отправить весь справочник.

## Выкладка на КЦ

В базе `omniweb` выполнить вручную SQL из
`database/migrate_cc_datareon_shops_push.sql` через phpMyAdmin или подтверждённый
PDO приложения. Он расширяет уже созданную таблицу `cc_shops`.

```bash
cd ~/cc-src
php -l site/datareon_shops.php
php -l site/datareon_shops_lib.php
php -l site/bridge_api.php
php tests/datareon_shops_test.php
rsync -vc site/datareon_shops.php site/datareon_shops_lib.php site/bridge_api.php /var/www/omniweb/
```

После каждого принятого сообщения новая строка видна в `cc_shops` с
`received_at IS NOT NULL`. Существующие строки без этого признака не передаются
в OMNI как новый поток. OMNI запрашивает страницы через действующую подпись
`GET /bridge_api.php?action=shops&after=...&limit=500`.

Не отправляйте проверочный POST с рабочим `shops.id`: он запишет или изменит запись.
