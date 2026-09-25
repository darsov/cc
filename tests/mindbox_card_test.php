<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/site/card_services.php';

$payload = [
    'status' => 'Success',
    'giftCard' => [
        'status' => ['ids' => ['systemName' => 'canBeUsed']],
        'pool' => ['systemName' => 'Int2026'],
        'balance' => 550,
    ],
];
$result = ccMindboxParseGiftCard($payload, 'intimissimi');
if ($result === null || $result['balance'] !== '550.00') {
    throw new RuntimeException('Баланс Mindbox не разобран.');
}
if (ccMindboxParseGiftCard($payload, 'calzedonia') !== null) {
    throw new RuntimeException('Неверный бренд принят как подходящий.');
}
$notFound = $payload;
$notFound['giftCard']['status']['ids']['systemName'] = 'notFound';
if (ccMindboxParseGiftCard($notFound, 'intimissimi') !== null) {
    throw new RuntimeException('Отсутствующая карта принята как найденная.');
}
$blocked = $payload;
$blocked['giftCard']['status']['ids']['systemName'] = 'blocked';
try {
    ccMindboxParseGiftCard($blocked, 'intimissimi');
    throw new RuntimeException('Заблокированная карта принята как активная.');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'Карта недоступна')) throw $e;
}
echo "OK\n";
