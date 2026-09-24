# Выкладка проверки карт и возвратов

После обновления КЦ потребуется исходящее HTTPS-соединение КЦ → `omni.clz.ru`
для подписанного запроса баланса Mindbox; КЦ обращается к Datareon напрямую.
До выкладки с сервера КЦ проверьте `curl --connect-timeout 5 --max-time 10 -I https://omni.clz.ru/`.
Если нет ответа по HTTPS, проверка баланса из КЦ не заработает: не выкладывайте
`card_check.php` до настройки маршрута или смены архитектуры интеграции.

SQL для MariaDB выполняется с правами создания таблиц **до** замены PHP-файлов:

```bash
sudo mariadb --database=omniweb < database/migrate_cc_card_tools.sql
```

Проверка файлов перед копированием:

```bash
find site tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/card_refund_static_test.php
```

Размещение публичных файлов (из корня checkout КЦ):

```bash
sudo rsync -av --exclude='.database.php' --exclude='.bridge.php' site/ /var/www/omniweb/
```

Проверить HTTP-страницы с рабочей станции в офисной сети и карту `2003056862`.
При ошибке Datareon строка загрузки отклоняется, для иных организаций выводится
«Карта выпущена другой организацией».
