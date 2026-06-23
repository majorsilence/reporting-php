<?php

declare(strict_types=1);

namespace MajorsilenceReporting;

/**
 * Report class for use with the RdlCmd .NET command-line tool and a .NET runtime.
 *
 * Usage (manual install):
 *   require_once __DIR__ . '/../src/Report.php';
 *
 * Usage (Composer):
 *   require_once __DIR__ . '/vendor/autoload.php';
 *   use MajorsilenceReporting\Report;
 *
 *   $rpt = new Report('/path/to/report.rdl', '/path/to/RdlCmd.dll', 'dotnet');
 *   $rpt->set_connection_string('Data Source=/path/to/db.sqlite');
 *   $rpt->set_parameter('Country', 'Germany');
 *   $rpt->export('pdf', '/tmp/output.pdf');
 *
 * Supported export types: "pdf", "csv", "xlsx", "xml", "rtf", "tif", "html".
 */
class Report
{
    private const VALID_TYPES = ['pdf', 'csv', 'xlsx', 'xml', 'rtf', 'tif', 'html'];

    private string $connectionString = '';
    private array  $parameters       = [];

    public function __construct(
        private readonly string  $reportPath,
        private readonly string  $rdlCmdPath,
        private readonly ?string $dotnetPath = null,
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
     * Render the report and save it to $export_path.
     * @param string $type        Output format: "pdf", "csv", "xlsx", "xml", "rtf", "tif", "html". Defaults to "pdf".
     * @param string $export_path Destination file path
     * @throws \RuntimeException on process failure
     */
    public function export(string $type, string $export_path): void
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            $type = 'pdf';
        }

        $tmpDir  = sys_get_temp_dir();
        $tmpName = (string) tempnam($tmpDir, 'majorsilencereporting');
        copy($this->reportPath, $tmpName);

        $rdlArg = '/f' . $tmpName;
        $count  = 0;
        foreach ($this->parameters as $key => $value) {
            $rdlArg .= ($count++ === 0 ? '?' : '&') . $key . '=' . $value;
        }

        $args = [];
        if ($this->dotnetPath !== null) {
            $args[] = $this->dotnetPath;
            $args[] = $this->rdlCmdPath;
        } else {
            $args[] = $this->rdlCmdPath;
        }
        $args[] = $rdlArg;
        $args[] = '/t' . $type;
        $args[] = '/o' . $tmpDir;
        if ($this->connectionString !== '') {
            $args[] = '/c' . $this->connectionString;
        }

        $this->runProcess($args, dirname($this->rdlCmdPath));

        $tmpOut = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($tmpName) . '.' . $type;
        copy($tmpOut, $export_path);
        unlink($tmpName);
        unlink($tmpOut);
    }

    /**
     * Render the report and return the output as a string.
     * Binary formats (pdf, tif, rtf) are returned as raw binary strings.
     * @param string $type Output format (defaults to "pdf")
     * @return string
     * @throws \RuntimeException on process failure
     */
    public function export_to_memory(string $type): string
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            $type = 'pdf';
        }
        $tmpName = (string) tempnam(sys_get_temp_dir(), 'majorsilencereporting');
        $this->export($type, $tmpName);
        $data = (string) file_get_contents($tmpName);
        unlink($tmpName);
        return $data;
    }

    /**
     * @param list<string> $args
     * @throws \RuntimeException if the process fails to start or exits non-zero
     */
    private function runProcess(array $args, string $cwd): void
    {
        $proc = proc_open(
            $args,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $cwd,
        );
        if ($proc === false) {
            throw new \RuntimeException('Failed to start RdlCmd process.');
        }
        fclose($pipes[0]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new \RuntimeException("RdlCmd exited with code {$code}: {$stderr}");
        }
    }
}
