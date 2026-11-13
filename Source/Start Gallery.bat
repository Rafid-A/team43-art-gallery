@echo off
setlocal
pushd "%~dp0"
docker compose version >nul 2>&1
if errorlevel 1 (
    echo Install Docker Desktop with Docker Compose v2. See README.md.
    goto failed
)
docker info >nul 2>&1
if errorlevel 1 (
    echo Start Docker Desktop in Linux containers mode, then try again.
    goto failed
)
docker compose up -d --build --wait --wait-timeout 180
if errorlevel 1 goto failed
for /f "delims=" %%A in ('docker compose port web 80') do set "GALLERY_ADDRESS=%%A"
for /f "delims=" %%A in ('docker compose port phpmyadmin 80') do set "ADMIN_ADDRESS=%%A"
echo Gallery: http://%GALLERY_ADDRESS%/artwork/art-login.php
echo phpMyAdmin: http://%ADMIN_ADDRESS%/
echo Administrator: user1 / pass1
echo Client: user2 / pass2
start "" "http://%GALLERY_ADDRESS%/artwork/art-login.php"
start "" "http://%ADMIN_ADDRESS%/"
popd
pause
exit /b 0
:failed
echo Startup failed. See README.md for troubleshooting.
popd
pause
exit /b 1
