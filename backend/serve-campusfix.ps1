$ErrorActionPreference = 'Stop'

$php = (Get-Command php -ErrorAction Stop).Source
$publicPath = Join-Path $PSScriptRoot 'public'
$serverRouter = Join-Path $PSScriptRoot 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'

if (-not (Test-Path -LiteralPath $serverRouter)) {
    throw 'O roteador de desenvolvimento do Laravel não foi encontrado. Verifique se as dependências do backend estão instaladas.'
}

Push-Location -LiteralPath $publicPath
try {
    & $php -d upload_max_filesize=12M -d post_max_size=16M -S 0.0.0.0:8000 -t . $serverRouter
} finally {
    Pop-Location
}
