<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$currentUser = ccRequireAuth();
ccApplyHtmlHeaders();

$error = null;
$shops = [];
$total = 0;
$perPage = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100, 'UTF-8');

try {
    $pdo = ccDb();
    $available = array_fill_keys($pdo->query('SHOW COLUMNS FROM `cc_shops`')->fetchAll(PDO::FETCH_COLUMN), true);
    if (!isset($available['datareon_shop_id'])) {
        throw new RuntimeException('Таблица cc_shops не содержит идентификатор магазина.');
    }

    $fields = ['datareon_shop_id', 'shop_id', 'shops_sap_id', 'shop_name', 'brand',
        'address', 'address_city', 'phone_number', 'organization_id', 'organization_name',
        'source_updated_at', 'received_at', 'synced_at'];
    $select = [];
    foreach ($fields as $field) {
        $select[] = isset($available[$field]) ? "`{$field}`" : "NULL AS `{$field}`";
    }

    $where = '';
    $parameters = [];
    if ($search !== '') {
        $searchFields = array_values(array_filter(
            ['shop_id', 'shops_sap_id', 'shop_name', 'brand', 'address_city',
                'organization_name', 'datareon_shop_id'],
            static fn(string $field): bool => isset($available[$field])
        ));
        $where = ' WHERE (' . implode(' OR ', array_map(
            static fn(string $field): string => "`{$field}` LIKE ?",
            $searchFields
        )) . ')';
        $parameters = array_fill(0, count($searchFields), '%' . $search . '%');
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM `cc_shops`' . $where);
    $count->execute($parameters);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $sort = isset($available['received_at']) ? '`received_at`' :
        (isset($available['synced_at']) ? '`synced_at`' : '`datareon_shop_id`');
    $query = $pdo->prepare('SELECT ' . implode(',', $select) . ' FROM `cc_shops`' . $where
        . ' ORDER BY ' . $sort . ' DESC, `datareon_shop_id` ASC LIMIT ' . $perPage
        . ' OFFSET ' . (($page - 1) * $perPage));
    $query->execute($parameters);
    $shops = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$pageUrl = static function (int $number) use ($search): string {
    return '/shops/?' . http_build_query(['q' => $search, 'page' => $number]);
};
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Магазины · Контакт-центр</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:#0f172a;background:#f8fafc}
        *{box-sizing:border-box}body{margin:0;padding:2rem 1rem}main{width:min(100%,84rem);margin:auto}
        .cc-menu{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}
        .cc-menu a{color:#2563eb;text-decoration:none}.cc-menu form{display:inline}.cc-menu button{border:0;background:none;color:#2563eb;cursor:pointer}
        .panel{padding:1.5rem;border:1px solid #e2e8f0;border-radius:1rem;background:#fff;box-shadow:0 18px 45px rgba(15,23,42,.06)}
        h1{margin:0 0 .6rem}.muted{color:#64748b;font-size:.86rem}.search{display:flex;gap:.6rem;flex-wrap:wrap;margin:1.2rem 0}
        .search input{min-width:min(100%,24rem);padding:.7rem;border:1px solid #cbd5e1;border-radius:.5rem;font:inherit}
        .search button{padding:.7rem 1rem;border:0;border-radius:.5rem;background:#2563eb;color:white;cursor:pointer}
        .table-wrap{overflow:auto}table{width:100%;min-width:62rem;border-collapse:collapse;text-align:left}
        th,td{padding:.75rem;border-bottom:1px solid #e2e8f0;vertical-align:top}th{color:#475569;background:#f8fafc}
        .error{padding:1rem;border-radius:.6rem;background:#fef2f2;color:#991b1b}.pagination{display:flex;gap:1rem;margin-top:1rem}
        .pagination a{color:#2563eb}
    </style>
</head>
<body><main>
    <?php include __DIR__ . '/menu.php'; ?>
    <section class="panel">
        <h1>Магазины</h1>
        <?php if ($error !== null): ?>
            <p class="error">Не удалось загрузить магазины: <?= ccEscape($error) ?></p>
        <?php else: ?>
            <p class="muted">Найдено: <?= number_format($total, 0, ',', ' ') ?>. Данные из базы КЦ.</p>
            <form class="search" method="get" action="/shops/">
                <input name="q" value="<?= ccEscape($search) ?>" placeholder="Код, название, бренд или город" aria-label="Поиск магазина">
                <button type="submit">Найти</button>
            </form>
            <?php if (!$shops): ?>
                <p class="muted">Магазины не найдены.</p>
            <?php else: ?>
                <div class="table-wrap"><table><thead><tr>
                    <th>Код</th><th>Название и адрес</th><th>Бренд</th><th>Организация</th><th>Телефон</th><th>Обновление</th>
                </tr></thead><tbody>
                <?php foreach ($shops as $shop): ?>
                    <tr>
                        <td><strong><?= ccEscape((string)($shop['shop_id'] ?: $shop['shops_sap_id'] ?: '—')) ?></strong><br>
                            <span class="muted">Datareon: <?= ccEscape((string)$shop['datareon_shop_id']) ?></span></td>
                        <td><strong><?= ccEscape((string)($shop['shop_name'] ?: '—')) ?></strong><br>
                            <span class="muted"><?= ccEscape(trim(implode(', ', array_filter([
                                $shop['address_city'], $shop['address']
                            ], static fn($value): bool => is_string($value) && $value !== '')))) ?></span></td>
                        <td><?= ccEscape((string)($shop['brand'] ?: '—')) ?></td>
                        <td><?= ccEscape((string)($shop['organization_name'] ?: '—')) ?><br>
                            <span class="muted"><?= ccEscape((string)($shop['organization_id'] ?? '')) ?></span></td>
                        <td><?= ccEscape((string)($shop['phone_number'] ?: '—')) ?></td>
                        <td><?= ccEscape((string)($shop['source_updated_at'] ?: $shop['received_at'] ?: $shop['synced_at'] ?: '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <div class="pagination">
                    <?php if ($page > 1): ?><a href="<?= ccEscape($pageUrl($page - 1)) ?>">← Назад</a><?php endif; ?>
                    <span>Страница <?= $page ?> из <?= $pages ?></span>
                    <?php if ($page < $pages): ?><a href="<?= ccEscape($pageUrl($page + 1)) ?>">Далее →</a><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main></body></html>
