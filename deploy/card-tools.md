# Выкладка проверки карт и возвратов

На сервере КЦ работайте из `~/cc-src` под `omniweb`. Права `sudo` не нужны.

Перед обновлением проверьте связь КЦ с OMNI для баланса Mindbox:

```bash
curl --connect-timeout 5 --max-time 10 -I https://omni.clz.ru/
```

Локальный Unix-сокет недоступен пользователю `omniweb` (2002/13).
Ручное подключение по TCP дошло до MariaDB, но пароль был отклонён (1045).
Проверьте, где находится доступный CLI файл настроек, не выводя его содержимое:

```bash
for f in /etc/omniweb/database.php /var/www/omniweb/.database.php "$PWD/site/.database.php"; do
    if test -r "$f"; then echo "READABLE $f"; else echo "NO $f"; fi
done
```

Конфигурация рядом с опубликованным PHP может отсутствовать в checkout.
Для проверки доступа из консоли используйте `bootstrap.php` опубликованного CC:

```bash
php -r 'require "/var/www/omniweb/bootstrap.php"; try { $pdo=ccDb(); echo "DB user: ", $pdo->query("SELECT CURRENT_USER()")->fetchColumn(), PHP_EOL; foreach ($pdo->query("SHOW GRANTS")->fetchAll(PDO::FETCH_COLUMN) as $grant) echo preg_replace("/\\s+IDENTIFIED\\b.*$/i", "", $grant), PHP_EOL; } catch (Throwable $e) { echo "DB_ERROR code=", $e->getCode(), PHP_EOL; }'
```

Если веб-сервер получает пароль только через своё окружение, CLI всё равно
не сможет подключиться. Тогда не пытайтесь угадывать пароль: потребуется
администратор MariaDB для выполнения SQL вручную.

Если `CREATE` для `omniweb` отсутствует, администратор MariaDB выполняет
`database/migrate_cc_card_tools.sql` в базе `omniweb` со своей учётной записью.
Если `CREATE` есть, используйте действительные реквизиты подключения,
полученные от администратора: команда `mariadb -u omniweb -p` не использует
автоматически пароль приложения. Не выводите пароль из конфигурации в терминал.

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
