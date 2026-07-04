<?php

/**
 * Unit tests for ReportNative — the PHP FFI wrapper for rdlnative.
 *
 * Requires:
 *   - PHP FFI extension enabled (php.ini: extension=ffi, ffi.enable=true)
 *   - Published rdlnative shared library
 *   - RDLNATIVE_LIB environment variable set to the library path
 *   - LD_LIBRARY_PATH including the library directory (required before PHP starts
 *     because PHP's FFI loads with RTLD_LOCAL; .NET runtime libs must already
 *     be findable by the dynamic linker)
 *
 * Usage (Linux):
 *   dotnet publish RdlNative/... -p:PublishAot=true -o /tmp/rdlnative-pub
 *   export RDLNATIVE_LIB=/tmp/rdlnative-pub/rdlnative.so
 *   export LD_LIBRARY_PATH=/tmp/rdlnative-pub:$LD_LIBRARY_PATH
 *   composer test
 *
 * Tests are skipped automatically (not failed) if the native library or
 * sample files aren't available.
 */

declare(strict_types=1);

namespace MajorsilenceReporting\Tests;

use MajorsilenceReporting\RdlLibrary;
use MajorsilenceReporting\ReportNative;
use PHPUnit\Framework\TestCase;

final class ReportNativeTest extends TestCase
{
    private static ?\FFI $ffi = null;

    private static string $libPath;
    private static string $repoRoot;
    private static string $rdlPath;
    private static string $dbPath;
    private static string $dbCs;
    private static string $salesRdl;

    /** @var array<int, array<string, string>> */
    private static array $salesData = [
        ['Product' => 'Chai',  'Region' => 'North America', 'Amount' => '1250.00', 'Quantity' => '50'],
        ['Product' => 'Chang', 'Region' => 'Europe',         'Amount' =>  '980.50', 'Quantity' => '42'],
        ['Product' => 'Tofu',  'Region' => 'Asia Pacific',   'Amount' =>  '560.00', 'Quantity' => '40'],
    ];

    public static function setUpBeforeClass(): void
    {
        self::$libPath  = (string) getenv('RDLNATIVE_LIB');
        // Set REPORTING_REPO_ROOT to the path of the cloned Reporting repository.
        self::$repoRoot = (string) (getenv('REPORTING_REPO_ROOT') ?: dirname(__DIR__, 4));
        self::$rdlPath  = self::$repoRoot . '/Examples/SqliteExamples/SimpleTest1.rdl';
        self::$dbPath   = self::$repoRoot . '/Examples/northwindEF.db';
        self::$dbCs     = 'Data Source=' . self::$dbPath;
        self::$salesRdl = self::$repoRoot . '/Examples/SetDataFromCode/SalesReport.rdl';
    }

    protected function setUp(): void
    {
        if (self::$libPath === '') {
            self::markTestSkipped('RDLNATIVE_LIB not set.');
        }
        if (!is_file(self::$libPath)) {
            self::markTestSkipped("RDLNATIVE_LIB=" . self::$libPath . ' does not exist.');
        }
        if (!is_file(self::$rdlPath)) {
            self::markTestSkipped('Sample RDL not found at ' . self::$rdlPath);
        }
        if (!is_file(self::$dbPath)) {
            self::markTestSkipped('Sample DB not found at ' . self::$dbPath);
        }
        if (!extension_loaded('ffi')) {
            self::markTestSkipped('PHP FFI extension not loaded.');
        }
    }

    private function ffi(): \FFI
    {
        return self::$ffi ??= RdlLibrary::load(self::$libPath);
    }

    private function makeReport(): ReportNative
    {
        $rpt = new ReportNative($this->ffi(), self::$rdlPath);
        $rpt->set_connection_string(self::$dbCs);
        return $rpt;
    }

    private function makeSalesReport(): ReportNative
    {
        if (!is_file(self::$salesRdl)) {
            self::markTestSkipped('SalesReport.rdl not found at ' . self::$salesRdl);
        }
        $rpt = new ReportNative($this->ffi(), self::$salesRdl);
        $rpt->add_data('Data', self::$salesData);
        return $rpt;
    }

    // ─── Basic render ─────────────────────────────────────────────────────

    public function testPdfMemory(): void
    {
        $data = $this->makeReport()->export_to_memory('pdf');
        $this->assertGreaterThan(1000, strlen($data));
        $this->assertStringStartsWith('%PDF', $data);
    }

    public function testHtmlMemory(): void
    {
        $data = $this->makeReport()->export_to_memory('html');
        $this->assertGreaterThan(100, strlen($data));
        $this->assertStringContainsString('<html', strtolower($data));
    }

    public function testCsvMemory(): void
    {
        $data = $this->makeReport()->export_to_memory('csv');
        $this->assertGreaterThan(0, strlen($data));
        $this->assertStringContainsString('Simple Test', $data);
    }

    public function testXmlMemory(): void
    {
        $data = $this->makeReport()->export_to_memory('xml');
        $this->assertGreaterThan(0, strlen($data));
        $this->assertStringContainsString('<?xml', $data);
    }

    public function testPdfToFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rdlnative_') . '.pdf';
        try {
            $this->makeReport()->export('pdf', $path);
            $this->assertGreaterThan(1000, (int) filesize($path));
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testMultipleRendersSameReport(): void
    {
        $rpt  = $this->makeReport();
        $pdf1 = $rpt->export_to_memory('pdf');
        $pdf2 = $rpt->export_to_memory('pdf');
        $this->assertSame(strlen($pdf1), strlen($pdf2), 'Repeated render produced different sizes');
    }

    // ─── Connection string and parameters ────────────────────────────────

    public function testSetConnectionString(): void
    {
        $rpt = new ReportNative($this->ffi(), self::$rdlPath);
        $rpt->set_connection_string(self::$dbCs);
        $this->assertStringContainsString('Simple Test', $rpt->export_to_memory('csv'));
    }

    public function testSetParameterDoesNotCrash(): void
    {
        $rpt = $this->makeReport();
        $rpt->set_parameter('SomeParam', 'SomeValue');
        $this->assertGreaterThan(0, strlen($rpt->export_to_memory('csv')));
    }

    // ─── Error handling ───────────────────────────────────────────────────

    public function testInvalidRdlPathRaises(): void
    {
        $rpt = new ReportNative($this->ffi(), '/nonexistent/report.rdl');
        $this->expectException(\RuntimeException::class);
        $rpt->export_to_memory('pdf');
    }

    public function testUnknownFormatDefaultsToPdf(): void
    {
        $data = $this->makeReport()->export_to_memory('not_a_format');
        $this->assertStringStartsWith('%PDF', $data, 'Unknown format should default to PDF');
    }

    // ─── add_data (in-memory dataset injection) ──────────────────────────

    public function testAddDataPdfReturnsValidPdf(): void
    {
        $data = $this->makeSalesReport()->export_to_memory('pdf');
        $this->assertGreaterThan(1000, strlen($data));
        $this->assertStringStartsWith('%PDF', $data);
    }

    public function testAddDataCsvContainsInjectedRows(): void
    {
        $data = $this->makeSalesReport()->export_to_memory('csv');
        $this->assertStringContainsString('Chai', $data);
        $this->assertStringContainsString('Chang', $data);
        $this->assertStringContainsString('Tofu', $data);
    }

    public function testAddDataExportToFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rdlnative_') . '.pdf';
        try {
            $this->makeSalesReport()->export('pdf', $path);
            $this->assertGreaterThan(1000, (int) filesize($path));
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testAddDataNoConnectionStringNeeded(): void
    {
        $this->assertGreaterThan(0, strlen($this->makeSalesReport()->export_to_memory('csv')));
    }

    public function testAddDataAllRowsPresent(): void
    {
        $data = $this->makeSalesReport()->export_to_memory('csv');
        foreach (self::$salesData as $row) {
            $this->assertStringContainsString($row['Product'], $data);
        }
    }

    public function testAddDataEmptyDatasetDoesNotCrash(): void
    {
        if (!is_file(self::$salesRdl)) {
            self::markTestSkipped('SalesReport.rdl not found at ' . self::$salesRdl);
        }
        $rpt = new ReportNative($this->ffi(), self::$salesRdl);
        $rpt->add_data('Data', []);
        $this->assertStringStartsWith('%PDF', $rpt->export_to_memory('pdf'));
    }
}
