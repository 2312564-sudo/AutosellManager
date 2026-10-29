# Tram theo doi acc - script VPS (SEAL2028 QuangQuy)
# Chay 1 lan / phut bang Task Scheduler (cai bang file cai-dat-QQ.bat tai tu web).
# Viec lam: bao web "VPS con song" + tab Minecraft nao dang chay; nhan lenh mo / tat / mo lai game qua Prism Launcher.
# Doc cau hinh chung voi mod: %USERPROFILE%\.quangquy\web.txt (url=, key=, vps=). Khong doc / gui token, mat khau.
$ErrorActionPreference = 'SilentlyContinue'
$Ver = '1.0'
$Dir = Join-Path $env:USERPROFILE '.quangquy'
$LogFile = Join-Path $Dir 'watchdog.log'

function Log($m) {
    try {
        $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + ' ' + $m
        Add-Content -Path $LogFile -Value $line -Encoding UTF8
        if ((Get-Item $LogFile).Length -gt 512KB) { Move-Item $LogFile ($LogFile + '.old') -Force }
    } catch {}
}

function Read-Cfg {
    $c = @{}
    $f = Join-Path $Dir 'web.txt'
    if (Test-Path $f) {
        foreach ($l in Get-Content $f -Encoding UTF8) {
            $t = $l.Trim()
            if ($t -eq '' -or $t.StartsWith('#')) { continue }
            $i = $t.IndexOf('=')
            if ($i -gt 0) { $c[$t.Substring(0, $i).Trim()] = $t.Substring($i + 1).Trim() }
        }
    }
    return $c
}

$cfg = Read-Cfg
if (-not $cfg['url'] -or -not $cfg['key'] -or $cfg['on'] -eq 'false') { exit }
$Url = $cfg['url'].TrimEnd('/')
$VpsName = $env:COMPUTERNAME
if ($cfg['vps']) { $VpsName = $cfg['vps'] }

# Prism Launcher + thu muc instances
$Prism = $cfg['prism']
if (-not $Prism -or -not (Test-Path $Prism)) {
    foreach ($p in @("$env:LOCALAPPDATA\Programs\PrismLauncher\prismlauncher.exe", "$env:ProgramFiles\PrismLauncher\prismlauncher.exe", "${env:ProgramFiles(x86)}\PrismLauncher\prismlauncher.exe", "$env:APPDATA\PrismLauncher\prismlauncher.exe")) {
        if (Test-Path $p) { $Prism = $p; break }
    }
}
$InstRoot = $cfg['instances']
if (-not $InstRoot -or -not (Test-Path $InstRoot)) {
    $InstRoot = "$env:APPDATA\PrismLauncher\instances"
    $pcfg = "$env:APPDATA\PrismLauncher\prismlauncher.cfg"
    if (Test-Path $pcfg) {
        foreach ($l in Get-Content $pcfg) {
            if ($l -match '^InstanceDir=(.+)$') {
                $d = $Matches[1].Trim()
                if (-not [System.IO.Path]::IsPathRooted($d)) { $d = Join-Path "$env:APPDATA\PrismLauncher" $d }
                if (Test-Path $d) { $InstRoot = $d }
            }
        }
    }
}

# Get-CimInstance (PowerShell 3+) hoac Get-WmiObject (may cu)
function Get-Wmi($class, $filter) {
    try {
        if (Get-Command Get-CimInstance -ErrorAction SilentlyContinue) {
            if ($filter) { return Get-CimInstance $class -Filter $filter -ErrorAction Stop }
            return Get-CimInstance $class -ErrorAction Stop
        }
        if ($filter) { return Get-WmiObject $class -Filter $filter }
        return Get-WmiObject $class
    } catch { return $null }
}

function Get-Instances {
    $procs = @(Get-Wmi 'Win32_Process' "Name='javaw.exe' OR Name='java.exe'")
    $list = @()
    if (-not (Test-Path $InstRoot)) { return $list }
    foreach ($d in Get-ChildItem -Path $InstRoot -Directory) {
        $game = $null
        foreach ($g in @('minecraft', '.minecraft')) { if (Test-Path (Join-Path $d.FullName $g)) { $game = Join-Path $d.FullName $g } }
        if (-not $game) { continue }
        $managed = [bool](Get-ChildItem -Path (Join-Path $game 'mods') -Filter 'QuangQuy-SEAL2028*.jar' -ErrorAction SilentlyContinue)
        $needle = ('\' + $d.Name + '\').ToLower()
        $proc = $procs | Where-Object { $_.CommandLine -and $_.CommandLine.ToLower().Contains($needle) } | Select-Object -First 1
        $procId = 0
        $ram = 0
        if ($proc) { $procId = [int]$proc.ProcessId; $ram = [int]($proc.WorkingSetSize / 1MB) }
        $list += [ordered]@{ name = $d.Name; running = [bool]$proc; managed = $managed; pid = $procId; ramMb = $ram }
    }
    return $list
}

function Post($path, $obj) {
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $json = $obj | ConvertTo-Json -Depth 6 -Compress
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
        return Invoke-RestMethod -Uri ($Url + $path) -Method Post -Body $bytes -ContentType 'application/json; charset=utf-8' -Headers @{ 'X-QQ-Key' = $cfg['key'] } -TimeoutSec 25 -UseBasicParsing
    } catch {
        Log ('Loi gui len web: ' + $_.Exception.Message)
        return $null
    }
}

function Do-Launch($inst, $server) {
    if (-not $Prism) { return @($false, 'Khong tim thay Prism Launcher (them dong prism=duong\dan\prismlauncher.exe vao web.txt)') }
    $argList = @('--launch', $inst)
    if ($server) { $argList += @('--server', $server) }
    Start-Process -FilePath $Prism -ArgumentList $argList
    Log ("Mo game: $inst -> $server")
    $txt = "Da mo $inst"
    if ($server) { $txt += " va vao $server" }
    return @($true, $txt)
}

function Do-Kill($inst) {
    $i = Get-Instances | Where-Object { $_.name -eq $inst } | Select-Object -First 1
    if (-not $i -or -not $i.running) { return @($true, "$inst dang khong chay") }
    Stop-Process -Id $i.pid -Force
    Log ("Tat game: $inst (pid " + $i.pid + ')')
    return @($true, "Da tat $inst")
}

$freeMb = 0; $totalMb = 0; $cpu = 0; $upSec = 0
try {
    $os = Get-Wmi 'Win32_OperatingSystem' $null
    $freeMb = [int]($os.FreePhysicalMemory / 1024)
    $totalMb = [int]($os.TotalVisibleMemorySize / 1024)
    $boot = $os.LastBootUpTime
    if ($boot -is [string]) { $boot = [Management.ManagementDateTimeConverter]::ToDateTime($boot) }
    if ($boot) { $upSec = [int]((Get-Date) - $boot).TotalSeconds }
} catch {}
try { $cpu = [double]((Get-Wmi 'Win32_Processor' $null | Measure-Object -Property LoadPercentage -Average).Average) } catch {}
$hostName = [string]$env:COMPUTERNAME
if (-not $VpsName) { $VpsName = $hostName }
$body = [ordered]@{
    vps = $VpsName; host = $hostName; ver = $Ver; prism = [bool]$Prism
    freeRamMb = $freeMb; totalRamMb = $totalMb; cpu = $cpu; uptimeSec = $upSec
    instances = @(Get-Instances); acks = @()
}
$res = Post '/api/vps.php' $body
if (-not $res -or -not $res.cmds) { exit }

$acks = @()
foreach ($c in $res.cmds) {
    $parts = ([string]$c.a).Split('|')
    $inst = $parts[0]
    $server = [string]$res.server
    if ($parts.Length -gt 1 -and $parts[1]) { $server = $parts[1] }
    $r = @($false, 'Lenh la')
    switch ([string]$c.t) {
        'launch' {
            $cur = Get-Instances | Where-Object { $_.name -eq $inst } | Select-Object -First 1
            if ($cur -and $cur.running) { $r = @($true, "$inst da dang chay") } else { $r = Do-Launch $inst $server }
        }
        'kill' { $r = Do-Kill $inst }
        'restart' {
            $k = Do-Kill $inst
            Start-Sleep -Seconds 6
            $r = Do-Launch $inst $server
        }
    }
    $acks += [ordered]@{ id = [int]$c.id; ok = [bool]$r[0]; msg = [string]$r[1] }
}
if ($acks.Count -gt 0) {
    Start-Sleep -Seconds 2
    $body.instances = @(Get-Instances)
    $body.acks = $acks
    [void](Post '/api/vps.php' $body)
}
