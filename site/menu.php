<?php
declare(strict_types=1);
/** @var array $currentUser */
?>
<nav class="cc-menu" aria-label="Разделы КЦ">
    <div><a href="/card_check.php">Проверка карты</a> &nbsp; <a href="/card_refund.php">Возврат карты</a> &nbsp; <a href="/shops/">Магазины</a></div>
    <div><?= ccEscape((string)($currentUser['email'] ?? '')) ?>
        <form method="post" action="/logout.php">
            <input type="hidden" name="csrf_token" value="<?= ccEscape(ccCsrfToken()) ?>">
            <button type="submit">Выйти</button>
        </form>
    </div>
</nav>
