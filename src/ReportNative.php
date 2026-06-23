<?php

declare(strict_types=1);

namespace MajorsilenceReporting;

/**
 * PHP FFI wrapper for the rdlnative shared library.
 *
 * Loads the Majorsilence Reporting engine in-process via PHP's FFI extension
 * (php.ini: extension=ffi, ffi.enable=true) — no subprocess is spawned, no
 * .NET runtime is required on the host.
 *
 * Platform-specific library filenames:
 *   Linux:   librdlnative.so
 *   macOS:   librdlnative.dylib
 *   Windows: rdlnative.dll
 *
 * Usage:
 *   require_once __DIR__ . '/../src/ReportNative.php';
 *   use MajorsilenceReporting\RdlLibrary;
 *   use MajorsilenceReporting\ReportNative;
 *
 *   $lib = RdlLibrary::load('/path/to/librdlnative.so');
 *
 *   $rpt = new ReportNative($lib, '/path/to/report.rdl');
 *   $rpt->set_parameter('Country', 'Germany');
 *   $rpt->set_connection_string('Data Source=myserver.db');
 *
 *   // Export to a file
 *   $rpt->export('pdf', '/tmp/output.pdf');
 *
 *   // Export to a string (binary for pdf/tif/rtf/xlsx; text otherwise)
 *   $data = $rpt->export_to_memory('pdf');
 *
 * Supported export types: "pdf", "csv", "xlsx", "xlsx_table", "xml", "rtf",
 *                         "tif", "tifb", "html", "mht"
 */

/** Loads and initializes the rdlnative shared library. */
class RdlLibrary
{
    private const C_DECLS = <<<C
        int         rdl_init(void);
        void*       rdl_report_open(const char* rdl_path, const char* connection_string);
        int         rdl_report_set_param(void* handle, const char* name, const char* value);
        int         rdl_dataset_set_field(void* handle, const char* dataset_name, const char* field_name, const char* field_value);
        int         rdl_dataset_commit_row(void* handle, const char* dataset_name);
        int         rdl_report_render_file(void* handle, const char* output_path, const char* format);
        void        rdl_free(void* ptr);
        void        rdl_report_close(void* handle);
        const char* rdl_last_error(void);
    C;

    /**
     * Load the shared library from $lib_path and initialize the engine.
     * Returns an \FFI instance to pass to ReportNative.
     * @throws \RuntimeException on init failure
     */
    public static function load(string $lib_path): \FFI
    {
        // Tell the C# resolver where to find P/Invoke sibling libraries (libSkiaSharp.so,
        // libe_sqlite3.so, etc.) — must be set before rdl_init() is called.
        putenv('RDLNATIVE_LIB_DIR=' . dirname((string) realpath($lib_path)));
        $ffi = \FFI::cdef(self::C_DECLS, $lib_path);
        $ret = $ffi->rdl_init();
        if ($ret !== 0) {
            throw new \RuntimeException('rdl_init failed: ' . \FFI::string($ffi->rdl_last_error()));
        }
        return $ffi;
    }
}

/** In-process report renderer backed by the rdlnative shared library. */
class ReportNative
{
    private const VALID_TYPES = ['pdf', 'csv', 'xlsx', 'xlsx_table', 'xml', 'rtf', 'tif', 'tifb', 'html', 'mht'];

    private string $connectionString = '';
    private array  $parameters       = [];
    private array  $dataSets         = [];

    /**
     * @param \FFI   $ffi        FFI instance returned by RdlLibrary::load()
     * @param string $reportPath Path to the .rdl file
     */
    public function __construct(
        private readonly \FFI   $ffi,
        private readonly string $reportPath,
    ) {}

    /**
     * Set a report parameter value.
     * @param string $name  Parameter name as declared in the RDL
     * @param string $value Parameter value
     */
    public function set_parameter(string $name, string $value): void
    {
        $this->parameters[$name] = $value;
    }

    /**
     * Override the connection string defined in the RDL.
     */
    public function set_connection_string(string $connectionString): void
    {
        $this->connectionString = $connectionString;
    }

    /**
     * Supply in-memory data for a named dataset, bypassing any database query.
     *
     * @param string  $datasetName Name of the DataSet element in the RDL (e.g. "Data")
     * @param array[] $rows        Array of associative arrays mapping field name => value.
     *                             Field names must match the <Field Name="..."> values in the RDL.
     *
     * SkipDatabaseSchemaValidation is set automatically when dataset rows are present,
     * so no DB connection is needed at parse or render time.
     *
     * Example:
     *   $rpt->add_data('Data', [
     *     ['Product' => 'Chai',  'Region' => 'North America', 'Amount' => '1250.00'],
     *     ['Product' => 'Chang', 'Region' => 'North America', 'Amount' =>  '980.50'],
     *   ]);
     */
    public function add_data(string $datasetName, array $rows): void
    {
        $this->dataSets[$datasetName] = $rows;
    }

    /**
     * Render the report and save it to $export_path.
     * @param string $type        Output format (defaults to "pdf")
     * @param string $export_path Destination file path
     * @throws \RuntimeException on render failure
     */
    public function export(string $type, string $export_path): void
    {
        $fmt    = in_array($type, self::VALID_TYPES, true) ? $type : 'pdf';
        $handle = $this->openHandle();
        try {
            $ret = $this->ffi->rdl_report_render_file($handle, $export_path, $fmt);
            if ($ret !== 0) {
                $this->throwLastError('rdl_report_render_file');
            }
        } finally {
            $this->ffi->rdl_report_close($handle);
        }
    }

    /**
     * Render the report and return the output as a string.
     * Binary formats (pdf, tif, rtf, xlsx) are returned as raw binary strings.
     * @param string $type Output format (defaults to "pdf")
     * @return string
     * @throws \RuntimeException on render failure
     */
    public function export_to_memory(string $type): string
    {
        $fmt     = in_array($type, self::VALID_TYPES, true) ? $type : 'pdf';
        $tmpPath = (string) tempnam(sys_get_temp_dir(), 'rdlnative');
        try {
            $this->export($fmt, $tmpPath);
            return (string) file_get_contents($tmpPath);
        } finally {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    // ─── Internal helpers ─────────────────────────────────────────────────

    /** Open a handle, apply stored parameters and dataset rows, and return it. Caller must close. */
    private function openHandle(): mixed
    {
        $cs     = $this->connectionString !== '' ? $this->connectionString : null;
        $handle = $this->ffi->rdl_report_open($this->reportPath, $cs);
        if ($handle === null) {
            $this->throwLastError('rdl_report_open');
        }
        foreach ($this->parameters as $name => $value) {
            $ret = $this->ffi->rdl_report_set_param($handle, $name, $value);
            if ($ret !== 0) {
                $this->ffi->rdl_report_close($handle);
                $this->throwLastError('rdl_report_set_param');
            }
        }
        foreach ($this->dataSets as $dsName => $rows) {
            foreach ($rows as $row) {
                foreach ($row as $field => $value) {
                    $ret = $this->ffi->rdl_dataset_set_field($handle, $dsName, (string) $field, (string) $value);
                    if ($ret !== 0) {
                        $this->ffi->rdl_report_close($handle);
                        $this->throwLastError('rdl_dataset_set_field');
                    }
                }
                $ret = $this->ffi->rdl_dataset_commit_row($handle, $dsName);
                if ($ret !== 0) {
                    $this->ffi->rdl_report_close($handle);
                    $this->throwLastError('rdl_dataset_commit_row');
                }
            }
        }
        return $handle;
    }

    /** @throws \RuntimeException */
    private function throwLastError(string $fn): never
    {
        $err = \FFI::string($this->ffi->rdl_last_error());
        throw new \RuntimeException("{$fn} failed: {$err}");
    }
}
