$fonts = @(
    'fa-solid-900.woff2',
    'fa-solid-900.ttf',
    'fa-regular-400.woff2',
    'fa-regular-400.ttf',
    'fa-brands-400.woff2',
    'fa-brands-400.ttf',
    'fa-v4compatibility.woff2',
    'fa-v4compatibility.ttf'
)

foreach ($f in $fonts) {
    $url = "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/$f"
    $out = "assets/vendor/fontawesome/webfonts/$f"
    Write-Host "Downloading $f..."
    Invoke-WebRequest -Uri $url -OutFile $out
}

Get-ChildItem 'assets/vendor/fontawesome/webfonts'
