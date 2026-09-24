# Выкладка проверки карт и возвратов

Работайте под `omniweb`. Проверка организации идёт из КЦ в Datareon, проверка
баланса — из КЦ непосредственно в `api.mindbox.ru`. Настройки Mindbox OMNI передаёт
в КЦ через подписанный bridge после выкладки обеих сторон.

На сервере КЦ загрузите согласованную ветку и переключитесь на проверенный commit:

```bash
set -e
cd ~/cc-src
test -z "$(git status --porcelain)"
git fetch origin codex/card-tools-org-check-20260924
git switch --detach <проверенный-commit-cc>
php -l site/card_services.php
php -l site/bridge_api.php
php -l site/card_check.php
php tests/card_refund_static_test.php
php tests/mindbox_card_test.php
```

Четыре таблицы из `database/migrate_cc_card_tools.sql` уже созданы. Создайте новую
таблицу настроек через подтверждённое подключение приложения к БД:

```bash
php <<'PHP'
<?php
require '/var/www/omniweb/bootstrap.php';
$pdo = ccDb();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'omniweb') {
    throw new RuntimeException('Подключена не база omniweb.');
}
$pdo->exec(file_get_contents('database/migrate_cc_mindbox_settings.sql'));
$pdo->query('SELECT 1 FROM `cc_mindbox_settings` LIMIT 0');
echo "cc_mindbox_settings OK\n";
PHP
```

Копируйте файлы без сохранения владельца и группы (ранее `rsync -a` вернул
`chgrp: Operation not permitted`):

```bash
rsync -vc site/card_services.php site/bridge_api.php site/card_check.php /var/www/omniweb/
```

На OMNI после проверки и включения изменения в `main` выполните
`~/bin/deploy-omni`, затем из `~/omni-src` выполните
`php scripts/cc_sync.php --mindbox-only`. Последняя команда передаёт четыре
настройки, не запускает синхронизацию UNF и не обращается к Datareon.
Ожидаемый результат: `{"mindbox":{"synced":4}}`.
