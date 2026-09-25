<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// Run on CC as omniweb, from ~/cc-src, before publishing card_check.php.
require '/var/www/omniweb/card_services.php';

try {
    $pdo = ccDb();
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'omniweb') {
        throw new RuntimeException('Подключена не база omniweb.');
    }

    $sql = file_get_contents(__DIR__ . '/../database/migrate_cc_card_tools.sql');
    if ($sql === false) throw new RuntimeException('Не найдена миграция таблиц карт.');
    $sql = preg_replace('/^\s*--[^\n]*$/m', '', $sql);
    foreach (explode(';', (string)$sql) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }

    $columns = $pdo->query('SHOW COLUMNS FROM `cc_card_organizations`')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('shop_id', $columns, true)) {
        $pdo->exec('ALTER TABLE `cc_card_organizations`
            ADD COLUMN `shop_id` VARCHAR(100) NULL AFTER `organization_id`,
            ADD KEY `idx_cc_card_shop_id` (`shop_id`)');
    } else {
        $indexes = $pdo->query('SHOW INDEX FROM `cc_card_organizations`')
            ->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('idx_cc_card_shop_id', array_column($indexes, 'Key_name'), true)) {
            $pdo->exec('ALTER TABLE `cc_card_organizations`
                ADD KEY `idx_cc_card_shop_id` (`shop_id`)');
        }
    }

    ccEnsureCardToolsSchema($pdo);
    echo "Таблицы карт КЦ готовы; shopId будет сохраняться.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
