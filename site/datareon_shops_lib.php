<?php

declare(strict_types=1);

function ccEnsureDatareonShopsSchema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT `datareon_shop_id`,`shop_id`,`shops_sap_id`,`shop_name`,`brand`,
            `address`,`address_city`,`phone_number`,`organization_id`,`organization_name`,
            `openhours_json`,`source_updated_at`,`payload_json`,`received_at`
            FROM `cc_shops` LIMIT 0');
    } catch (Throwable $e) {
        throw new RuntimeException('Нужна ручная миграция database/migrate_cc_datareon_shops_push.sql.', 0, $e);
    }
}

function ccDatareonShopText(array $shop, string $key, int $maximum): ?string
{
    $value = $shop[$key] ?? null;
    if ($value !== null && !is_scalar($value)) {
        throw new InvalidArgumentException('Некорректное поле shops.' . $key . '.');
    }
    $value = trim((string)$value);
    if (mb_strlen($value, 'UTF-8') > $maximum) {
        throw new InvalidArgumentException('Слишком длинное поле shops.' . $key . '.');
    }
    return $value === '' ? null : $value;
}

/** Accept a single Datareon message with {"shops": {...}} or the shop object itself. */
function ccNormalizeDatareonShop(array $payload): array
{
    $shop = $payload['shops'] ?? $payload;
    if (!is_array($shop) || array_is_list($shop)) {
        throw new InvalidArgumentException('Ожидается объект shops.');
    }
    $id = strtolower(ccDatareonShopText($shop, 'id', 36) ?? '');
    if (!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $id)) {
        throw new InvalidArgumentException('Ожидается корректный shops.id (UUID).');
    }
    $sapId = ccDatareonShopText($shop, 'sapId', 100);
    $shopId = ccDatareonShopText($shop, 'shopId', 100) ?? $sapId;
    if ($shopId === null) {
        throw new InvalidArgumentException('Нужен shops.shopId или shops.sapId.');
    }
    $organizationId = strtolower(ccDatareonShopText($shop, 'organizationId', 36) ?? '');
    if ($organizationId !== ''
        && !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $organizationId)) {
        throw new InvalidArgumentException('Некорректный shops.organizationId.');
    }
    $updatedAt = ccDatareonShopText($shop, 'updatedAt', 40);
    if ($updatedAt === null || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D', $updatedAt)) {
        throw new InvalidArgumentException('Нужен shops.updatedAt в ISO 8601 с часовым поясом.');
    }
    try {
        $sourceUpdatedAt = (new DateTimeImmutable($updatedAt))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Некорректный shops.updatedAt.', 0, $e);
    }
    $hours = $shop['openhours'] ?? [];
    if ($hours === '') $hours = [];
    if (!is_array($hours) || !array_is_list($hours)) {
        throw new InvalidArgumentException('shops.openhours должен быть массивом.');
    }
    return [
        'datareon_shop_id' => $id,
        'shop_id' => $shopId,
        'shops_sap_id' => $sapId,
        'shop_name' => ccDatareonShopText($shop, 'name', 500),
        'brand' => ccDatareonShopText($shop, 'brand', 100),
        'address' => ccDatareonShopText($shop, 'address', 10000),
        'address_city' => ccDatareonShopText($shop, 'addressCity', 255),
        'phone_number' => ccDatareonShopText($shop, 'phoneNumber', 100),
        'organization_id' => $organizationId === '' ? null : $organizationId,
        'organization_name' => ccDatareonShopText($shop, 'organization', 255),
        'openhours_json' => json_encode($hours, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'source_updated_at' => $sourceUpdatedAt,
        'payload_json' => json_encode($shop, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ];
}

function ccStoreDatareonShop(PDO $pdo, array $shop): array
{
    ccEnsureDatareonShopsSchema($pdo);
    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT `source_updated_at` FROM `cc_shops`
            WHERE `datareon_shop_id` = :id FOR UPDATE');
        $existing->execute(['id' => $shop['datareon_shop_id']]);
        $current = $existing->fetch(PDO::FETCH_ASSOC);
        if ($current && $current['source_updated_at'] !== null
            && strcmp((string)$current['source_updated_at'], $shop['source_updated_at']) > 0) {
            $pdo->commit();
            return ['accepted' => false, 'reason' => 'older_update'];
        }
        $columns = array_keys($shop);
        $updates = [];
        foreach ($columns as $column) {
            if (in_array($column, ['datareon_shop_id', 'source_updated_at'], true)) continue;
            $updates[] = '`' . $column . '`=IF(`source_updated_at` IS NULL'
                . ' OR VALUES(`source_updated_at`) >= `source_updated_at`,'
                . ' VALUES(`' . $column . '`),`' . $column . '`)';
        }
        $updates[] = '`received_at`=IF(`source_updated_at` IS NULL'
            . ' OR VALUES(`source_updated_at`) >= `source_updated_at`,UTC_TIMESTAMP(3),`received_at`)';
        $updates[] = '`source_updated_at`=IF(`source_updated_at` IS NULL'
            . ' OR VALUES(`source_updated_at`) >= `source_updated_at`,'
            . ' VALUES(`source_updated_at`),`source_updated_at`)';
        $insert = $pdo->prepare('INSERT INTO `cc_shops` (`' . implode('`,`', $columns) . '`,`received_at`)
            VALUES (:' . implode(',:', $columns) . ',UTC_TIMESTAMP(3))
            ON DUPLICATE KEY UPDATE ' . implode(',', $updates));
        $insert->execute($shop);
        $stored = $pdo->prepare('SELECT `source_updated_at` FROM `cc_shops`
            WHERE `datareon_shop_id`=:id');
        $stored->execute(['id' => $shop['datareon_shop_id']]);
        $applied = strcmp((string)$stored->fetchColumn(), $shop['source_updated_at']) === 0;
        if ($applied && $shop['organization_id'] !== null && $shop['organization_name'] !== null) {
            $org = $pdo->prepare('INSERT INTO `cc_organizations` (`id`,`name`)
                VALUES (:id,:name) ON DUPLICATE KEY UPDATE `name`=VALUES(`name`)');
            $org->execute(['id' => $shop['organization_id'], 'name' => $shop['organization_name']]);
        }
        $pdo->commit();
        return ['accepted' => $applied, 'datareon_shop_id' => $shop['datareon_shop_id']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ccDatareonShopPage(PDO $pdo, string $after, int $limit): array
{
    ccEnsureDatareonShopsSchema($pdo);
    if ($after !== '' && !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $after)) {
        throw new InvalidArgumentException('Некорректный курсор магазина.');
    }
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare('SELECT `datareon_shop_id`,`shop_id`,`shops_sap_id`,`shop_name`,`brand`,
        `address`,`address_city`,`phone_number`,`organization_id`,`organization_name`,
        `openhours_json`,`source_updated_at`,`received_at`
        FROM `cc_shops` WHERE `received_at` IS NOT NULL AND `datareon_shop_id` > :after
        ORDER BY `datareon_shop_id` LIMIT ' . ($limit + 1));
    $stmt->execute(['after' => $after]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    $lastRow = $rows ? $rows[count($rows) - 1] : null;
    return [
        'shops' => $rows,
        'next_after' => $hasMore && $lastRow !== null ? (string)$lastRow['datareon_shop_id'] : null,
        'total' => (int)$pdo->query('SELECT COUNT(*) FROM `cc_shops` WHERE `received_at` IS NOT NULL')->fetchColumn(),
    ];
}
