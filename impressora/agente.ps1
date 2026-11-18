# Agente de impressao (D79 a D81).
# Roda no computador onde a impressora termica esta ligada (USB). A cada poucos segundos pergunta ao servidor se ha comanda
# para imprimir e imprime sozinho: sem navegador, sem janela de impressao, com o painel fechado. Dois jeitos (painel, tela Impressora):
#   * ESC/POS direto (padrao): para impressora de cupom de 58/80 mm. A comanda vira imagem e vai crua (RAW) para a fila do
#     Windows, sem passar pelo driver; funciona ate com o driver errado instalado.
#   * Pelo driver do Windows: para impressora de etiqueta (TSPL/ZPL) ou qualquer uma com o driver certo.
# Toda a parametrizacao (impressora, jeito de enviar, papel, letra, vias) vem do painel.
#
# Instalacao normal (D86): o painel entrega um instalar-impressora.cmd ja configurado, que grava este arquivo e o config.json
# em %LOCALAPPDATA%\ImpressoraDoPainel e liga o agente escondido, abrindo sozinho com o Windows.
# Uso manual:  iniciar-impressora.bat         (na primeira vez pede o endereco do servidor e o codigo)
#       powershell -File agente.ps1 -UmaVez     (um ciclo so, para teste)
#       powershell -File agente.ps1 -UmaVez -Previa C:\pasta   (em vez de imprimir, salva cada comanda como PNG nesta pasta)
# Este arquivo fica so com caracteres ASCII: o PowerShell 5.1 le scripts sem BOM com a codificacao do Windows.
param(
    [string]$Config = (Join-Path $PSScriptRoot 'config.json'),
    [switch]$UmaVez,
    [string]$Previa = '',
    [switch]$Instalar,
    [string]$PastaAtalho = ''
)

$ErrorActionPreference = 'Stop'

# Abrir sozinho quando o Windows iniciar: um atalho (minimizado) na pasta Inicializar do usuario. Fica aqui, e nao no .bat,
# porque as aspas de um comando PowerShell dentro de um .bat se perdem (foi o que quebrou o "instalar" antigo).
if ($Instalar) {
    $pasta = $PastaAtalho
    if (-not $pasta) { $pasta = [Environment]::GetFolderPath('Startup') }
    $caminho = Join-Path $pasta 'Agente de impressao.lnk'
    $atalho = (New-Object -ComObject WScript.Shell).CreateShortcut($caminho)
    $atalho.TargetPath = Join-Path $PSScriptRoot 'iniciar-impressora.bat'
    $atalho.WorkingDirectory = $PSScriptRoot
    $atalho.WindowStyle = 7
    $atalho.Save()
    Write-Host ''
    Write-Host '  Pronto: o agente vai abrir sozinho (minimizado) quando o Windows iniciar.'
    Write-Host "  Atalho criado em: $caminho"
    Write-Host '  Para desfazer, apague o atalho "Agente de impressao" dessa pasta (Win+R, shell:startup).'
    Write-Host ''
    exit 0
}
$VERSAO = 3
$LOG = Join-Path $PSScriptRoot 'agente.log'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
Add-Type -AssemblyName System.Drawing

# Impressora de cupom (ESC/POS): a comanda vai como imagem (raster "GS v 0") direto para a fila do Windows em modo RAW,
# sem passar pelo driver. Assim funciona mesmo quando o Windows instalou um driver errado (ex.: driver de etiqueta TSPL
# numa impressora de cupom, que imprime os comandos como texto) e os acentos saem sempre, porque e imagem, nao texto.
if (-not ('Termica' -as [type])) {
    Add-Type -ReferencedAssemblies System.Drawing -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Runtime.InteropServices;

public static class Termica
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public class DOCINFO
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string pDataType;
    }

    [DllImport("winspool.drv", EntryPoint = "OpenPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool OpenPrinter(string nome, out IntPtr h, IntPtr padrao);
    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool ClosePrinter(IntPtr h);
    [DllImport("winspool.drv", EntryPoint = "StartDocPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern int StartDocPrinter(IntPtr h, int nivel, [In, MarshalAs(UnmanagedType.LPStruct)] DOCINFO doc);
    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool EndDocPrinter(IntPtr h);
    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool StartPagePrinter(IntPtr h);
    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool EndPagePrinter(IntPtr h);
    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool WritePrinter(IntPtr h, byte[] dados, int tamanho, out int escritos);

    /// Manda os bytes para a fila da impressora como estao (tipo RAW): o driver nao mexe.
    public static void Enviar(string impressora, string documento, byte[] dados)
    {
        IntPtr h;
        if (!OpenPrinter(impressora, out h, IntPtr.Zero)) throw new Win32Exception(Marshal.GetLastWin32Error(), "Nao abriu a impressora " + impressora);
        try
        {
            DOCINFO doc = new DOCINFO();
            doc.pDocName = documento;
            doc.pDataType = "RAW";
            if (StartDocPrinter(h, 1, doc) == 0) throw new Win32Exception(Marshal.GetLastWin32Error(), "A fila nao aceitou o documento");
            try
            {
                StartPagePrinter(h);
                int escritos;
                if (!WritePrinter(h, dados, dados.Length, out escritos) || escritos != dados.Length)
                    throw new Win32Exception(Marshal.GetLastWin32Error(), "Nao deu para escrever na impressora");
                EndPagePrinter(h);
            }
            finally { EndDocPrinter(h); }
        }
        finally { ClosePrinter(h); }
    }

    /// Imagem -> comando raster "GS v 0" em faixas de ate 128 linhas (impressoras pequenas tem pouca memoria).
    public static byte[] Raster(Bitmap imagem)
    {
        int largura = imagem.Width, altura = imagem.Height, bytesPorLinha = (largura + 7) / 8;
        Rectangle area = new Rectangle(0, 0, largura, altura);
        BitmapData bits = imagem.LockBits(area, ImageLockMode.ReadOnly, PixelFormat.Format32bppArgb);
        byte[] pixels = new byte[bits.Stride * altura];
        Marshal.Copy(bits.Scan0, pixels, 0, pixels.Length);
        int passo = bits.Stride;
        imagem.UnlockBits(bits);

        MemoryStream saida = new MemoryStream();
        for (int inicio = 0; inicio < altura; inicio += 128)
        {
            int linhas = Math.Min(128, altura - inicio);
            saida.Write(new byte[] { 0x1D, 0x76, 0x30, 0x00, (byte)(bytesPorLinha & 0xFF), (byte)(bytesPorLinha >> 8), (byte)(linhas & 0xFF), (byte)(linhas >> 8) }, 0, 8);
            for (int y = inicio; y < inicio + linhas; y++)
            {
                byte[] linha = new byte[bytesPorLinha];
                for (int x = 0; x < largura; x++)
                {
                    int i = y * passo + x * 4;
                    int luz = (pixels[i + 2] * 299 + pixels[i + 1] * 587 + pixels[i] * 114) / 1000;
                    if (pixels[i + 3] > 0 && luz < 128) linha[x >> 3] |= (byte)(0x80 >> (x & 7));
                }
                saida.Write(linha, 0, linha.Length);
            }
        }
        return saida.ToArray();
    }

    /// Imagem -> etiqueta TSPL (impressora de etiqueta). alturaEtiquetaMm = 0: papel continuo (GAP 0), a "etiqueta" tem a
    /// altura da comanda. No BITMAP do TSPL o bit 0 imprime (preto) e o 1 fica em branco.
    public static byte[] Tspl(Bitmap imagem, double larguraPapelMm, double alturaEtiquetaMm, int deslocamentoX)
    {
        int largura = imagem.Width, altura = imagem.Height, bytesPorLinha = (largura + 7) / 8;
        Rectangle area = new Rectangle(0, 0, largura, altura);
        BitmapData bits = imagem.LockBits(area, ImageLockMode.ReadOnly, PixelFormat.Format32bppArgb);
        byte[] pixels = new byte[bits.Stride * altura];
        Marshal.Copy(bits.Scan0, pixels, 0, pixels.Length);
        int passo = bits.Stride;
        imagem.UnlockBits(bits);

        bool continuo = alturaEtiquetaMm <= 0;
        int linhasPorEtiqueta = continuo ? altura : (int)Math.Round(alturaEtiquetaMm * 8);
        System.Globalization.CultureInfo c = System.Globalization.CultureInfo.InvariantCulture;
        MemoryStream saida = new MemoryStream();
        for (int inicio = 0; inicio < altura; inicio += linhasPorEtiqueta)
        {
            int linhas = Math.Min(linhasPorEtiqueta, altura - inicio);
            double alturaMm = continuo ? Math.Ceiling(altura / 8.0) + 2 : alturaEtiquetaMm;
            string cabecalho = "SIZE " + larguraPapelMm.ToString(c) + " mm," + alturaMm.ToString(c) + " mm\r\n"
                + (continuo ? "GAP 0 mm,0 mm\r\n" : "GAP 2 mm,0 mm\r\n")
                + "DIRECTION 1\r\nREFERENCE 0,0\r\nCLS\r\n"
                + "BITMAP " + deslocamentoX + ",0," + bytesPorLinha + "," + linhas + ",0,";
            byte[] b = System.Text.Encoding.ASCII.GetBytes(cabecalho);
            saida.Write(b, 0, b.Length);
            for (int y = inicio; y < inicio + linhas; y++)
            {
                byte[] linha = new byte[bytesPorLinha];
                for (int k = 0; k < bytesPorLinha; k++) linha[k] = 0xFF;
                for (int x = 0; x < largura; x++)
                {
                    int i = y * passo + x * 4;
                    int luz = (pixels[i + 2] * 299 + pixels[i + 1] * 587 + pixels[i] * 114) / 1000;
                    if (pixels[i + 3] > 0 && luz < 128) linha[x >> 3] &= (byte)~(0x80 >> (x & 7));
                }
                saida.Write(linha, 0, linha.Length);
            }
            b = System.Text.Encoding.ASCII.GetBytes("\r\nPRINT 1,1\r\n");
            saida.Write(b, 0, b.Length);
        }
        return saida.ToArray();
    }
}
'@
}

function Registrar([string]$texto) {
    $linha = '[{0}] {1}' -f (Get-Date -Format 'dd/MM HH:mm:ss'), $texto
    Write-Host $linha
    try {
        if ((Test-Path $LOG) -and (Get-Item $LOG).Length -gt 1MB) { Move-Item $LOG "$LOG.antigo" -Force }
        Add-Content -Path $LOG -Value $linha -Encoding UTF8
    } catch { }
}

# ---------------------------------------------------------------- configuracao (servidor + codigo do agente)
function Ler-Configuracao {
    if (Test-Path $Config) {
        $c = Get-Content $Config -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($c.servidor -and $c.codigo) { return $c }
    }
    Write-Host ''
    Write-Host 'Primeira vez: o painel mostra estes dois dados na tela Impressora (botao "Gerar codigo do agente").'
    $servidor = (Read-Host 'Endereco do servidor (ex.: https://familia-massas.onrender.com)').Trim().TrimEnd('/')
    $codigo = (Read-Host 'Codigo do agente').Trim()
    if (-not $servidor -or -not $codigo) { throw 'Endereco e codigo sao obrigatorios.' }
    $c = [pscustomobject]@{ servidor = $servidor; codigo = $codigo }
    $c | ConvertTo-Json | Set-Content -Path $Config -Encoding UTF8
    Registrar "Configuracao gravada em $Config"
    return $c
}

# ---------------------------------------------------------------- conversa com o servidor
function Chamar([string]$caminho, $corpo) {
    $req = [Net.HttpWebRequest]::Create($script:cfg.servidor.TrimEnd('/') + $caminho)
    $req.Method = 'POST'
    $req.Timeout = 20000
    $req.ReadWriteTimeout = 20000
    $req.ContentType = 'application/json; charset=utf-8'
    $req.Accept = 'application/json'
    $req.UserAgent = "AgenteImpressao/$VERSAO"
    $req.Headers.Add('X-Impressora-Codigo', $script:cfg.codigo)
    $bytes = [Text.Encoding]::UTF8.GetBytes(($corpo | ConvertTo-Json -Depth 6 -Compress))
    $req.ContentLength = $bytes.Length
    $fluxo = $req.GetRequestStream()
    $fluxo.Write($bytes, 0, $bytes.Length)
    $fluxo.Close()
    try {
        $resp = $req.GetResponse()
    } catch [Net.WebException] {
        $r = $_.Exception.Response
        if ($null -eq $r) { throw "Sem conexao com o servidor: $($_.Exception.Message)" }
        $status = [int]$r.StatusCode
        $r.Close()
        if ($status -eq 401) { throw 'CODIGO_INVALIDO' }
        throw "O servidor respondeu $status."
    }
    $leitor = New-Object IO.StreamReader($resp.GetResponseStream(), [Text.Encoding]::UTF8)
    $texto = $leitor.ReadToEnd()
    $resp.Close()
    return ($texto | ConvertFrom-Json)
}

# ---------------------------------------------------------------- impressoras do Windows (lista e estado)
$script:cacheImpressoras = $null
$script:cacheEm = [datetime]::MinValue

function Impressoras {
    if ($script:cacheImpressoras -and ((Get-Date) - $script:cacheEm).TotalSeconds -lt 20) { return $script:cacheImpressoras }
    $padrao = ''
    try { $padrao = (Get-CimInstance Win32_Printer -Filter 'Default=TRUE').Name } catch { }
    $lista = @()
    foreach ($p in (Get-Printer)) {
        $lista += [pscustomobject]@{ nome = $p.Name; status = [string]$p.PrinterStatus; padrao = ($p.Name -eq $padrao); porta = [string]$p.PortName; driver = [string]$p.DriverName }
    }
    $script:cacheImpressoras = $lista
    $script:cacheEm = Get-Date
    return $lista
}

$PROBLEMAS = @('Paused', 'Error', 'PaperJam', 'PaperOut', 'Offline', 'NotAvailable', 'UserIntervention', 'DoorOpen', 'PaperProblem', 'NoToner')

function Escolhida($ajustes, $lista) {
    foreach ($i in $lista) {
        if ($ajustes -and $ajustes.impressora) { if ($i.nome -eq $ajustes.impressora) { return $i } }
        elseif ($i.padrao) { return $i }
    }
    return $null
}

# ---------------------------------------------------------------- desenho da comanda
# Unidades em milimetros. Estilos da linha (app/Impressao/Comanda.php): loja, numero, caixa, destaque, negrito, normal,
# pequeno, linha (separador), espaco.
function Fonte([string]$estilo, [double]$base) {
    $negrito = [System.Drawing.FontStyle]::Bold
    $normal = [System.Drawing.FontStyle]::Regular
    switch ($estilo) {
        'loja'     { return New-Object System.Drawing.Font('Arial', [single]($base * 0.95), $normal) }
        'numero'   { return New-Object System.Drawing.Font('Arial', [single]($base * 3.0), $negrito) }
        'caixa'    { return New-Object System.Drawing.Font('Arial', [single]($base * 1.35), $negrito) }
        'destaque' { return New-Object System.Drawing.Font('Arial', [single]($base * 1.2), $negrito) }
        'negrito'  { return New-Object System.Drawing.Font('Arial', [single]$base, $negrito) }
        'pequeno'  { return New-Object System.Drawing.Font('Arial', [single]($base * 0.85), $normal) }
        default    { return New-Object System.Drawing.Font('Arial', [single]$base, $normal) }
    }
}

function Tamanho-Base($ajustes) {
    $base = 10.0
    if ($ajustes.largura_mm -le 58) { $base = 8.0 } elseif ($ajustes.largura_mm -ge 100) { $base = 11.0 }
    switch ($ajustes.fonte) { 'p' { $base *= 0.85 } 'g' { $base *= 1.2 } }
    return $base
}

# Altura de uma linha (mm) e, se $desenhar, desenha a partir de $y.
function Linha($g, $linha, [double]$x, [double]$y, [double]$largura, [double]$base, [bool]$desenhar) {
    $estilo = [string]$linha.e
    if ($estilo -eq 'linha') {
        if ($desenhar) {
            $caneta = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, [single]0.35)
            $caneta.DashStyle = [System.Drawing.Drawing2D.DashStyle]::Dash
            $g.DrawLine($caneta, [single]$x, [single]($y + 1.2), [single]($x + $largura), [single]($y + 1.2))
            $caneta.Dispose()
        }
        return 2.6
    }
    if ($estilo -eq 'espaco') { return 2.0 }
    $fonte = Fonte $estilo $base
    $formato = New-Object System.Drawing.StringFormat
    if ($linha.a -eq 'c') { $formato.Alignment = [System.Drawing.StringAlignment]::Center }
    $texto = [string]$linha.t
    $direita = [string]$linha.d
    $larguraTexto = $largura
    $folga = 0.0
    if ($estilo -eq 'caixa') { $folga = 1.2 }
    if ($direita) {
        $tamDireita = $g.MeasureString($direita, $fonte)
        $larguraTexto = [Math]::Max(10.0, $largura - $tamDireita.Width - 1.5)
    }
    $tam = $g.MeasureString($texto, $fonte, [System.Drawing.SizeF]::new([single]($larguraTexto - 2 * $folga), [single]1000), $formato)
    $altura = [double]$tam.Height + 2 * $folga
    if ($desenhar) {
        $area = New-Object System.Drawing.RectangleF([single]($x + $folga), [single]($y + $folga), [single]($larguraTexto - 2 * $folga), [single]($altura - 2 * $folga))
        $g.DrawString($texto, $fonte, [System.Drawing.Brushes]::Black, $area, $formato)
        if ($direita) {
            $formatoD = New-Object System.Drawing.StringFormat
            $formatoD.Alignment = [System.Drawing.StringAlignment]::Far
            $g.DrawString($direita, $fonte, [System.Drawing.Brushes]::Black, (New-Object System.Drawing.RectangleF([single]$x, [single]$y, [single]$largura, [single]$altura)), $formatoD)
        }
        if ($estilo -eq 'caixa') {
            $caneta = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, [single]0.5)
            $g.DrawRectangle($caneta, [single]$x, [single]$y, [single]$largura, [single]$altura)
            $caneta.Dispose()
        }
    }
    $fonte.Dispose()
    $espaco = 0.6
    if ($estilo -eq 'numero' -or $estilo -eq 'caixa') { $espaco = 1.5 }
    return $altura + $espaco
}

function Altura-Total($linhas, $ajustes) {
    $bmp = New-Object System.Drawing.Bitmap(10, 10)
    $bmp.SetResolution(203, 203)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter
    $util = $ajustes.largura_mm - 2 * $ajustes.margem_mm
    $base = Tamanho-Base $ajustes
    $total = 0.0
    foreach ($l in $linhas) { $total += Linha $g $l 0 0 $util $base $false }
    $g.Dispose()
    $bmp.Dispose()
    return $total
}

# Pontos do cabecote (8 por mm) de cada largura de rolo: 58 mm -> 384, 80 mm -> 576 (a area util e menor que o papel).
function Pontos-Do-Papel([int]$larguraMm) {
    switch ($larguraMm) { 58 { return 384 } 72 { return 512 } 80 { return 576 } default { return [int]($larguraMm * 8) } }
}

# Comanda inteira numa imagem so, do tamanho exato do cabecote (rolo continuo: a altura e a do conteudo).
function Imagem-Continua($trabalho, $ajustes) {
    $pontos = Pontos-Do-Papel $ajustes.largura_mm
    $aj = [pscustomobject]@{ largura_mm = $pontos / 8.0; margem_mm = [double]$ajustes.margem_mm; fonte = $ajustes.fonte }
    $altura = (Altura-Total $trabalho.linhas $aj) + 2 * $aj.margem_mm + 2
    $bmp = New-Object System.Drawing.Bitmap($pontos, [int][Math]::Ceiling($altura * 8))
    $bmp.SetResolution(203.2, 203.2)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.Clear([System.Drawing.Color]::White)
    $g.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter
    $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::SingleBitPerPixelGridFit
    $util = $aj.largura_mm - 2 * $aj.margem_mm
    $base = Tamanho-Base $aj
    $y = $aj.margem_mm
    foreach ($l in @($trabalho.linhas)) { $y += Linha $g $l $aj.margem_mm $y $util $base $true }
    $g.Dispose()
    return $bmp
}

# Cupom ESC/POS: inicia, manda a imagem, avanca o papel e corta (a impressora sem guilhotina ignora o corte).
function Imprimir-EscPos($trabalho, $ajustes, [string]$documento) {
    $impressora = [string]$ajustes.impressora
    if (-not $impressora) { $impressora = (New-Object System.Drawing.Printing.PrinterSettings).PrinterName }
    $bmp = Imagem-Continua $trabalho $ajustes
    $raster = [Termica]::Raster($bmp)
    $bmp.Dispose()
    $dados = New-Object IO.MemoryStream
    $inicio = [byte[]](0x1B, 0x40)                                  # ESC @  (reinicia a impressora)
    $fim = [byte[]](0x1B, 0x64, 0x05, 0x1D, 0x56, 0x42, 0x00)       # ESC d 5 (avanca 5 linhas) + GS V B (corta)
    $vias = [Math]::Max(1, [int]$ajustes.vias)
    for ($i = 0; $i -lt $vias; $i++) {
        $dados.Write($inicio, 0, $inicio.Length)
        $dados.Write($raster, 0, $raster.Length)
        $dados.Write($fim, 0, $fim.Length)
    }
    [Termica]::Enviar($impressora, $documento, $dados.ToArray())
}

# Etiqueta TSPL direto: a mesma imagem do cupom, centralizada no papel; rolo continuo (altura 0) ou etiquetas da altura dada.
function Imprimir-Tspl($trabalho, $ajustes, [string]$documento) {
    $impressora = [string]$ajustes.impressora
    if (-not $impressora) { $impressora = (New-Object System.Drawing.Printing.PrinterSettings).PrinterName }
    $bmp = Imagem-Continua $trabalho $ajustes
    $deslocamento = [Math]::Max(0, [int](($ajustes.largura_mm * 8 - $bmp.Width) / 2))
    $etiqueta = [Termica]::Tspl($bmp, [double]$ajustes.largura_mm, [double]$ajustes.altura_mm, $deslocamento)
    $bmp.Dispose()
    $dados = New-Object IO.MemoryStream
    $vias = [Math]::Max(1, [int]$ajustes.vias)
    for ($i = 0; $i -lt $vias; $i++) { $dados.Write($etiqueta, 0, $etiqueta.Length) }
    [Termica]::Enviar($impressora, $documento, $dados.ToArray())
}

# Mesmo desenho da impressao, numa imagem de 203 dpi (a resolucao das termicas): uma PNG por pagina/etiqueta.
function Salvar-Previa($trabalho, $ajustes) {
    if ($ajustes.linguagem -ne 'driver') {
        $arquivo = Join-Path $Previa ('comanda-{0}.png' -f $trabalho.id)
        $bmp = Imagem-Continua $trabalho $ajustes
        $bmp.Save($arquivo, [System.Drawing.Imaging.ImageFormat]::Png)
        $bmp.Dispose()
        return @($arquivo)
    }
    $altura = [double]$ajustes.altura_mm
    if ($altura -le 0) { $altura = (Altura-Total $trabalho.linhas $ajustes) + 2 * $ajustes.margem_mm + 6 }
    $px = { param($mm) [int][Math]::Ceiling($mm / 25.4 * 203) }
    $linhas = @($trabalho.linhas)
    $margem = [double]$ajustes.margem_mm
    $util = $ajustes.largura_mm - 2 * $margem
    $base = Tamanho-Base $ajustes
    $proxima = 0
    $pagina = 1
    $arquivos = @()
    while ($proxima -lt $linhas.Count) {
        $bmp = New-Object System.Drawing.Bitmap((& $px $ajustes.largura_mm), (& $px $altura))
        $bmp.SetResolution(203, 203)
        $g = [System.Drawing.Graphics]::FromImage($bmp)
        $g.Clear([System.Drawing.Color]::White)
        $g.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter
        $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::SingleBitPerPixelGridFit
        $y = $margem
        $naPagina = 0
        while ($proxima -lt $linhas.Count) {
            $h = Linha $g $linhas[$proxima] $margem $y $util $base $false
            if ($naPagina -gt 0 -and $y + $h -gt $altura - $margem) { break }
            [void](Linha $g $linhas[$proxima] $margem $y $util $base $true)
            $y += $h; $naPagina++; $proxima++
        }
        $g.Dispose()
        $arquivo = Join-Path $Previa ('comanda-{0}-{1}.png' -f $trabalho.id, $pagina)
        $bmp.Save($arquivo, [System.Drawing.Imaging.ImageFormat]::Png)
        $bmp.Dispose()
        $arquivos += $arquivo
        $pagina++
    }
    return $arquivos
}

function Imprimir($trabalho, $ajustes) {
    if ($Previa) {
        $salvos = Salvar-Previa $trabalho $ajustes
        Registrar ("Previa salva: " + ($salvos -join ', '))
        return
    }
    $documento = 'Comanda de teste'
    if ($trabalho.pedido_id) { $documento = "Comanda #$($trabalho.pedido_id)" }
    # "tspl": impressora de etiqueta falando TSPL direto (serve para rolo continuo, que o driver de etiqueta nao entende).
    if ($ajustes.linguagem -eq 'tspl') {
        Imprimir-Tspl $trabalho $ajustes $documento
        return
    }
    # Padrao: ESC/POS direto (impressora de cupom). "driver": pelo driver do Windows (etiqueta ou impressora com driver proprio).
    if ($ajustes.linguagem -ne 'driver') {
        Imprimir-EscPos $trabalho $ajustes $documento
        return
    }
    $doc = New-Object System.Drawing.Printing.PrintDocument
    if ($ajustes.impressora) { $doc.PrinterSettings.PrinterName = $ajustes.impressora }
    if (-not $doc.PrinterSettings.IsValid) { throw "A impressora '$($doc.PrinterSettings.PrinterName)' nao existe neste computador." }
    $nome = 'Comanda de teste'
    if ($trabalho.pedido_id) { $nome = "Comanda #$($trabalho.pedido_id)" }
    $doc.DocumentName = $nome
    $doc.PrintController = New-Object System.Drawing.Printing.StandardPrintController   # sem a janela "Imprimindo..."
    $doc.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins(0, 0, 0, 0)
    $doc.OriginAtMargins = $false

    # Papel: etiqueta usa o tamanho do driver mais parecido; rolo continuo usa a altura do conteudo.
    $paraCentesimos = { param($mm) [int][Math]::Round($mm / 25.4 * 100) }
    $altura = [double]$ajustes.altura_mm
    if ($altura -le 0) { $altura = (Altura-Total $trabalho.linhas $ajustes) + 2 * $ajustes.margem_mm + 6 }
    $papel = $null
    if ($ajustes.altura_mm -gt 0) {
        foreach ($p in $doc.PrinterSettings.PaperSizes) {
            $w = $p.Width * 0.254; $h = $p.Height * 0.254
            if ([Math]::Abs($w - $ajustes.largura_mm) -le 3 -and [Math]::Abs($h - $ajustes.altura_mm) -le 3) { $papel = $p; break }
        }
    }
    if ($null -eq $papel) { $papel = New-Object System.Drawing.Printing.PaperSize('Comanda', (& $paraCentesimos $ajustes.largura_mm), (& $paraCentesimos $altura)) }
    $doc.DefaultPageSettings.PaperSize = $papel

    $script:linhasDoTrabalho = @($trabalho.linhas)
    $script:proxima = 0
    $script:ajustesDoTrabalho = $ajustes
    $doc.add_PrintPage({
        param($origem, $e)
        $g = $e.Graphics
        $g.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter
        $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::SingleBitPerPixelGridFit   # termica: preto puro, sem cinza
        $aj = $script:ajustesDoTrabalho
        $margem = [double]$aj.margem_mm
        $util = $aj.largura_mm - 2 * $margem
        $limite = $e.PageSettings.PaperSize.Height * 0.254 - $margem
        $base = Tamanho-Base $aj
        $y = $margem
        $naPagina = 0
        while ($script:proxima -lt $script:linhasDoTrabalho.Count) {
            $l = $script:linhasDoTrabalho[$script:proxima]
            $h = Linha $g $l $margem $y $util $base $false
            if ($naPagina -gt 0 -and $y + $h -gt $limite) { break }   # nao cabe: vai para a proxima etiqueta
            [void](Linha $g $l $margem $y $util $base $true)
            $y += $h
            $naPagina++
            $script:proxima++
        }
        $e.HasMorePages = $script:proxima -lt $script:linhasDoTrabalho.Count
    })
    $vias = [Math]::Max(1, [int]$ajustes.vias)
    for ($i = 0; $i -lt $vias; $i++) {
        $script:proxima = 0
        $doc.Print()
    }
    $doc.Dispose()
}

# ---------------------------------------------------------------- laco principal
$mutex = New-Object System.Threading.Mutex($false, 'Local\AgenteImpressaoFamiliaMassas')
# Agente anterior fechado a forca (o instalador faz isso): a trava fica "abandonada" e o .NET avisa com erro, mas ela ja e nossa.
$livre = $false
try { $livre = $mutex.WaitOne(0) } catch [System.Threading.AbandonedMutexException] { $livre = $true }
if (-not $livre) {
    Write-Host 'O agente de impressao ja esta aberto neste computador. Esta janela pode ser fechada.'
    exit 1
}

$script:cfg = Ler-Configuracao
Registrar "Agente de impressao v$VERSAO ligado. Servidor: $($script:cfg.servidor)"
$ultimos = $null
$intervalo = 3
$falhas = 0
$conectou = $false
$avisouProblema = ''
while ($true) {
    try {
        $lista = Impressoras
        $alvo = Escolhida $ultimos $lista
        $pronta = $true
        if ($alvo -and ($PROBLEMAS -contains $alvo.status)) { $pronta = $false }
        $problema = ''
        if ($alvo -and -not $pronta) { $problema = "$($alvo.nome): $($alvo.status)" }
        if ($problema -ne $avisouProblema) {
            if ($problema) { Registrar "Impressora com problema ($problema). As comandas esperam na fila." } elseif ($avisouProblema) { Registrar 'Impressora pronta de novo.' }
            $avisouProblema = $problema
        }

        $r = Chamar '/api/impressora/ciclo' @{ computador = $env:COMPUTERNAME; versao = $VERSAO; impressoras = @($lista); pronta = $pronta }
        if (-not $conectou) { Registrar 'Agente conectado ao servidor.'; $conectou = $true }
        elseif ($falhas -gt 0) { Registrar 'Conexao com o servidor de volta.' }
        $falhas = 0
        $ultimos = $r.ajustes
        if ($r.intervalo_s) { $intervalo = [int]$r.intervalo_s }
        foreach ($t in @($r.trabalhos)) {
            if ($null -eq $t) { continue }
            $rotulo = 'teste'
            if ($t.pedido_id) { $rotulo = "pedido #$($t.pedido_id)" }
            try {
                Imprimir $t $r.ajustes
                $script:cacheImpressoras = $null   # o estado da fila pode ter mudado
                [void](Chamar '/api/impressora/resultado' @{ trabalho = $t.id; ok = $true })
                Registrar "Comanda impressa ($rotulo, $($t.tipo))."
            } catch {
                $erro = $_.Exception.Message
                Registrar "Falhou a comanda ($rotulo): $erro"
                try { [void](Chamar '/api/impressora/resultado' @{ trabalho = $t.id; ok = $false; erro = $erro }) } catch { }
            }
        }
    } catch {
        $falhas++
        if ($_.Exception.Message -eq 'CODIGO_INVALIDO') {
            if ($falhas -eq 1 -or $falhas % 20 -eq 0) { Registrar 'O servidor recusou o codigo do agente. Baixe o programa da impressora de novo no painel (tela Impressora).' }
            $intervalo = 30
            # Instalaram por cima: o config.json novo vale sem reiniciar.
            try { $novo = Get-Content $Config -Raw -Encoding UTF8 | ConvertFrom-Json; if ($novo.codigo -and $novo.codigo -ne $script:cfg.codigo) { $script:cfg = $novo; $falhas = 0 } } catch { }
        } else {
            if ($falhas -eq 1 -or $falhas % 20 -eq 0) { Registrar "Sem falar com o servidor: $($_.Exception.Message)" }
            $intervalo = [Math]::Min(30, 3 * $falhas)
        }
    }
    if ($UmaVez) { break }
    Start-Sleep -Seconds $intervalo
}
$mutex.ReleaseMutex()
