# OMNI Contact Center

Веб-интерфейс Контакт-центра OMNI для `https://cc.clz.ru`.

## Окружение

- AlmaLinux 9.8
- nginx на портах 80/443 с перенаправлением HTTP на HTTPS
- Apache + PHP 8.5 (`mod_php`)
- MariaDB 10.11
- каталог приложения: `/var/www/omniweb`

## Структура

Публичные файлы приложения находятся в каталоге `site/`.

## Проверка

```bash
find site tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/card_refund_static_test.php
```

## Возвраты подарочных сертификатов

Страница `site/card_refund.php` принимает XLSX, проверяет обязательные поля и передаёт
данные в OMNI по HTTPS. Файл и банковские реквизиты в базе КЦ не сохраняются.

Конфигурация соединения хранится вне web-root в `/etc/omniweb/card_refund.php`.
Пример находится в `deploy/card_refund.php.example`. Общий секрет должен совпадать
с `/etc/omni/card_refund_api.php` на сервере OMNI.

## Выкладка

Выкладка выполняется вручную внутри закрытого офисного контура после получения
изменений из ветки `main`. Автоматического деплоя из GitHub нет.
