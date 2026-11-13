param([Parameter(Mandatory=$true)][string]$Manifest, [Parameter(Mandatory=$true)][string]$OutputDirectory)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Speech
$speaker = New-Object System.Speech.Synthesis.SpeechSynthesizer
try {
    $speaker.SelectVoice('Microsoft Zira Desktop')
    $speaker.Rate = 0
    $chapters = Get-Content -LiteralPath $Manifest -Raw | ConvertFrom-Json
    New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
    foreach ($chapter in $chapters) {
        $file = Join-Path $OutputDirectory ('chapter-{0:D2}.wav' -f [int]$chapter.number)
        $speaker.SetOutputToWaveFile($file)
        $speaker.Speak($chapter.text)
        $speaker.SetOutputToNull()
    }
} finally { $speaker.Dispose() }
