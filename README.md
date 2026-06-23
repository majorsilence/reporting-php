# Majorsilence Reporting — PHP Wrappers

Three wrappers are provided for generating reports from PHP. Pick the one that matches how you deploy the reporting engine:

| Wrapper | Mechanism | Requires |
|---|---|---|
| `MajorsilenceReporting\Report` | subprocess → RdlCmd (.NET DLL) | .NET runtime on the host |
| `MajorsilenceReporting\ReportAot` | subprocess → RdlCmd (self-contained or AOT binary) | nothing extra |
| `MajorsilenceReporting\ReportNative` | in-process FFI → rdlnative shared library | PHP FFI extension |

**PHP 8.1 or later** is required (constructor property promotion and `str_contains`/`str_starts_with` are used throughout).

---

## Setup

### Install PHP

- **Linux (Debian/Ubuntu):**
  ```bash
  sudo apt-get update && sudo apt-get install -y php php-cli
  php --version
  ```
- **macOS** (via Homebrew):
  ```bash
  brew install php
  php --version
  ```
- **Windows** — download from [windows.php.net](https://windows.php.net/download/) or use winget:
  ```powershell
  winget install PHP.PHP
  php --version
  ```

### Install via Composer (recommended)

```bash
composer install
```

Then autoload in your script:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

### Manual install (no Composer)

```php
require_once '/path/to/reporting-php/src/Report.php';
require_once '/path/to/reporting-php/src/ReportAot.php';
// or
require_once '/path/to/reporting-php/src/ReportNative.php';
```

---

## Option 1 — `Report` (subprocess, .NET runtime required)

Use this when you have the .NET runtime installed on the host and want to run `RdlCmd.dll` through `dotnet`.

```php
use MajorsilenceReporting\Report;

$rpt = new Report(
    reportPath:  '/path/to/report.rdl',
    rdlCmdPath:  '/path/to/RdlCmd.dll',
    dotnetPath:  'dotnet',          // omit on Windows when using a native exe
);
$rpt->set_connection_string('Data Source=/path/to/northwindEF.db');
$rpt->set_parameter('Country', 'Germany');

// Export to a file
$rpt->export('pdf', '/tmp/output.pdf');

// Export to memory (binary string for pdf/tif/rtf; text string for csv/html/xml)
$data = $rpt->export_to_memory('pdf');
```

### Windows

```php
$rpt = new Report(
    reportPath: 'C:\\reports\\report.rdl',
    rdlCmdPath: 'C:\\RdlCmd\\RdlCmd.exe',
);
```

---

## Option 2 — `ReportAot` (subprocess, no runtime required)

Use this with an AOT-compiled or self-contained RdlCmd binary. No `dotnetPath` — the binary runs directly.

Download the appropriate binary from the release:
- `majorsilence-reporting-rdlcmd-aot-linux.zip` → `linux-x64/` or `linux-arm64/`
- `majorsilence-reporting-rdlcmd-aot-osx.zip` → `osx-x64/` or `osx-arm64/`
- `majorsilence-reporting-rdlcmd-aot-windows.zip` → `win-x64/` or `win-arm64/`

Or use the self-contained (non-AOT) build from `majorsilence-reporting-rdlcmd-self-contained.zip`.

```php
use MajorsilenceReporting\ReportAot;

$rpt = new ReportAot(
    reportPath: '/path/to/report.rdl',
    rdlCmdPath: '/path/to/RdlCmd',  // RdlCmd.exe on Windows
);
$rpt->set_connection_string('Data Source=/path/to/northwindEF.db');
$rpt->set_parameter('Country', 'Germany');

$rpt->export('pdf', '/tmp/output.pdf');
$data = $rpt->export_to_memory('xlsx');
```

On Linux/macOS, make the binary executable:

```bash
chmod +x /path/to/RdlCmd
```

---

## Option 3 — `ReportNative` (in-process FFI, no subprocess)

Use this for the lowest overhead: the reporting engine runs inside the PHP process via the FFI extension. No subprocess is spawned.

### Enable the FFI extension

```ini
; php.ini
extension=ffi
ffi.enable=true
```

### Load the library

Download the native shared library from the release (`majorsilence-reporting-rdlnative-linux.zip`, `-osx.zip`, or `-windows.zip`).

| Platform | Library filename |
|---|---|
| Linux | `librdlnative.so` |
| macOS | `librdlnative.dylib` |
| Windows | `rdlnative.dll` |

On Linux, pre-load the library directory before starting PHP so that the .NET runtime's sibling libraries are on `LD_LIBRARY_PATH`:

```bash
export LD_LIBRARY_PATH=/path/to/rdlnative-dir:$LD_LIBRARY_PATH
```

```php
use MajorsilenceReporting\RdlLibrary;
use MajorsilenceReporting\ReportNative;

// Load once per process
$lib = RdlLibrary::load('/path/to/librdlnative.so');

$rpt = new ReportNative($lib, '/path/to/report.rdl');
$rpt->set_connection_string('Data Source=/path/to/northwindEF.db');
$rpt->set_parameter('Country', 'Germany');

// Export to a file
$rpt->export('pdf', '/tmp/output.pdf');

// Export to memory — returns raw binary string for binary formats
$data = $rpt->export_to_memory('pdf');
```

---

## Supported export formats

| Format | Description | `Report` | `ReportAot` / `ReportNative` |
|---|---|---|---|
| `pdf` | PDF (default) | ✓ | ✓ |
| `csv` | Comma-separated values | ✓ | ✓ |
| `xlsx` | Excel workbook | ✓ | ✓ |
| `xlsx_table` | Excel workbook (table style) | | ✓ |
| `xml` | XML | ✓ | ✓ |
| `rtf` | Rich Text Format | ✓ | ✓ |
| `tif` | TIFF image | ✓ | ✓ |
| `tifb` | TIFF image (black & white) | | ✓ |
| `html` | HTML | ✓ | ✓ |
| `mht` | MHTML | | ✓ |

An unrecognised format string defaults to `pdf`.

---

## Running the tests

The test suite (`tests/ReportNativeTest.php`) covers `ReportNative`. It requires a published `rdlnative` shared library and is skipped automatically if `RDLNATIVE_LIB` is not set.

```bash
# Build the native library first (from the main Reporting repo)
dotnet publish RdlNative -c Release-DrawingCompat -r linux-x64 -f net10.0 \
    --self-contained true -p:PublishAot=true \
    -o /tmp/rdlnative-pub

# Run tests
export RDLNATIVE_LIB=/tmp/rdlnative-pub/librdlnative.so
export LD_LIBRARY_PATH=/tmp/rdlnative-pub:$LD_LIBRARY_PATH
export REPORTING_REPO_ROOT=/path/to/Reporting
php tests/ReportNativeTest.php
```

On macOS replace `linux-x64` with `osx-arm64` (or `osx-x64`) and `librdlnative.so` with `librdlnative.dylib`.

---

## Examples

See the `Examples/` subdirectory for runnable scripts:

- `test1.php` — basic PDF export to file
- `test2-connection-string-parameter.php` — connection string and report parameters
- `test3-streaming.php` — exporting to memory for streaming HTTP responses

A `viewer.php` script is also included as a simple web-based viewer that streams the rendered report directly to the browser.
