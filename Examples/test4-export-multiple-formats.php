<?php

// ExportMultipleFormats — render Orders.rdl to PDF, Excel, CSV, and HTML
// using the rdlnative in-process library (no subprocess / .NET runtime required).
//
// Build the native library first:
//   dotnet publish RdlNative/Majorsilence.Reporting.RdlNative.csproj \
//       -p:PublishAot=true -o /tmp/rdlnative-pub
//
// Run:
//   RDLNATIVE_LIB=/tmp/rdlnative-pub/librdlnative.so php -f test4-export-multiple-formats.php
//
// Output: orders.pdf / orders.xlsx / orders.csv / orders.html in the output directory

error_reporting(E_ALL);
ini_set('display_errors', '1');

date_default_timezone_set('America/Los_Angeles');

require_once __DIR__ . '/../src/ReportNative.php';

use MajorsilenceReporting\RdlLibrary;
use MajorsilenceReporting\ReportNative;

// SETUP
$current_directory = dirname(__FILE__);
$base_directory    = realpath($current_directory . '/../../../');

$db_path     = realpath($base_directory . '/Examples/ExportMultipleFormats/sqlitetestdb2.db');
$report_path = realpath($base_directory . '/Examples/ExportMultipleFormats/Orders.rdl');

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

// REPORT EXAMPLE
$lib = RdlLibrary::load($lib_path);
$rpt = new ReportNative($lib, $report_path);
$rpt->set_connection_string('Data Source=' . $db_path);

$formats = [
    'orders.pdf'  => 'pdf',
    'orders.xlsx' => 'xlsx',
    'orders.csv'  => 'csv',
    'orders.html' => 'html',
];

foreach ($formats as $filename => $fmt) {
    $out_path = $output_directory . '/' . $filename;
    $rpt->export($fmt, $out_path);
    echo "Written: {$out_path}\n";
}

?>
