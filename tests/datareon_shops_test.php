<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/site/datareon_shops_lib.php';

$example = ['shops' => [
    'id' => 'e09e3db3-b7db-11f1-8fa6-d92d477b5769',
    'sapId' => 'DII8',
    'shopId' => 'DII8',
    'updatedAt' => '2026-09-24T05:50:47.493Z',
    'name' => 'DII8 DI.CE PARTIAL RETURNS',
    'organization' => 'КАЛЦРУ ООО',
    'organizationId' => '75fabd9c-421e-11f0-a6e2-a7bd3aad63e9',
    'onlineOrders' => '',
    'openhours' => [],
]];
$normalized = ccNormalizeDatareonShop($example);
if ($normalized['shop_id'] !== 'DII8'
    || $normalized['organization_name'] !== 'КАЛЦРУ ООО'
    || $normalized['source_updated_at'] !== '2026-09-24 05:50:47.493'
    || $normalized['openhours_json'] !== '[]') {
    throw new RuntimeException('Образец Datareon преобразован неверно.');
}

$withOffset = $example['shops'];
$withOffset['updatedAt'] = '2026-09-24T08:50:47.493+03:00';
if (ccNormalizeDatareonShop($withOffset)['source_updated_at'] !== $normalized['source_updated_at']) {
    throw new RuntimeException('Часовой пояс Datareon преобразован неверно.');
}

foreach ([
    ['shops' => ['id' => 'bad', 'shopId' => 'DII8', 'updatedAt' => $example['shops']['updatedAt']]],
    ['shops' => ['id' => $example['shops']['id'], 'shopId' => 'DII8', 'updatedAt' => '']],
    ['shops' => $example['shops'] + ['openhours' => []]],
] as $index => $bad) {
    if ($index === 2) $bad['shops']['openhours'] = 'invalid';
    try {
        ccNormalizeDatareonShop($bad);
        throw new RuntimeException('Некорректный payload #' . $index . ' принят.');
    } catch (InvalidArgumentException $expected) {
        // Rejected as intended.
    }
}

echo "OK\n";
