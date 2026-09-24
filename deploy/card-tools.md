# Выкладка проверки карт и возвратов

На сервере КЦ работайте из `~/cc-src` под `omniweb`. Права `sudo` не нужны.

Для баланса CC обращается прямо к Mindbox. Связь CC → OMNI не требуется.
Проверьте доступность Mindbox с CC без отправки ключей:

```bash
curl --connect-timeout 5 --max-time 10 -sS -o /dev/null \
  -w 'Mindbox HTTP %{http_code}; IP %{remote_ip}\\n' https://api.mindbox.ru/v3/operations/sync
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

Отдельная таблица для зашифрованных настроек Mindbox:

```bash
php -r 'require "/var/www/omniweb/bootstrap.php"; $db=ccDb(); if ($db->query("SELECT DATABASE()")->fetchColumn()!=="omniweb") throw new RuntimeException("Неверная база."); $db->exec(file_get_contents("database/migrate_cc_mindbox_settings.sql")); echo "cc_mindbox_settings OK\\n";'
```

После публикации CC и OMNI настройка четырёх брендов передаётся без ручного
копирования ключей командой на OMNI: `php scripts/cc_sync.php --mindbox-only`.
OMNI посылает настройки по подписанному HTTPS; CC хранит секреты зашифрованными
ключом из существующего `.bridge.php`.

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

