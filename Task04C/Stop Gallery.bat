@echo off
setlocal
pushd "%~dp0"
docker compose down
set "RESULT=%ERRORLEVEL%"
if "%RESULT%"=="0" echo Stopped. Database rows are preserved in the Docker volume.
popd
pause
exit /b %RESULT%
