<?php
/* Tải file cài đặt cho VPS: cai-dat-QQ.bat (đã gắn sẵn địa chỉ web + key). Chạy 1 lần trên mỗi VPS. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';

qq_require_user('owner');
$url = qq_base_url();
$key = qq_api_key();
$ps1 = (string)file_get_contents(QQ_ROOT . '/watchdog/qq-watchdog.ps1');
$b64 = str_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $ps1))), 64);

$L = [];
$L[] = '@echo off';
$L[] = 'setlocal';
$L[] = 'title Cai dat Tram theo doi acc';
$L[] = 'set "QQ=%USERPROFILE%\.quangquy"';
$L[] = 'if not exist "%QQ%" mkdir "%QQ%"';
$L[] = 'echo.';
$L[] = 'echo  === Cai dat ket noi web Tram theo doi acc ===';
$L[] = 'echo  Web: ' . $url;
$L[] = 'echo.';
$L[] = 'set "VPSNAME=%COMPUTERNAME%"';
$L[] = 'set /p "VPSNAME=Ten VPS hien tren web (Enter = %COMPUTERNAME%): "';
$L[] = 'if "%VPSNAME%"=="" set "VPSNAME=%COMPUTERNAME%"';
$L[] = '> "%QQ%\web.txt" echo # Web quan ly acc - tao boi cai-dat-QQ.bat. Mod SEAL2028 R491 va script VPS cung doc file nay.';
$L[] = '>> "%QQ%\web.txt" echo url=' . $url;
$L[] = '>> "%QQ%\web.txt" echo key=' . $key;
$L[] = '>> "%QQ%\web.txt" echo vps=%VPSNAME%';
$L[] = '>> "%QQ%\web.txt" echo every=30';
$L[] = '> "%QQ%\w.b64" echo -----BEGIN CERTIFICATE-----';
foreach ($b64 as $line) {
    $L[] = '>> "%QQ%\w.b64" echo ' . $line;
}
$L[] = '>> "%QQ%\w.b64" echo -----END CERTIFICATE-----';
$L[] = 'certutil -f -decode "%QQ%\w.b64" "%QQ%\qq-watchdog.ps1" >nul';
$L[] = 'del "%QQ%\w.b64" >nul 2>&1';
$L[] = '> "%QQ%\qq-watchdog.vbs" echo CreateObject("WScript.Shell").Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""%QQ%\qq-watchdog.ps1""", 0, False';
$L[] = 'schtasks /Create /F /TN "QQ Watchdog" /SC MINUTE /MO 1 /TR "wscript.exe \"%QQ%\qq-watchdog.vbs\"" >nul';
$L[] = 'if errorlevel 1 (';
$L[] = '  echo  [LOI] Khong tao duoc lich chay Task Scheduler. Chuot phai file nay ^> Run as administrator roi chay lai.';
$L[] = ') else (';
$L[] = '  echo  [OK] Da luu ket noi vao %QQ%\web.txt';
$L[] = '  echo  [OK] Script VPS se tu chay moi 1 phut.';
$L[] = ')';
$L[] = 'wscript.exe "%QQ%\qq-watchdog.vbs"';
$L[] = 'echo.';
$L[] = 'echo  Xong. Mod R491 trong cac tab Minecraft se tu doc ket noi trong vong 1 phut.';
$L[] = 'echo  Mo web ^> trang VPS de kiem tra.';
$L[] = 'echo.';
$L[] = 'pause';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="cai-dat-QQ.bat"');
header('Cache-Control: no-store');
echo implode("\r\n", $L) . "\r\n";
