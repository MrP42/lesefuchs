<#
.SYNOPSIS
    Automatisierter Teil der Lesefuchs-Abnahme (Punkte 1 und 2a/2b/2c).

.DESCRIPTION
    Installiert das Debug-APK, schiebt Testbild und Demo-Paket aufs Gerät,
    startet die SpikeActivity im Autorun-Modus und wertet die
    SPIKE_RESULT-Zeilen aus dem Logcat aus.

    Die Punkte 3-6 der Checkliste (Player, Lead-Offset, Wort-Tap,
    Kind-Beobachtung) bleiben manuell - sie brauchen Auge und Ohr.

.EXAMPLE
    .\abnahme.ps1
    .\abnahme.ps1 -SkipInstall -TimeoutSeconds 300
#>
[CmdletBinding()]
param(
    [string]$Adb = "$env:USERPROFILE\tools\android\sdk\platform-tools\adb.exe",
    [string]$Apk,
    [string]$TestImage,
    [string]$ExpectedText,
    [string]$Package,
    [switch]$SkipInstall,
    [int]$TimeoutSeconds = 240
)

$ErrorActionPreference = 'Stop'
$appId = 'de.lesefuchs.spike'

# $PSScriptRoot ist in param()-Defaults nicht zuverlaessig gefuellt (je nach
# Aufrufart) -> Pfade hier aufloesen.
$root = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
if (-not $Apk)          { $Apk = Join-Path $root 'app\build\outputs\apk\debug\app-debug.apk' }
if (-not $TestImage)    { $TestImage = Join-Path $root 'testdata\seite.png' }
if (-not $ExpectedText) { $ExpectedText = Join-Path $root 'testdata\seite.txt' }
if (-not $Package)      { $Package = Join-Path $root '..\worker\out\finn-fuchs-und-der-sternenwald_v1.lesepaket' }

function Step($text) { Write-Host "`n=== $text" -ForegroundColor Cyan }
function Ok($text)   { Write-Host "  OK   $text" -ForegroundColor Green }
function Warn($text) { Write-Host "  WARN $text" -ForegroundColor Yellow }
function Fail($text) { Write-Host "  FAIL $text" -ForegroundColor Red }

if (-not (Test-Path $Adb)) { throw "adb nicht gefunden: $Adb" }

# --- Gerät -----------------------------------------------------------------
Step "Geraet suchen"
# @(...) erzwingt ein Array: bei genau einem Geraet liefert $devices[0] sonst
# das erste ZEICHEN der Zeile statt der Zeile.
$devices = @(& $Adb devices | Select-Object -Skip 1 | Where-Object { $_ -match '\tdevice$' })
if ($devices.Count -eq 0) {
    Fail "Kein Geraet verbunden. USB-Debugging am Fire Tablet aktivieren:"
    Write-Host "       Einstellungen -> Geraeteoptionen -> 7x auf Seriennummer tippen,"
    Write-Host "       danach Entwickleroptionen -> USB-Debugging."
    exit 2
}
$serial = ($devices[0] -split '\t')[0]
function Prop($name) { (& $Adb -s $serial shell getprop $name | Out-String).Trim() }
$model = Prop 'ro.product.model'
$fireOs = Prop 'ro.build.version.fireos'
$release = Prop 'ro.build.version.release'
$sdk = Prop 'ro.build.version.sdk'
$abi = Prop 'ro.product.cpu.abi'
$isEmulator = ($serial -like 'emulator-*') -or ((Prop 'ro.kernel.qemu') -eq '1') -or ($model -like '*sdk*')
$osLabel = if ($fireOs) { "Fire OS $fireOs" } else { "Android $release" }
Ok "$model (Serial $serial, $osLabel, SDK $sdk, ABI $abi)"

if ($isEmulator) {
    Warn "EMULATOR erkannt - Vorlauf, KEINE Abnahme."
    Warn "  Nicht verwertbar: TTS-Latenz/RTF/RAM (Host-CPU), Lead-Offset (andere Puffer-Latenz)."
    $gms = @(& $Adb -s $serial shell pm list packages | Where-Object { $_ -match 'com\.google\.android\.gms$' })
    if ($gms.Count -gt 0) {
        Warn "  Play Services im Image -> 2a taugt NICHT als GMS-Freiheitsnachweis."
    }
}

# --- 1 · Installation ------------------------------------------------------
Step "1 - APK installieren"
if ($SkipInstall) {
    Warn "uebersprungen (-SkipInstall)"
} else {
    if (-not (Test-Path $Apk)) { throw "APK fehlt: $Apk  (zuerst: gradlew.bat :app:assembleDebug)" }
    $apkMb = [math]::Round((Get-Item $Apk).Length / 1MB, 1)
    $out = & $Adb -s $serial install -r $Apk 2>&1 | Out-String
    if ($out -match 'Success') { Ok "installiert ($apkMb MB)" }
    else { Fail $out.Trim(); exit 3 }
}

# --- Testdaten aufs Gerät --------------------------------------------------
Step "Testdaten uebertragen"
& $Adb -s $serial shell mkdir -p /sdcard/Lesefuchs/test /sdcard/Lesefuchs/inbox | Out-Null
& $Adb -s $serial push $TestImage /sdcard/Lesefuchs/test/seite.png | Out-Null
& $Adb -s $serial push $ExpectedText /sdcard/Lesefuchs/test/seite.txt | Out-Null
Ok "Testbild + Erwartungstext nach /sdcard/Lesefuchs/test/"
if (Test-Path $Package) {
    & $Adb -s $serial push $Package /sdcard/Lesefuchs/inbox/ | Out-Null
    Ok "Demo-Paket nach /sdcard/Lesefuchs/inbox/ (fuer den manuellen Teil 3-6)"
} else {
    Warn "Demo-Paket nicht gefunden: $Package"
}
# Ohne diese Freigabe liest die App nur ihren App-eigenen Ordner
& $Adb -s $serial shell appops set $appId MANAGE_EXTERNAL_STORAGE allow 2>&1 | Out-Null

# --- 2 · Autorun-Spike -----------------------------------------------------
Step "2 - SpikeActivity im Autorun (2a OCR, 2b TTS, 2c LockTask)"
& $Adb -s $serial logcat -c
& $Adb -s $serial shell am force-stop $appId | Out-Null
& $Adb -s $serial shell am start -n "$appId/.SpikeActivity" `
    --ez autorun true `
    --es ocr_image /sdcard/Lesefuchs/test/seite.png | Out-Null

Write-Host "  laeuft (Modell-Entpacken beim ersten Start dauert)..." -NoNewline
$deadline = (Get-Date).AddSeconds($TimeoutSeconds)
$lines = @()
while ((Get-Date) -lt $deadline) {
    Start-Sleep -Seconds 5
    Write-Host "." -NoNewline
    $lines = & $Adb -s $serial logcat -d -s LesefuchsSpike
    if ($lines -match 'SPIKE_RUN done') { break }
}
Write-Host ""

$results = $lines | Select-String -Pattern 'SPIKE_RESULT .*' | ForEach-Object { $_.Matches[0].Value }
if (-not $results) {
    Fail "Keine SPIKE_RESULT-Zeilen im Logcat. Vollstaendiges Log:"
    $lines | Select-Object -Last 40 | ForEach-Object { Write-Host "    $_" }
    exit 4
}

Step "Ergebnisse (Werte in ABNAHME.md eintragen)"
foreach ($r in $results) {
    if ($r -match 'status=OK') { Ok $r } else { Fail $r }
}

# --- Zusammenfassung -------------------------------------------------------
Step "Zusammenfassung"
$stamp = Get-Date -Format 'yyyy-MM-dd HH:mm'
$report = @("# Autorun-Ergebnis $stamp", "Geraet: $model (Fire OS '$fireOs', SDK $sdk)", "") + $results
$reportPath = Join-Path $root 'abnahme-ergebnis.txt'
$report | Set-Content -Path $reportPath -Encoding UTF8
Ok "Protokoll: $reportPath"

Write-Host ""
Write-Host "Naechste Schritte (manuell, siehe ABNAHME.md):" -ForegroundColor Cyan
Write-Host "  3 - App oeffnen, Kapitel 1 vollstaendig hoeren, Highlight+Drift pruefen"
Write-Host "  4 - Lead-Offset kalibrieren und in README.md eintragen"
Write-Host "  5 - Wort-Tap-Seek pruefen"
Write-Host "  6 - Kind draufschauen lassen"

if ($results -match 'status=FAIL') { exit 1 } else { exit 0 }
