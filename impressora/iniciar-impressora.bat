@echo off
rem Agente de impressao: imprime sozinho as comandas na impressora termica deste computador.
rem Deixe esta janela aberta (pode minimizar). Para abrir sozinho quando o Windows iniciar:  iniciar-impressora.bat instalar
cd /d "%~dp0"
if /i "%~1"=="instalar" goto instalar
title Agente de impressao
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0agente.ps1"
pause
goto :eof

:instalar
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0agente.ps1" -Instalar
pause
goto :eof
