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

На КЦ нет phpMyAdmin. Под пользователем `omniweb`, без `sudo`, выполните SQL
через уже проверенный PHP PDO приложения. Миграция расширяет созданную таблицу
`cc_shops`. Сначала обновите исходники в `~/cc-src`, затем:

```bash
cd ~/cc-src
php <<'PHP'
<?php
require '/var/www/omniweb/bootstrap.php';
$pdo = ccDb();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'omniweb') {
    throw new RuntimeException('Подключена не база omniweb.');
}
$sql = file_get_contents('database/migrate_cc_datareon_shops_push.sql');
if ($sql === false) {
    throw new RuntimeException('Не найден SQL миграции.');
}
$pdo->exec($sql);
$pdo->query('SELECT `received_at`,`source_updated_at`,`payload_json` FROM `cc_shops` LIMIT 0');
echo "cc_shops OK\n";
PHP
```

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

Сверка на КЦ после повторной отправки всего справочника Datareon, тем же PDO:

```bash
php <<'PHP'
<?php
require '/var/www/omniweb/bootstrap.php';
$pdo = ccDb();
echo 'Получено: ' . $pdo->query('SELECT COUNT(*) FROM `cc_shops` WHERE `received_at` IS NOT NULL')->fetchColumn() . PHP_EOL;
$stmt = $pdo->prepare('SELECT `datareon_shop_id`,`shop_id`,`shops_sap_id`,`source_updated_at`,`received_at`
    FROM `cc_shops` WHERE `received_at` IS NOT NULL AND (`shop_id`=:shop_code OR `shops_sap_id`=:sap_code)');
$stmt->execute(['shop_code' => 'TH07', 'sap_code' => 'TH07']);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
PHP
```

Не отправляйте проверочный POST с рабочим `shops.id`: он запишет или изменит запись.
