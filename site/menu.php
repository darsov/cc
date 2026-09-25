<?php
declare(strict_types=1);
/** @var array $currentUser */
?>
<style>
.cc-menu .cc-nav{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.cc-menu .cc-nav a{display:inline-flex;align-items:center;padding:.65rem .9rem;border:1px solid #94a3b8;
    border-radius:.6rem;background:#fff;color:#1e293b;text-decoration:none;font-weight:650;line-height:1.2}
.cc-menu .cc-nav a:hover,.cc-menu .cc-nav a:focus-visible{background:#dbeafe;border-color:#2563eb;color:#1d4ed8}
.cc-menu .cc-nav a[aria-current=page]{background:#2563eb;border-color:#2563eb;color:#fff}
</style>
<nav class="cc-menu" aria-label="Разделы КЦ">
    <div class="cc-nav">
        <?php foreach (['/card_check.php' => 'Проверка', '/card_refund.php' => 'Возвраты',
            '/tickets.php' => 'Заявки', '/shops/' => 'Магазины'] as $path => $label): ?>
            <a href="<?= $path ?>"<?= parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === $path ? ' aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <div><?= ccEscape((string)($currentUser['email'] ?? '')) ?>
        <form method="post" action="/logout.php">
            <input type="hidden" name="csrf_token" value="<?= ccEscape(ccCsrfToken()) ?>">
            <button type="submit">Выйти</button>
        </form>
    </div>
</nav>
