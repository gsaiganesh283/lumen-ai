@echo off
REM Starts both InterviewLens portals with PHP's built-in server (for local use / testing).
REM Make sure MySQL is running first (XAMPP Control Panel -> MySQL -> Start).
cd /d "%~dp0"
set PHP=php
where php >nul 2>nul || set PHP=C:\xampp\php\php.exe
start "InterviewLens user portal :8080" %PHP% -S 127.0.0.1:8080 -t user
start "InterviewLens superadmin :8081" %PHP% -S 127.0.0.1:8081 -t superadmin
timeout /t 2 >nul
start http://127.0.0.1:8081/
start http://127.0.0.1:8080/
