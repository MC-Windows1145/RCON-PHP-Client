@echo off
chcp 65001 >nul
TITLE RCON PHP
cd /d %~dp0

if exist "bin\php\php.exe" (
    set "PHP_BINARY=bin\php\php.exe"
) else (
    set "PHP_BINARY=php"
)

if exist "RCON.php" (
    set "MAIN_FILE=RCON.php"
) else (
    echo 错误: 找不到 RCON.php 文件
    pause
    exit /b 1
)

if exist "bin\mintty.exe" (
    echo 正在启动 RCON 控制台...
    start "" "bin\mintty.exe" -o Columns=100 -o Rows=30 -o AllowBlinking=0 -o FontQuality=3 -o Font="Consolas" -o FontHeight=10 -o CursorType=0 -o CursorBlinks=0 -h error -t "RCON PHP" -w max "%PHP_BINARY%" "%MAIN_FILE%" %*
) else (
    echo 正在启动 RCON...
    "%PHP_BINARY%" "%MAIN_FILE%" %*
)

exit /b 0