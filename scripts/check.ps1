# Runs every quality gate on Windows. Usage: powershell -ExecutionPolicy Bypass -File scripts\check.ps1
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot

function Step($name, $dir, [scriptblock]$cmd) {
    Write-Host "==> $name" -ForegroundColor Cyan
    Push-Location (Join-Path $Root $dir)
    try {
        & $cmd
        if ($LASTEXITCODE -ne 0) { throw "$name failed (exit $LASTEXITCODE)" }
    } finally { Pop-Location }
}

Step 'Backend: Pint'      'backend'  { php vendor/bin/pint --test }
Step 'Backend: Larastan'  'backend'  { php vendor/bin/phpstan analyse --memory-limit=1G --no-progress }
Step 'Backend: Pest'      'backend'  { php vendor/bin/pest }
Step 'Frontend: lint'     'frontend' { npm run --silent lint }
Step 'Frontend: typecheck' 'frontend' { npm run --silent typecheck }
Step 'Frontend: tests'    'frontend' { npm run --silent test }
Step 'Frontend: build'    'frontend' { npm run --silent build }
Step 'Frontend: no demo in build' 'frontend' { npm run --silent check:no-demo }

Write-Host 'All checks passed.' -ForegroundColor Green
