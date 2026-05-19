<?php
require __DIR__ . '/vendor/autoload.php';

use Epsalibrary\Domain\SalesOrders\SalesOrderHeaderData;
use Epsalibrary\Domain\SalesOrders\Builders\SalesOrderHeaderLineBuilder;

$data = new SalesOrderHeaderData([
    'operation_center' => '002',
    'document_type' => 'PFC',
    'document_consecutive' => '24116',
    'document_date' => '20260519',
    'document_class' => '030',
    'state_indicator' => '1',
    'backorder_indicator' => '1',
    'billing_third_party' => '900123456789012',
    'billing_branch' => '001',
    'shipping_third_party' => '900123456789012',
    'shipping_branch' => '001',
    'customer_type' => '0001',
    'billing_operation_center' => '002',
    'delivery_date' => '20260520',
    'delivery_days' => '002',
    'reference_document_number' => 'OC-24116',
    'reference' => 'REF-24116',
    'load_id' => 'LOAD000001',
    'document_currency' => 'COP',
    'conversion_currency' => 'USD',
    'conversion_rate' => '00000000123',
    'local_currency' => 'COP',
    'local_rate' => '00000000123',
    'payment_condition' => '001',
    'print_indicator' => '1',
    'notes' => str_repeat('A', 2000),
    'cash_customer_id' => '900123456789012',
    'shipping_point_id' => '001',
    'seller_third_party' => '900123456789012',
    'contact_name' => str_repeat('B', 50),
    'address_line_1' => str_repeat('C', 40),
    'address_line_2' => str_repeat('D', 40),
    'address_line_3' => str_repeat('E', 40),
    'country_id' => 'COL',
    'department_id' => '05',
    'city_id' => '001',
    'district_id' => str_repeat('F', 40),
    'phone' => str_repeat('1', 20),
    'fax' => str_repeat('2', 20),
    'postal_code' => str_repeat('3', 10),
    'email' => str_repeat('x', 50),
    'discount_indicator' => '    ',
], 0, 0);

$builder = new SalesOrderHeaderLineBuilder();
$line = $builder->build($data);
echo strlen($line) . "\n";
