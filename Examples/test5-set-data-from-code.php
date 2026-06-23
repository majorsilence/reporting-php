<?php

// SetDataFromCode — feed data directly into a report with no database at all,
// using the rdlnative in-process library.
//
// Build the native library first:
//   dotnet publish RdlNative/Majorsilence.Reporting.RdlNative.csproj \
//       -p:PublishAot=true -o /tmp/rdlnative-pub
//
// Run:
//   RDLNATIVE_LIB=/tmp/rdlnative-pub/librdlnative.so php -f test5-set-data-from-code.php
//
// Key patterns shown:
//   - add_data() injects rows from any PHP data source (array, API response, etc.)
//   - Array keys must exactly match the <Field Name="..."> values in the RDL
//   - No connection string is needed — SkipDatabaseSchemaValidation is set automatically
//
// Output: sales-report.pdf in the output directory

error_reporting(E_ALL);
ini_set('display_errors', '1');

date_default_timezone_set('America/Los_Angeles');

require_once __DIR__ . '/../src/ReportNative.php';

use MajorsilenceReporting\RdlLibrary;
use MajorsilenceReporting\ReportNative;

// SETUP
$current_directory = dirname(__FILE__);
$base_directory    = realpath($current_directory . '/../../../');

$report_path = realpath($base_directory . '/Examples/SetDataFromCode/SalesReport.rdl');

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $lib_name = 'rdlnative.dll';
} elseif (PHP_OS === 'Darwin') {
    $lib_name = 'librdlnative.dylib';
} else {
    $lib_name = 'librdlnative.so';
}

$lib_path = getenv('RDLNATIVE_LIB') ?: ($base_directory . '/RdlNative/bin/Release/net10.0/' . $lib_name);

$output_directory = $current_directory . '/output';
if (!file_exists($output_directory)) {
    mkdir($output_directory, 0777, true);
}

// DATA — keys must match <Field Name="..."> in SalesReport.rdl exactly
$sales_data = [
    ['Product' => 'Chai',                   'Region' => 'North America', 'Amount' => '1250.00', 'Quantity' => '50'],
    ['Product' => 'Chang',                  'Region' => 'North America', 'Amount' =>  '980.50', 'Quantity' => '42'],
    ['Product' => 'Aniseed Syrup',          'Region' => 'Europe',        'Amount' =>  '432.00', 'Quantity' => '24'],
    ['Product' => "Chef Anton's Cajun",     'Region' => 'Europe',        'Amount' => '1875.25', 'Quantity' => '75'],
    ['Product' => "Grandma's Boysenberry",  'Region' => 'Asia Pacific',  'Amount' =>  '640.00', 'Quantity' => '32'],
    ['Product' => "Uncle Bob's Organic",    'Region' => 'North America', 'Amount' =>  '315.60', 'Quantity' => '18'],
    ['Product' => 'Northwoods Cranberry',   'Region' => 'North America', 'Amount' =>  '560.00', 'Quantity' => '20'],
    ['Product' => 'Mishi Kobe Niku',        'Region' => 'Asia Pacific',  'Amount' => '4500.00', 'Quantity' => '30'],
    ['Product' => 'Ikura',                  'Region' => 'Asia Pacific',  'Amount' => '1980.00', 'Quantity' => '36'],
    ['Product' => 'Queso Cabrales',         'Region' => 'Europe',        'Amount' =>  '850.00', 'Quantity' => '25'],
    ['Product' => 'Queso Manchego La',      'Region' => 'Europe',        'Amount' =>  '720.00', 'Quantity' => '30'],
    ['Product' => 'Konbu',                  'Region' => 'Asia Pacific',  'Amount' =>  '180.00', 'Quantity' => '24'],
    ['Product' => 'Tofu',                   'Region' => 'Asia Pacific',  'Amount' =>  '560.00', 'Quantity' => '40'],
    ['Product' => 'Genen Shouyu',           'Region' => 'Asia Pacific',  'Amount' =>  '310.00', 'Quantity' => '26'],
    ['Product' => 'Pavlova',                'Region' => 'Asia Pacific',  'Amount' =>  '825.00', 'Quantity' => '55'],
    ['Product' => 'Alice Mutton',           'Region' => 'Europe',        'Amount' => '2340.00', 'Quantity' => '26'],
    ['Product' => 'Carnarvon Tigers',       'Region' => 'Asia Pacific',  'Amount' => '6200.00', 'Quantity' => '31'],
    ['Product' => 'Teatime Biscuits',       'Region' => 'Europe',        'Amount' =>  '291.60', 'Quantity' => '36'],
    ['Product' => "Sir Rodney's Marmalade", 'Region' => 'Europe',        'Amount' => '1245.00', 'Quantity' => '45'],
    ['Product' => "Sir Rodney's Scones",    'Region' => 'Europe',        'Amount' =>  '350.00', 'Quantity' => '50'],
];

// REPORT EXAMPLE
$lib = RdlLibrary::load($lib_path);
$rpt = new ReportNative($lib, $report_path);
$rpt->add_data('Data', $sales_data);

$out_path = $output_directory . '/sales-report.pdf';
$rpt->export('pdf', $out_path);
echo "Written: {$out_path}\n";

?>
