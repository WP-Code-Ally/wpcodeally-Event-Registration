$start = "https://www.wpcodeally.com/"
$hostName = "www.wpcodeally.com"
$maxPages = 120

$queue = New-Object System.Collections.Generic.Queue[string]
$visited = New-Object System.Collections.Generic.HashSet[string]
$statusCache = @{}
$broken = New-Object System.Collections.Generic.List[object]

$queue.Enqueue($start)

while ($queue.Count -gt 0 -and $visited.Count -lt $maxPages) {
    $pageUrl = $queue.Dequeue()
    if ($visited.Contains($pageUrl)) {
        continue
    }
    $null = $visited.Add($pageUrl)

    try {
        $pageResp = Invoke-WebRequest -Uri $pageUrl -MaximumRedirection 5 -TimeoutSec 30 -ErrorAction Stop
    } catch {
        continue
    }

    $hrefs = @()
    if ($pageResp.Links) {
        $hrefs = $pageResp.Links |
            ForEach-Object { $_.href } |
            Where-Object { $_ -and $_ -notmatch '^(mailto:|tel:|javascript:|#)' }
    }

    foreach ($href in $hrefs) {
        try {
            $abs = [uri]::new([uri]$pageUrl, $href).AbsoluteUri
            $u = [uri]$abs
        } catch {
            continue
        }

        if ($u.Host -ne $hostName) {
            continue
        }

        $norm = $u.GetLeftPart([System.UriPartial]::Path).TrimEnd("/")
        if ([string]::IsNullOrWhiteSpace($norm)) {
            continue
        }

        if (-not $visited.Contains($norm)) {
            $queue.Enqueue($norm)
        }

        if (-not $statusCache.ContainsKey($norm)) {
            try {
                $code = (Invoke-WebRequest -Uri $norm -Method Head -MaximumRedirection 5 -TimeoutSec 20 -ErrorAction Stop).StatusCode
                $statusCache[$norm] = [int]$code
            } catch {
                if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
                    $statusCache[$norm] = [int]$_.Exception.Response.StatusCode
                } else {
                    $statusCache[$norm] = 0
                }
            }
        }

        if ($statusCache[$norm] -ge 400 -or $statusCache[$norm] -eq 0) {
            $broken.Add([pscustomobject]@{
                source = $pageUrl
                target = $norm
                status = $statusCache[$norm]
            }) | Out-Null
        }
    }
}

$out = [pscustomobject]@{
    crawled_pages = $visited.Count
    checked_urls  = $statusCache.Count
    broken        = $broken
}

$outputPath = "C:\Users\commo\OneDrive\Documents\Cursor Projects\wpcodeally-link-audit.json"
$out | ConvertTo-Json -Depth 6 | Set-Content -Path $outputPath

Write-Output ("CRAWL_DONE pages={0} checked={1} broken={2}" -f $visited.Count, $statusCache.Count, $broken.Count)
Write-Output ("OUTPUT_FILE {0}" -f $outputPath)
