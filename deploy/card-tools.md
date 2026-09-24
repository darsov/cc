# Выкладка проверки карт и возвратов

На сервере КЦ работайте из `~/cc-src` под `omniweb`. Права `sudo` не нужны.

Перед обновлением проверьте связь КЦ с OMNI для баланса Mindbox:

```bash
curl --connect-timeout 5 --max-time 10 -I https://omni.clz.ru/
```

Рабочий конфиг находится в `/var/www/omniweb/.database.php`;
`~/cc-src/site/.database.php` отсутствует. Приложение подключается через
`/var/www/omniweb/bootstrap.php`. Подтверждено `DB_OK omniweb@localhost`
и `GRANT ALL PRIVILEGES ON omniweb.*`, поэтому для SQL не нужен ни пароль
MariaDB в командной строке, ни `sudo`.

Ручной запуск SQL из корня checkout, до публикации новых PHP-файлов:

```bash
cd ~/cc-src
php <<'PHP'
<?php
require '/var/www/omniweb/bootstrap.php';
$pdo = ccDb();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'omniweb') {
    throw new RuntimeException('Подключена не база omniweb.');
}
$sql = file_get_contents('database/migrate_cc_card_tools.sql');
if ($sql === false) {
    throw new RuntimeException('Файл миграции не найден.');
}
$statements = array_values(array_filter(array_map('trim',
    preg_split('/;\\s*(?:\\r?\\n|$)/', $sql)
)));
if (count($statements) !== 5) {
    throw new RuntimeException('Неожиданный формат SQL-миграции.');
}
foreach ($statements as $statement) {
    $pdo->exec($statement);
}
foreach (['cc_organizations', 'cc_shops', 'cc_card_organizations', 'cc_user_actions'] as $table) {
    $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 0');
    echo $table . " OK\\n";
}
PHP
```

Миграция состоит из `CREATE TABLE IF NOT EXISTS` и повторяемой вставки
организации. Если выполнение прервётся, её можно запустить повторно.

```bash
find site tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/card_refund_static_test.php
test -w /var/www/omniweb
rsync -rvc --exclude='.database.php' --exclude='.bridge.php' site/ /var/www/omniweb/
```

Если `test -w` не проходит, владелец webroot должен предоставить пользователю
`omniweb` права записи или опубликовать проверенные файлы.

Проверка `/giftcardsFind` производится на КЦ. Все запрошенные через
`lookup_organizations` номера (включая заблокированные в OMNI) сохраняются
в таблицу `cc_card_organizations`. Пробный номер: `2003056862`.

## Проверка связи с OMNI для баланса

CC обращается к `https://omni.clz.ru/cc_gift_card_api.php` по HTTPS,
подписывая запрос существующим `CC_OMNI_BRIDGE_SECRET` из
`/var/www/omniweb/.bridge.php` или `/etc/omniweb/bridge.php`.
Секреты Mindbox остаются в OMNI. Сообщение `Connection timed out after 4001 milliseconds`
означает отсутствие TCP-соединения с OMNI; до проверки подписи дело не дошло.

Из консоли CC проверьте без токенов:

```bash
getent ahostsv4 omni.clz.ru
curl --connect-timeout 4 --max-time 8 -sS -o /dev/null \\
  -w 'OMNI HTTP %{http_code}; IP %{remote_ip}; connect %{time_connect}s\\n' \\
  https://omni.clz.ru/cc_gift_card_api.php
```

Ответ HTTP 405 на GET означает, что HTTPS-маршрут работает. Код 000 и таймаут
означают, что нужно наладить маршрут или внутренний адрес до OMNI.
