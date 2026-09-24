# Выкладка проверки карт и возвратов

На сервере КЦ работайте из `~/cc-src` под `omniweb`. Права `sudo` не нужны.

Перед обновлением проверьте связь КЦ с OMNI для баланса Mindbox:

```bash
curl --connect-timeout 5 --max-time 10 -I https://omni.clz.ru/
```

Локальный Unix-сокет недоступен пользователю `omniweb` (ошибка 2002/13). Подключайтесь по TCP к `127.0.0.1:3306` и проверьте права на создание таблиц в MariaDB:

```bash
mariadb --protocol=TCP -h 127.0.0.1 -P 3306 -u omniweb -p omniweb -e 'SHOW GRANTS;'
```

Если есть `CREATE`, выполните SQL до копирования PHP:

```bash
mariadb --protocol=TCP -h 127.0.0.1 -P 3306 -u omniweb -p omniweb < database/migrate_cc_card_tools.sql
```

Если `CREATE` отсутствует, передайте администратору БД файл
`database/migrate_cc_card_tools.sql` для выполнения в базе `omniweb`.

```bash
find site tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/card_refund_static_test.php
test -w /var/www/omniweb
rsync -av --exclude='.database.php' --exclude='.bridge.php' site/ /var/www/omniweb/
```

Если `test -w` не проходит, владелец webroot должен предоставить пользователю
`omniweb` права записи или опубликовать проверенные файлы.

Проверка `/giftcardsFind` производится на КЦ. Все запрошенные через
`lookup_organizations` номера (включая заблокированные в OMNI) сохраняются
в таблицу `cc_card_organizations`. Пробный номер: `2003056862`.
