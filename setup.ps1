# One-time setup for the shared deploy (run in PowerShell from this deploy-tools folder).
# Needs the GitHub CLI: winget install GitHub.cli   then   gh auth login
#   1. publishes this folder as the public repo DevVivekyt/deploy-tools (holds no passwords)
#   2. saves your cPanel API token as secret CPANEL_TOKEN in all 6 app repos
#   3. pulsecrm: saves DEPLOY_PATH (secret) and SITE_URL (variable)
#   4. copies app-workflows\<app>.deploy.yml to <app>\.github\workflows\deploy.yml in each app, commits and pushes
$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
$owner = 'DevVivekyt'
$repos = 'EaseBill', 'EaseSchool', 'ca-console', 'digi-library', 'ladingpagegetEasebill', 'pulsecrm'

gh auth status | Out-Null

# 1. shared action repo
if (-not (Test-Path .git)) { git init -b main | Out-Null }
git add action.yml deploy-runner.php README.md setup.ps1 app-workflows
git commit -m "Shared cPanel deploy action" 2>$null | Out-Null
gh repo view "$owner/deploy-tools" 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) { gh repo create "$owner/deploy-tools" --public --source . --push }
else { git push origin main }
Write-Host "deploy-tools published" -ForegroundColor Green

# 2. cPanel token in every app repo
$sec = Read-Host "Paste the cPanel API token (cPanel -> Security -> Manage API Tokens)" -AsSecureString
$token = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
foreach ($r in $repos) {
    $token | gh secret set CPANEL_TOKEN --repo "$owner/$r"
    Write-Host "CPANEL_TOKEN saved in $r"
}

# 3. pulsecrm settings
$path = Read-Host "pulsecrm folder on the server (e.g. /home/geteaseb/crm.geteasebill.xyz)"
$url  = Read-Host "pulsecrm site address (e.g. https://crm.geteasebill.xyz)"
$path | gh secret set DEPLOY_PATH --repo "$owner/pulsecrm"
gh variable set SITE_URL --repo "$owner/pulsecrm" --body $url

# 4. new deploy.yml in each app (only this one file is committed; your other changes stay uncommitted)
$apps = 'EaseBill', 'EaseSchool', 'ca-console', 'digi-library', 'ladingpage', 'pulsecrm'
foreach ($a in $apps) {
    $dir = Join-Path (Split-Path $PSScriptRoot) $a
    Copy-Item (Join-Path $PSScriptRoot "app-workflows\$a.deploy.yml") (Join-Path $dir '.github\workflows\deploy.yml') -Force
    git -C $dir add .github/workflows/deploy.yml
    git -C $dir commit -m "Deploy through the cPanel API (shared deploy-tools action)" -- .github/workflows/deploy.yml
    git -C $dir push
    Write-Host "$a deploy.yml updated and pushed (this starts a deploy)" -ForegroundColor Green
}
Write-Host "Done. Old SSH_* secrets are no longer used and can be deleted." -ForegroundColor Green
