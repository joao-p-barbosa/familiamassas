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
powershell -NoProfile -ExecutionPolicy Bypass -Command "$s = (New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath(Startup) + \Agente de impressao.lnk); $s.TargetPath = %~dp0iniciar-impressora.bat; $s.WorkingDirectory = %~dp0; $s.WindowStyle = 7; $s.Save()"
echo.
echo   Pronto: o agente vai abrir sozinho (minimizado) quando o Windows iniciar.
echo   Para desfazer, apague o atalho "Agente de impressao" da pasta Inicializar (Win+R, shell:startup).
echo.
pause
