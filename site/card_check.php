<?php
declare(strict_types=1);

require_once __DIR__ . '/card_services.php';
$currentUser = ccRequireAuth();
ccApplyHtmlHeaders();
$error = null;
$data = null;
$card = trim((string)($_POST['card_number'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!ccVerifyCsrf($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Сессия устарела. Обновите страницу.');
        }
        ccEnsureCardToolsSchema(ccDb());
        $card = ccCardNumber($card);
        $organizationId = null;
        $datareonError = null;
        try {
            $organizationId = ccFindGiftCardOrganization($card);
            ccStoreCardOrganizations(ccDb(), [$card => ['organization_id' => $organizationId]]);
        }
        catch (Throwable $e) { $datareonError = $e->getMessage(); }
        $mindbox = null;
        $mindboxError = null;
        try { $mindbox = ccCheckMindboxGiftCard($card); }
        catch (Throwable $e) { $mindboxError = $e->getMessage(); }
        $name = $organizationId === null ? '' : ccCardOrganization(ccDb(), $organizationId);
        $data = compact('organizationId', 'name', 'mindbox', 'mindboxError', 'datareonError');
        ccLogAction(ccDb(), (int)$currentUser['id'], 'card_check', $card,
            $datareonError !== null ? 'datareon_error' : ($organizationId === null ? 'not_found' : 'found'));
    } catch (Throwable $e) {
        $error = $e->getMessage();
        try { ccLogAction(ccDb(), (int)$currentUser['id'], 'card_check', $card, 'error'); }
        catch (Throwable $logError) { error_log('CC action log failed: ' . $logError->getMessage()); }
    }
}
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Проверка карты · КЦ</title>
<style>
body{font-family:system-ui,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:2rem 1rem}
main{width:min(100%,72rem);margin:auto}.cc-menu{display:flex;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1rem}
.cc-menu a{color:#2563eb;text-decoration:none}.cc-menu form{display:inline}.cc-menu button{border:0;background:none;color:#2563eb;cursor:pointer}
.panel{background:#fff;border:1px solid #e2e8f0;border-radius:1rem;padding:2rem;box-shadow:0 18px 45px rgba(15,23,42,.08)}
form.check{display:flex;flex-wrap:wrap;gap:.7rem}input{padding:.8rem;border:1px solid #94a3b8;border-radius:.5rem;font:inherit}
.check button{padding:.8rem;border:0;border-radius:.5rem;background:#2563eb;color:#fff;cursor:pointer}
.error{background:#fee2e2;color:#991b1b;padding:1rem;border-radius:.6rem}.details{margin-top:1rem;line-height:1.8}
</style></head><body><main>
<?php include __DIR__ . '/menu.php'; ?>
<section class="panel"><h1>Проверка подарочной карты</h1>
<form class="check" method="post">
<input type="hidden" name="csrf_token" value="<?= ccEscape(ccCsrfToken()) ?>">
<input type="text" name="card_number" value="<?= ccEscape($card) ?>" inputmode="numeric" maxlength="20" pattern="[0-9]{10}|[0-9]{20}" placeholder="Номер карты" required aria-label="Номер карты">
<button type="submit">Проверить</button></form>
<?php if ($error !== null): ?><p class="error"><?= ccEscape($error) ?></p><?php endif; ?>
<?php if ($data !== null): ?><div class="details">
<?php if ($data['datareonError'] !== null): ?><strong>Организация:</strong> ошибка проверки — <?= ccEscape($data['datareonError']) ?>
<?php elseif ($data['organizationId'] === null): ?><strong>Организация:</strong> карта не найдена в Datareon.
<?php else: ?><strong>Организация:</strong> <?= ccEscape($data['name'] !== '' ? $data['name'] : 'Организация отсутствует в справочнике КЦ') ?><?php endif; ?><br>
<?php if ($data['mindbox'] !== null): ?><strong>Баланс Mindbox:</strong> <?= ccEscape((string)($data['mindbox']['balance'] ?? '')) ?> ₽
<?php else: ?><strong>Баланс Mindbox:</strong> ошибка проверки — <?= ccEscape((string)$data['mindboxError']) ?><?php endif; ?>
</div><?php endif; ?>
</section></main></body></html>
