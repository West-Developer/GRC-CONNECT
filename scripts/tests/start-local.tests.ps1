$scriptPath = Join-Path $PSScriptRoot '..\start-local.ps1'
. $scriptPath -NoAutoStart

Describe 'Start-PredictionService' {
    It 'reuses an already healthy prediction service instead of starting a duplicate' {
        $result = Start-PredictionService -HealthProbe { $true } -ProcessStarter {
            throw 'A healthy service must not be started again.'
        }

        $result.State | Should Be 'reused'
        $result.ProcessId | Should Be 0
    }

    It 'starts Uvicorn and waits until the private health contract becomes healthy' {
        $script:probeCount = 0
        $result = Start-PredictionService -HealthProbe {
            $script:probeCount += 1
            $script:probeCount -ge 2
        } -ProcessStarter {
            [pscustomobject]@{ Id = 2468 }
        } -PortProbe {
            param([int] $Port)

            return 0
        } -ReadinessAttempts 2 -ReadinessDelayMilliseconds 0

        $result.State | Should Be 'started'
        $result.ProcessId | Should Be 2468
        $script:probeCount | Should Be 2
    }
}

Describe 'Get-FrontendLaunchPlan' {
    # ADR 0029: the day-to-day launcher runs `next dev` (unminified, compiles on
    # demand), which is what makes the app feel slow on a phone or a demo. -Production
    # builds once and serves the optimised bundle instead.
    It 'runs the Next dev server by default, with no build step' {
        $plan = Get-FrontendLaunchPlan

        $plan.BuildArguments | Should BeNullOrEmpty
        ($plan.RunArguments -join ' ') | Should Be 'run dev -- --hostname 0.0.0.0'
    }

    It 'builds first and then serves the production bundle with -Production' {
        $plan = Get-FrontendLaunchPlan -Production

        ($plan.BuildArguments -join ' ') | Should Be 'run build'
        ($plan.RunArguments -join ' ') | Should Be 'run start -- --hostname 0.0.0.0'
    }
}

Describe 'Start-LocalStack' {
    It 'fails before launching any process when an API port belongs to an unknown listener' {
        {
            Start-LocalStack -PredictionOnly:$false -PortProbe {
                param([int] $Port)

                if ($Port -eq 8000) {
                    return 9876
                }

                return 0
            } -ProcessStarter {
                throw 'must not launch'
            }
        } | Should Throw 'Port 8000 is already used by process 9876.'
    }
}
