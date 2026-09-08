param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $PhpArguments
)

$ErrorActionPreference = 'Stop'
$sikordikPhp = if ($env:SIKORDIK_PHP) { $env:SIKORDIK_PHP } else { 'C:\laragon\bin\php\php-8.2.27-Win32-vs16-x64\php.exe' }
if (-not (Test-Path -LiteralPath $sikordikPhp -PathType Leaf)) {
    throw 'PHP proyek tidak ditemukan. Isi SIKORDIK_PHP dengan path PHP 8.2+ yang sesuai.'
}

# Load SQLite only if the selected runtime has not already enabled it.
$sikordikModules = & $sikordikPhp -m
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
$sikordikExtensions = @()
foreach ($sikordikExtension in @('pdo_sqlite', 'sqlite3')) {
    if ($sikordikModules -notcontains $sikordikExtension) {
        $sikordikExtensions += @('-d', "extension=$sikordikExtension")
    }
}
& $sikordikPhp @sikordikExtensions @PhpArguments
exit $LASTEXITCODE
