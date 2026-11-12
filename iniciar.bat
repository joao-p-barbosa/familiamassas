@echo off
rem Sobe o servidor de desenvolvimento. Feche esta janela (ou Ctrl+C) para parar.
rem "-d extension=pdo_sqlite" liga o SQLite so para este servidor, sem mexer no php.ini.
rem 0.0.0.0 deixa o celular na mesma rede Wi-Fi abrir o site pelo IP deste computador.
cd /d "%~dp0"
echo.
echo   Cardapio:  http://localhost:8090/
echo   Painel:    http://localhost:8090/painel   (admin / trocar123)
echo   No celular (mesma rede Wi-Fi), troque "localhost" pelo IP deste computador:
ipconfig | findstr /c:"IPv4"
echo.
php -d extension=pdo_sqlite -S 0.0.0.0:8090 -t public public/index.php
