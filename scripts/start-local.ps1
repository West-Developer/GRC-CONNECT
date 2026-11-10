[CmdletBinding()]
param(
    [switch] $NoAutoStart,
    [switch] $PredictionOnly,
    # Build once and serve the optimised Next.js bundle instead of `next dev`
    # (minified, code-split, no on-demand compile). Slower to start, much faster
    # to use: the right choice for a demo, a phone on the LAN, or performance checks.
    [switch] $Production,
    [ValidateRange(1, 120)]
    [int] $ReadinessAttempts = 30,
    [ValidateRange(0, 60000)]
    [int] $ReadinessDelayMilliseconds = 1000
)

Set-StrictMode -Version Latest

$script:LauncherRoot = Split-Path -Parent $PSScriptRoot
$script:PredictionHealthUri = 'http://127.0.0.1:8100/internal/v1/health'

function Get-LocalLauncherPaths {
    $artifactsPath = Join-Path $script:LauncherRoot 'artifacts\local-dev'

    return [pscustomobject]@{
        ArtifactsPath = $artifactsPath
        BackendPath = Join-Path $script:LauncherRoot 'backend'
        FrontendPath = Join-Path $script:LauncherRoot 'frontend'
        MlServicePath = Join-Path $script:LauncherRoot 'ml-service'
        PredictionErrorLog = Join-Path $artifactsPath 'prediction-service.error.log'
        PredictionOutputLog = Join-Path $artifactsPath 'prediction-service.output.log'
        ApiErrorLog = Join-Path $artifactsPath 'api.error.log'
        ApiOutputLog = Join-Path $artifactsPath 'api.output.log'
        FrontendErrorLog = Join-Path $artifactsPath 'frontend.error.log'
        FrontendOutputLog = Join-Path $artifactsPath 'frontend.output.log'
        PythonPath = Join-Path $script:LauncherRoot 'ml-service\.venv\Scripts\python.exe'
    }
}

function Assert-LocalLauncherPrerequisites {
    param(
        [Parameter(Mandatory)]
        [pscustomobject] $Paths,
        [switch] $PredictionOnly
    )

    if (-not (Test-Path -LiteralPath $Paths.PythonPath -PathType Leaf)) {
        throw "Prediction-service interpreter not found at $($Paths.PythonPath). Run ml-service/.venv/Scripts/python.exe -m pip install -r requirements-dev.txt after completing the ML-service setup."
    }

    if (-not $PredictionOnly) {
        foreach ($path in @($Paths.BackendPath, $Paths.FrontendPath, $Paths.MlServicePath)) {
            if (-not (Test-Path -LiteralPath $path -PathType Container)) {
                throw "Required local service directory is missing: $path"
            }
        }

        try {
            Get-Command 'php.exe' -ErrorAction Stop | Out-Null
            Get-Command 'npm.cmd' -ErrorAction Stop | Out-Null
        } catch {
            throw 'The local stack requires php.exe and npm.cmd on PATH. Start the services individually after correcting PATH, or run -PredictionOnly for the ML service.'
        }
    }
}

function Get-ListeningProcessId {
    param(
        [ValidateRange(1, 65535)]
        [int] $Port
    )

    $connection = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue |
        Select-Object -First 1

    if ($null -eq $connection) {
        return 0
    }

    return [int] $connection.OwningProcess
}

function Test-PredictionServiceHealth {
    param(
        [string] $HealthUri = $script:PredictionHealthUri
    )

    try {
        $response = Invoke-RestMethod -Method Get -Uri $HealthUri -TimeoutSec 2 -ErrorAction Stop

        return $response.data.service -eq 'grc-prediction-service' -and
            $response.data.status -eq 'ok'
    } catch {
        return $false
    }
}

function Start-PredictionService {
    param(
        [scriptblock] $HealthProbe = { Test-PredictionServiceHealth },
        [scriptblock] $PortProbe = { param([int] $Port) Get-ListeningProcessId -Port $Port },
        [scriptblock] $ProcessStarter,
        [ValidateRange(1, 120)]
        [int] $ReadinessAttempts = 30,
        [ValidateRange(0, 60000)]
        [int] $ReadinessDelayMilliseconds = 1000
    )

    if (& $HealthProbe) {
        return [pscustomobject]@{
            ProcessId = 0
            State = 'reused'
        }
    }

    $owner = & $PortProbe 8100
    if ($owner -gt 0) {
        throw "Port 8100 is already used by process $owner."
    }

    if ($null -eq $ProcessStarter) {
        throw 'No prediction-service process starter was supplied.'
    }

    $process = & $ProcessStarter
    if ($null -eq $process -or $null -eq $process.Id) {
        throw 'The prediction-service process did not start.'
    }

    for ($attempt = 1; $attempt -le $ReadinessAttempts; $attempt += 1) {
        if (& $HealthProbe) {
            return [pscustomobject]@{
                ProcessId = [int] $process.Id
                State = 'started'
            }
        }

        Start-Sleep -Milliseconds $ReadinessDelayMilliseconds
    }

    throw 'The prediction service did not become healthy. Run ml-service/.venv/Scripts/python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8100 for diagnostics.'
}

function Get-FrontendLaunchPlan {
    param(
        [switch] $Production
    )

    if ($Production) {
        return [pscustomobject]@{
            BuildArguments = @('run', 'build')
            RunArguments = @('run', 'start', '--', '--hostname', '0.0.0.0')
        }
    }

    return [pscustomobject]@{
        BuildArguments = $null
        RunArguments = @('run', 'dev', '--', '--hostname', '0.0.0.0')
    }
}

function Start-LocalStack {
    param(
        [switch] $PredictionOnly,
        [switch] $Production,
        [scriptblock] $PortProbe = { param([int] $Port) Get-ListeningProcessId -Port $Port },
        [scriptblock] $ProcessStarter,
        [ValidateRange(1, 120)]
        [int] $ReadinessAttempts = 30,
        [ValidateRange(0, 60000)]
        [int] $ReadinessDelayMilliseconds = 1000
    )

    $paths = Get-LocalLauncherPaths

    if (-not $PredictionOnly) {
        foreach ($port in @(8000, 3000)) {
            $owner = & $PortProbe $port
            if ($owner -gt 0) {
                throw "Port $port is already used by process $owner."
            }
        }
    }

    Assert-LocalLauncherPrerequisites -Paths $paths -PredictionOnly:$PredictionOnly
    New-Item -ItemType Directory -Force -Path $paths.ArtifactsPath | Out-Null

    if ($null -eq $ProcessStarter) {
        $ProcessStarter = {
            Start-Process -FilePath $paths.PythonPath `
                -ArgumentList '-m', 'uvicorn', 'app.main:app', '--host', '127.0.0.1', '--port', '8100' `
                -WorkingDirectory $paths.MlServicePath `
                -WindowStyle Hidden `
                -PassThru `
                -RedirectStandardOutput $paths.PredictionOutputLog `
                -RedirectStandardError $paths.PredictionErrorLog
        }
    }

    $prediction = Start-PredictionService `
        -PortProbe $PortProbe `
        -ProcessStarter $ProcessStarter `
        -ReadinessAttempts $ReadinessAttempts `
        -ReadinessDelayMilliseconds $ReadinessDelayMilliseconds

    if (-not $PredictionOnly) {
        $phpCommand = Get-Command 'php.exe' -ErrorAction Stop
        $npmCommand = Get-Command 'npm.cmd' -ErrorAction Stop

        $api = Start-Process -FilePath $phpCommand.Source `
            -ArgumentList 'artisan', 'serve', '--host=0.0.0.0', '--port=8000' `
            -WorkingDirectory $paths.BackendPath `
            -WindowStyle Hidden `
            -PassThru `
            -RedirectStandardOutput $paths.ApiOutputLog `
            -RedirectStandardError $paths.ApiErrorLog

        $frontendPlan = Get-FrontendLaunchPlan -Production:$Production

        if ($null -ne $frontendPlan.BuildArguments) {
            Write-Host 'Building the production frontend (a few minutes; output in frontend.output.log)...'
            $build = Start-Process -FilePath $npmCommand.Source `
                -ArgumentList $frontendPlan.BuildArguments `
                -WorkingDirectory $paths.FrontendPath `
                -WindowStyle Hidden `
                -PassThru `
                -Wait `
                -RedirectStandardOutput $paths.FrontendOutputLog `
                -RedirectStandardError $paths.FrontendErrorLog

            if ($build.ExitCode -ne 0) {
                throw "The production frontend build failed (exit code $($build.ExitCode)). See $($paths.FrontendErrorLog)."
            }
        }

        $frontend = Start-Process -FilePath $npmCommand.Source `
            -ArgumentList $frontendPlan.RunArguments `
            -WorkingDirectory $paths.FrontendPath `
            -WindowStyle Hidden `
            -PassThru `
            -RedirectStandardOutput $paths.FrontendOutputLog `
            -RedirectStandardError $paths.FrontendErrorLog

        Write-Host "API started (PID $($api.Id)): http://127.0.0.1:8000/api/v1/health"
        Write-Host "Frontend started (PID $($frontend.Id)): http://127.0.0.1:3000"
        Write-Host "LAN access (same Wi-Fi, e.g. phone): http://192.168.100.18:3000 -- run ipconfig if this IP changes"
    }

    $predictionMessage = if ($prediction.State -eq 'reused') {
        'reused existing healthy service'
    } else {
        "started (PID $($prediction.ProcessId))"
    }

    Write-Host "Prediction service ${predictionMessage}: $script:PredictionHealthUri"
    Write-Host "Child-process logs: $($paths.ArtifactsPath)"

    return $prediction
}

if (-not $NoAutoStart) {
    Start-LocalStack `
        -PredictionOnly:$PredictionOnly `
        -Production:$Production `
        -ReadinessAttempts $ReadinessAttempts `
        -ReadinessDelayMilliseconds $ReadinessDelayMilliseconds
}
