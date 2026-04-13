param(
    [Parameter(Mandatory = $false)]
    [string]$BaseUrl = "https://comodisimos.atlassian.net",

    [Parameter(Mandatory = $false)]
    [string]$ProjectKey = "SMWH",

    [Parameter(Mandatory = $false)]
    [int]$BoardId = 252,

    [Parameter(Mandatory = $false)]
    [string]$CsvPath = ".\docs\JIRA_BACKLOG_WORKERHUB.csv",

    [Parameter(Mandatory = $false)]
    [string]$Email = $env:JIRA_EMAIL,

    [Parameter(Mandatory = $false)]
    [string]$ApiToken = $env:JIRA_API_TOKEN
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function ConvertTo-AtlassianDocument {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Text
    )

    $lines = ($Text -replace "`r", "") -split "`n"
    $content = @()

    foreach ($line in $lines) {
        if ([string]::IsNullOrWhiteSpace($line)) {
            continue
        }

        $content += @{
            type = "paragraph"
            content = @(
                @{
                    type = "text"
                    text = $line
                }
            )
        }
    }

    if ($content.Count -eq 0) {
        $content = @(
            @{
                type = "paragraph"
                content = @(
                    @{
                        type = "text"
                        text = ""
                    }
                )
            }
        )
    }

    return @{
        type = "doc"
        version = 1
        content = $content
    }
}

function ConvertTo-SafeText {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Text
    )

    $normalized = $Text.Normalize([Text.NormalizationForm]::FormD)
    $builder = New-Object System.Text.StringBuilder

    foreach ($char in $normalized.ToCharArray()) {
        $category = [Globalization.CharUnicodeInfo]::GetUnicodeCategory($char)

        if ($category -eq [Globalization.UnicodeCategory]::NonSpacingMark) {
            continue
        }

        $code = [int][char]$char

        if ($code -ge 32 -and $code -le 126) {
            [void] $builder.Append($char)
            continue
        }

        switch ($char) {
            '–' { [void] $builder.Append('-') }
            '—' { [void] $builder.Append('-') }
            '“' { [void] $builder.Append('"') }
            '”' { [void] $builder.Append('"') }
            '‘' { [void] $builder.Append("'") }
            '’' { [void] $builder.Append("'") }
            default { [void] $builder.Append(' ') }
        }
    }

    return ($builder.ToString() -replace '\s+', ' ').Trim()
}

function Invoke-JiraRequest {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Method,

        [Parameter(Mandatory = $true)]
        [string]$Uri,

        [Parameter(Mandatory = $false)]
        $Body,

        [Parameter(Mandatory = $true)]
        [hashtable]$Headers
    )

    $params = @{
        Method = $Method
        Uri = $Uri
        Headers = $Headers
    }

    if ($null -ne $Body) {
        $json = $Body | ConvertTo-Json -Depth 20 -Compress
        $params["Body"] = [System.Text.Encoding]::UTF8.GetBytes($json)
        $params["ContentType"] = "application/json"
    }

    return Invoke-RestMethod @params
}

if ([string]::IsNullOrWhiteSpace($Email) -or [string]::IsNullOrWhiteSpace($ApiToken)) {
    throw "Debes definir JIRA_EMAIL y JIRA_API_TOKEN como variables de entorno o pasarlas como parametros."
}

$resolvedCsvPath = Resolve-Path $CsvPath
$bytes = [System.Text.Encoding]::UTF8.GetBytes("$Email`:$ApiToken")
$basicAuth = [Convert]::ToBase64String($bytes)

$headers = @{
    Authorization = "Basic $basicAuth"
    Accept = "application/json"
}

$boardSprintUri = "$BaseUrl/rest/agile/1.0/board/$BoardId/sprint?state=active"
$activeSprint = $null

try {
    $sprintResponse = Invoke-JiraRequest -Method "GET" -Uri $boardSprintUri -Headers $headers
    if ($null -ne $sprintResponse.values -and $sprintResponse.values.Count -gt 0) {
        $activeSprint = $sprintResponse.values[0]
        Write-Host "Sprint activo encontrado: $($activeSprint.id) - $($activeSprint.name)"
    } else {
        Write-Warning "No se encontro sprint activo en el board $BoardId. Los issues se crearan en backlog."
    }
} catch {
    Write-Warning "No fue posible consultar el sprint activo del board $BoardId. Los issues se crearan sin asignacion de sprint."
}

$rows = Import-Csv -Path $resolvedCsvPath
$createdIssueKeys = New-Object System.Collections.Generic.List[string]

foreach ($row in $rows) {
    $summary = ConvertTo-SafeText -Text $row.Summary
    $descriptionText = ConvertTo-SafeText -Text $row.Description
    $labels = @()
    if (-not [string]::IsNullOrWhiteSpace($row.Labels)) {
        $labels = $row.Labels.Split(",") | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne "" }
    }

    $fields = @{
        project = @{
            key = $ProjectKey
        }
        summary = $summary
        issuetype = @{
            name = $row.'Issue Type'
        }
        labels = $labels
        description = (ConvertTo-AtlassianDocument -Text $descriptionText)
    }

    if (-not [string]::IsNullOrWhiteSpace($row.Priority)) {
        $fields["priority"] = @{
            name = $row.Priority
        }
    }

    $issuePayload = @{
        fields = $fields
    }

    $issue = Invoke-JiraRequest `
        -Method "POST" `
        -Uri "$BaseUrl/rest/api/3/issue" `
        -Body $issuePayload `
        -Headers $headers

    $createdIssueKeys.Add($issue.key)
    Write-Host "Issue creado: $($issue.key) - $summary"
}

if ($null -ne $activeSprint -and $createdIssueKeys.Count -gt 0) {
    $sprintAssignPayload = @{
        issues = @($createdIssueKeys.ToArray())
    }

    Invoke-JiraRequest `
        -Method "POST" `
        -Uri "$BaseUrl/rest/agile/1.0/sprint/$($activeSprint.id)/issue" `
        -Body $sprintAssignPayload `
        -Headers $headers | Out-Null

    Write-Host "Issues asignados al sprint activo $($activeSprint.name)."
}

Write-Host "Proceso terminado. Issues creados: $($createdIssueKeys.Count)"
