<?php
declare(strict_types=1);

namespace App\Impressao;

/**
 * O "programa da impressora" num arquivo só (D86): o dono baixa no painel e dá dois cliques no computador da impressora.
 * O arquivo já vem com o endereço do site e um código novo do agente, então não há nada para digitar. Ele:
 *   0. se nenhuma impressora do Windows usa uma porta USB, instala o driver da QIA-8388 (impressora/driver/qia-8388.zip) e cria a
 *      fila "QIA-8388" na porta USB (D90). Pede permissão de administrador uma vez; recusou, avisa e segue. O agente manda a
 *      comanda em RAW (ESC/POS), então o driver só precisa existir para o Windows ter a fila: outra impressora USB também funciona;
 *   1. desliga o agente que estiver rodando neste computador;
 *   2. grava agente.ps1 e config.json em %LOCALAPPDATA%\ImpressoraDoPainel;
 *   3. cria o atalho na pasta Inicializar do Windows (abre sozinho, escondido, quando o computador liga);
 *   4. liga o agente agora, sem janela.
 *
 * Formato: um .cmd com o instalador PowerShell depois do marcador. O cmd só lê até o "exit", e o PowerShell lê o próprio
 * arquivo e roda o que vem depois do marcador. Assim nenhum comando PowerShell passa pelas aspas do cmd (foi o que quebrou o
 * "instalar" antigo). O agente vai em base64 dentro dele. O arquivo fica só com ASCII e quebra de linha CRLF.
 *
 * Para testar sem mexer no computador de verdade: IMPRESSORA_PASTA_TESTE e IMPRESSORA_ATALHO_TESTE trocam as duas pastas.
 */
final class Instalador
{
    public const NOME_ARQUIVO = 'instalar-impressora.cmd';
    public const MARCADOR = '##POWERSHELL##';

    public static function cmd(string $servidor, string $codigo, ?string $agente = null): string
    {
        $agente ??= (string) file_get_contents(RAIZ . '/impressora/agente.ps1');
        $driver = base64_encode((string) file_get_contents(RAIZ . '/impressora/driver/qia-8388.zip'));
        $aspas = static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'";
        $base64 = base64_encode($agente);

        $cmd = <<<'CMD'
@echo off
rem Programa da impressora: instala e liga o agente de impressao neste computador.
rem Pode apagar este arquivo depois de instalar.
title Programa da impressora
set "INSTALADOR=%~f0"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$t = [IO.File]::ReadAllText($env:INSTALADOR); Invoke-Expression $t.Substring($t.LastIndexOf('#' + '#POWERSHELL#' + '#'))"
if errorlevel 1 goto erro
timeout /t 15
exit /b 0
:erro
echo.
echo   Tire uma foto desta tela e mande para quem cuida do sistema.
echo.
pause
exit /b 1
CMD;

        $ps = <<<'PS'
$ErrorActionPreference = 'Stop'
try {
    $servidor = __SERVIDOR__
    $codigo = __CODIGO__
    $pasta = Join-Path $env:LOCALAPPDATA 'ImpressoraDoPainel'
    if ($env:IMPRESSORA_PASTA_TESTE) { $pasta = $env:IMPRESSORA_PASTA_TESTE }
    $inicializar = [Environment]::GetFolderPath('Startup')
    if ($env:IMPRESSORA_ATALHO_TESTE) { $inicializar = $env:IMPRESSORA_ATALHO_TESTE }
    Write-Host ''
    Write-Host '  Instalando o programa da impressora...'

    # 0. A impressora no Windows: sem nenhuma fila numa porta USB, instala o driver da QIA-8388 e cria a fila (pede administrador)
    $ProgressPreference = 'SilentlyContinue'
    $temFila = @(Get-Printer -ErrorAction SilentlyContinue | Where-Object { $_.PortName -like 'USB*' }).Count -gt 0
    if (-not $temFila -and -not $env:IMPRESSORA_PASTA_TESTE) {
        $porta = Get-PrinterPort -ErrorAction SilentlyContinue | Where-Object { $_.Name -like 'USB*' } | Select-Object -First 1
        if (-not $porta) {
            Write-Host '  A impressora nao apareceu no USB. Ligue a impressora, conecte o cabo e abra este arquivo de novo.'
        } else {
            Write-Host '  Instalando a impressora no Windows. Se o Windows pedir permissao, clique em Sim.'
            $pastaDriver = Join-Path $env:TEMP 'driver-impressora-painel'
            Remove-Item $pastaDriver -Recurse -Force -ErrorAction SilentlyContinue
            New-Item -ItemType Directory -Force -Path $pastaDriver | Out-Null
            $zip = Join-Path $pastaDriver 'driver.zip'
            [IO.File]::WriteAllBytes($zip, [Convert]::FromBase64String(__DRIVER__))
            Expand-Archive -Path $zip -DestinationPath $pastaDriver -Force
            $inf = Join-Path $pastaDriver 'labelprinter.inf'
            $comandos = "pnputil /add-driver '$inf' | Out-Null; Add-PrinterDriver -Name 'LABEL'; Add-Printer -Name 'QIA-8388' -DriverName 'LABEL' -PortName '$($porta.Name)'"
            $codificado = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($comandos))
            try {
                Start-Process -FilePath powershell -Verb RunAs -Wait -WindowStyle Hidden -ArgumentList '-NoProfile', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $codificado
            } catch {
                Write-Host '  Sem a permissao, a impressora nao foi instalada no Windows. Abra este arquivo de novo e clique em Sim.'
            }
            if (Get-Printer -Name 'QIA-8388' -ErrorAction SilentlyContinue) { Write-Host '  Impressora instalada no Windows.' }
        }
    }

    # 1. Desliga o agente que ja estiver rodando (versao antiga ou instalacao anterior)
    Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" |
        Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -match 'agente\.ps1' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Milliseconds 500

    # 2. Arquivos do agente
    New-Item -ItemType Directory -Force -Path $pasta | Out-Null
    $script = Join-Path $pasta 'agente.ps1'
    [IO.File]::WriteAllBytes($script, [Convert]::FromBase64String(__AGENTE__))
    $config = [ordered]@{ servidor = $servidor; codigo = $codigo } | ConvertTo-Json
    [IO.File]::WriteAllText((Join-Path $pasta 'config.json'), $config, (New-Object Text.UTF8Encoding($false)))
    $log = Join-Path $pasta 'agente.log'
    if (Test-Path $log) { Remove-Item $log -Force }

    # 3. Abre sozinho quando o Windows iniciar (sem janela)
    $powershell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $argumentos = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $script + '"'
    $antigo = Join-Path $inicializar 'Agente de impressao.lnk'
    if (Test-Path $antigo) { Remove-Item $antigo -Force }
    $atalho = (New-Object -ComObject WScript.Shell).CreateShortcut((Join-Path $inicializar 'Programa da impressora.lnk'))
    $atalho.TargetPath = $powershell
    $atalho.Arguments = $argumentos
    $atalho.WorkingDirectory = $pasta
    $atalho.WindowStyle = 7
    $atalho.Description = 'Imprime sozinho as comandas dos pedidos'
    $atalho.Save()

    # 4. Liga agora e confere se ele falou com o site
    Start-Process -FilePath $powershell -ArgumentList $argumentos -WorkingDirectory $pasta -WindowStyle Hidden
    $resultado = ''
    for ($i = 0; $i -lt 20 -and -not $resultado; $i++) {
        Start-Sleep -Seconds 1
        if (Test-Path $log) {
            $texto = Get-Content $log -Raw -Encoding UTF8
            if ($texto -match 'recusou o codigo') { $resultado = 'codigo' }
            elseif ($texto -match 'Sem falar com o servidor') { $resultado = 'internet' }
            elseif ($texto -match 'conectado ao servidor') { $resultado = 'ok' }
        }
    }
    Write-Host ''
    if ($resultado -eq 'codigo') {
        Write-Host '  O site recusou este arquivo: ele ficou velho porque outro foi baixado depois.'
        Write-Host '  Baixe o programa de novo no painel (tela Impressora) e abra o arquivo novo.'
        exit 1
    }
    if ($resultado -eq 'internet') {
        Write-Host '  Instalado, mas este computador nao conseguiu falar com o site.'
        Write-Host '  Confira a internet. O programa continua tentando sozinho.'
        exit 1
    }
    Write-Host '  Pronto! O programa da impressora esta ligado e vai abrir sozinho quando o computador ligar.'
    Write-Host '  Pode fechar esta janela e voltar ao painel.'
    Write-Host ''
    exit 0
} catch {
    Write-Host ''
    Write-Host ('  Nao deu para instalar: ' + $_.Exception.Message)
    exit 1
}
PS;
        $ps = strtr($ps, ['__SERVIDOR__' => $aspas($servidor), '__CODIGO__' => $aspas($codigo), '__AGENTE__' => $aspas($base64), '__DRIVER__' => $aspas($driver)]);
        $texto = $cmd . "\n" . self::MARCADOR . "\n" . $ps . "\n";
        return str_replace("\n", "\r\n", str_replace("\r\n", "\n", $texto));
    }
}
