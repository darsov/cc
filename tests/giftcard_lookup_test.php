<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/site/card_services.php';

$card = '2003138096';
$response = ['giftcartNumber' => $card,
    'organizationId' => '9A41499A-8814-11F0-A6E3-8B3C1D90E763', 'shopId' => ' TH07 '];
$parsed = ccParseGiftCardLookup($response, $card);
if ($parsed !== ['organization_id' => '9a41499a-8814-11f0-a6e3-8b3c1d90e763', 'shop_id' => 'TH07']) {
    throw new RuntimeException('Ответ Datareon потерял shopId или ID организации.');
}
$response['shopId'] = '';
if (ccParseGiftCardLookup($response, $card)['shop_id'] !== null) {
    throw new RuntimeException('Пустой shopId должен сохраняться как NULL.');
}
$response['shopId'] = ['TH07'];
try {
    ccParseGiftCardLookup($response, $card);
    throw new LogicException('Некорректный shopId принят.');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'shopId')) throw $e;
}
echo "OK\n";
