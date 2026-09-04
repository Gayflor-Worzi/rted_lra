# ==========================================
# 1. Environment & Path Setup
# ==========================================
$env:PATH = "C:\laragon\bin\nodejs\node-v22;" + $env:PATH

$phpExe       = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
$laravelDir   = "C:\laragon\www\rted_lra"
$frontendDir  = "C:\laragon\www\rted_lra\frontend"
$mobileDir    = "C:\laragon\www\rted_lra\mobile"

Write-Host "Restarting full stack environment..." -ForegroundColor Cyan

# ==========================================
# 2. Kill Active Processes on App Ports (Excluding current PID)
# ==========================================
$portsToClear = @(8000, 5173, 8081)

foreach ($port in $portsToClear) {
    $process = Get-NetTCPConnection -LocalPort $port -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique
    if ($process -and $process -ne $PID) {
        Write-Host "Stopping process running on port $port (PID: $process)..." -ForegroundColor Yellow
        Stop-Process -Id $process -Force -ErrorAction SilentlyContinue
    }
}

# ==========================================
# 3. Start Laravel Backend (Visible Window for Debugging)
# ==========================================
Write-Host "Starting Laravel Backend..." -ForegroundColor Green
Start-Process "powershell.exe" -ArgumentList "-NoExit", "-ExecutionPolicy", "Bypass", "-Command", "cd '$laravelDir'; & '$phpExe' artisan serve --host=0.0.0.0 --port=8000"

# ==========================================
# 4. Start Vite Frontend
# ==========================================
Write-Host "Starting Vite Web Frontend..." -ForegroundColor Green
Start-Process "powershell.exe" -ArgumentList "-NoExit", "-ExecutionPolicy", "Bypass", "-Command", "`$env:PATH='$env:PATH'; cd '$frontendDir'; npm.cmd run dev"

# ==========================================
# 5. Start Expo Mobile App
# ==========================================
Write-Host "Starting Expo Mobile App..." -ForegroundColor Green
Start-Process "powershell.exe" -ArgumentList "-NoExit", "-ExecutionPolicy", "Bypass", "-Command", "`$env:PATH='$env:PATH'; cd '$mobileDir'; npx.cmd expo start"

# ==========================================
# 6. Open Web Browser
# ==========================================
Start-Sleep -Seconds 4
Start-Process "http://localhost:5173"

Write-Host "All launch commands dispatched!" -ForegroundColor Cyan