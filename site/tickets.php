<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$currentUser = ccRequireAuth();
ccApplyHtmlHeaders();

$page = max(1, (int)($_GET['page'] ?? 1));
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100, 'UTF-8');
$limit = 100;
$rows = [];
$total = 0;
$error = null;
try {
    $pdo = ccDb();
    $where = $q !== '' ? ' WHERE `card_number` LIKE :q OR `request_number` LIKE :r' : '';
    $params = $q !== '' ? ['q' => '%' . $q . '%', 'r' => '%' . $q . '%'] : [];
    $count = $pdo->prepare('SELECT COUNT(*) FROM `cc_refund_tickets`' . $where);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $limit));
    $page = min($page, $pages);
    $query = $pdo->prepare('SELECT * FROM `cc_refund_tickets`' . $where
        . ' ORDER BY `client_contact_date` DESC, `last_submitted_at` DESC, `card_number` DESC LIMIT '
        . $limit . ' OFFSET ' . (($page - 1) * $limit));
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('CC tickets: ' . $e->getMessage());
    $error = 'Не удалось загрузить заявки. Проверьте создание таблицы cc_refund_tickets.';
}

$fieldIndicator = static function (array $row, string $key): string {
    if (!empty($row['has_' . $key])) return '';
    return in_array($key, ['full_name','bic','account'], true) ? '⚠️' : '—';
};
$statusIndicator = static function (string $status, array $positive, array $negative, string $reason): string {
    if (isset($positive[$status])) return '<span title="' . ccEscape($positive[$status]) . '">✅</span>';
    if (!isset($negative[$status])) return '';
    $html = '<span title="' . ccEscape($negative[$status]) . '">❌</span>';
    if ($reason !== '') $html .= '<small class="reason">' . ccEscape($reason) . '</small>';
    return $html;
};
$url = static fn(int $number): string => '/tickets.php?' . http_build_query(['q' => $q, 'page' => $number]);
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Заявки · Контакт-центр</title>
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:#0f172a;background:#f8fafc}*{box-sizing:border-box}
body{margin:0;padding:2rem 1rem}.cc-menu{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}
.cc-menu form{display:inline}.panel{background:#fff;border:1px solid #e2e8f0;border-radius:1rem;padding:1.5rem}
h1{margin:0 0 .6rem}.muted{color:#64748b}.search{display:flex;gap:.6rem;margin:1rem 0}
input{padding:.7rem;border:1px solid #cbd5e1;border-radius:.5rem;max-width:100%}
.search button{padding:.7rem 1rem;color:#fff;background:#2563eb;border:0;border-radius:.5rem;cursor:pointer}
.scroll{overflow:auto}table{width:100%;min-width:64rem;border-collapse:collapse;text-align:left}th,td{padding:.65rem;border-bottom:1px solid #e2e8f0}
th{background:#f8fafc}td.flag,th.flag{text-align:center}.pages{display:flex;gap:1rem;margin-top:1rem}
.reason{display:block;margin-top:.3rem;max-width:18rem;color:#475569;font-size:.8rem;line-height:1.35;text-align:left;white-space:pre-wrap;overflow-wrap:anywhere}
</style><?php require __DIR__ . '/cc_layout.php'; ?></head><body><main class="cc-page">
<?php include __DIR__ . '/menu.php'; ?>
<section class="panel"><h1>Заявки</h1>
<?php if ($error !== null): ?><p role="alert"><?= ccEscape($error) ?></p><?php else: ?>
<p class="muted">Всего карт: <?= number_format($total, 0, ',', ' ') ?>. Одна строка на подарочную карту.</p>
<form class="search" method="get"><input name="q" value="<?= ccEscape($q) ?>" placeholder="Номер обращения или карты" aria-label="Поиск">
<button type="submit">Найти</button></form>
<div class="scroll"><table><thead><tr><th>Обращение</th><th>Подарочная карта</th>
<?php foreach (['Телефон','Email','ФИО','БИК','Счёт','Заблокированна','Выплата'] as $heading): ?><th class="flag"><?= $heading ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<?php $requestNumber = trim((string)$row['request_number']); ?>
<tr><td><?php if ($requestNumber !== '' && ctype_digit($requestNumber)): ?><a href="<?= ccEscape('https://calzedonia.intraservice.ru/Task/view/' . rawurlencode($requestNumber)) ?>"><?= ccEscape($requestNumber) ?></a><?php else: ?><?= ccEscape($requestNumber !== '' ? $requestNumber : '—') ?><?php endif; ?><br><small><?= $row['client_contact_date'] ? ccEscape(date('d.m.Y', strtotime((string)$row['client_contact_date']))) : '—' ?></small></td>
<td><?= ccEscape((string)$row['card_number']) ?></td>
<?php foreach (['phone'=>'Телефон','email'=>'Email','full_name'=>'ФИО','bic'=>'БИК','account'=>'Счёт'] as $key=>$label): ?>
<td class="flag"><?= $fieldIndicator($row, $key) ?></td>
<?php endforeach; ?>
<?php $decision = (string)$row['decision']; $payment = (string)$row['payment_status']; ?>
<td class="flag"><?= $statusIndicator($decision,
    ['blocked'=>'Карта заблокирована','ready_for_payment'=>'Готово к выплате','refunded'=>'Возврат оформлен'],
    ['rejected'=>'Отказ в блокировке'], trim((string)($row['decision_reason'] ?? ''))) ?></td>
<td class="flag"><?= $statusIndicator($payment, ['paid'=>'Выплачено'],
    ['denied'=>'Отказ в выплате'], trim((string)($row['payment_denial_reason'] ?? ''))) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if (!$rows): ?><p class="muted">Заявки не найдены.</p><?php endif; ?>
<div class="pages"><?php if ($page > 1): ?><a href="<?= ccEscape($url($page-1)) ?>">← Назад</a><?php endif; ?>
<span><?= $page ?> / <?= $pages ?></span>
<?php if ($page < $pages): ?><a href="<?= ccEscape($url($page+1)) ?>">Далее →</a><?php endif; ?></div>
<?php endif; ?></section></main></body></html>
