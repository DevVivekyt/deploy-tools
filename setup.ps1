# One-time setup for the shared deploy (run in PowerShell; safe to run again).
# Needs the GitHub CLI: winget install GitHub.cli   then   gh auth login
#   1. publishes this folder as the public repo DevVivekyt/deploy-tools (holds no passwords)
#   2. saves your cPanel API token as secret CPANEL_TOKEN in all 6 app repos
#   3. pulsecrm: saves DEPLOY_PATH (secret) and SITE_URL (variable)
#   4. copies app-workflows\<app>.deploy.yml to <app>\.github\workflows\deploy.yml in each app, commits and pushes
$ErrorActionPreference = 'Continue'   # gh / git write normal messages to stderr; results are checked with $LASTEXITCODE
Set-Location $PSScriptRoot
$owner = 'DevVivekyt'
$repos = 'EaseBill', 'EaseSchool', 'ca-console', 'digi-library', 'ladingpagegetEasebill', 'pulsecrm'

function Check([string]$what) {
    if ($LASTEXITCODE -ne 0) { Write-Host "FAILED: $what" -ForegroundColor Red; exit 1 }
}

gh auth status *> $null; Check "gh is not signed in. Run: gh auth login"

# 1. shared action repo
if (-not (Test-Path .git)) { git init -b main *> $null; Check "git init" }
git add action.yml deploy-runner.php README.md setup.ps1 app-workflows *> $null; Check "git add"
git diff --cached --quiet
if ($LASTEXITCODE -ne 0) { git commit -q -m "Shared cPanel deploy action"; Check "git commit" }

gh repo view "$owner/deploy-tools" *> $null
if ($LASTEXITCODE -ne 0) {
    gh repo create "$owner/deploy-tools" --public --source . --remote origin --push; Check "create repo $owner/deploy-tools"
} else {
    git remote get-url origin *> $null
    if ($LASTEXITCODE -ne 0) { git remote add origin "https://github.com/$owner/deploy-tools.git" }
    git push -u origin main; Check "push deploy-tools"
}
Write-Host "deploy-tools published" -ForegroundColor Green

# 2. cPanel token in every app repo
$sec = Read-Host "Paste the cPanel API token (cPanel -> Security -> Manage API Tokens)" -AsSecureString
$token = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
if (-not $token) { Write-Host "No token given" -ForegroundColor Red; exit 1 }
foreach ($r in $repos) {
    gh secret set CPANEL_TOKEN --repo "$owner/$r" --body $token; Check "save CPANEL_TOKEN in $r"
    Write-Host "CPANEL_TOKEN saved in $r"
}

# 3. pulsecrm settings
$path = Read-Host "pulsecrm folder on the server (e.g. /home/geteaseb/crm.geteasebill.xyz)"
$url  = Read-Host "pulsecrm site address (e.g. https://crm.geteasebill.xyz)"
gh secret set DEPLOY_PATH --repo "$owner/pulsecrm" --body $path; Check "save DEPLOY_PATH in pulsecrm"
gh variable set SITE_URL --repo "$owner/pulsecrm" --body $url; Check "save SITE_URL in pulsecrm"

# 4. new deploy.yml in each app (only this one file is committed; your other changes stay uncommitted)
$apps = 'EaseBill', 'EaseSchool', 'ca-console', 'digi-library', 'ladingpage', 'pulsecrm'
foreach ($a in $apps) {
    $dir = Join-Path (Split-Path $PSScriptRoot) $a
    Copy-Item (Join-Path $PSScriptRoot "app-workflows\$a.deploy.yml") (Join-Path $dir '.github\workflows\deploy.yml') -Force
    git -C $dir add .github/workflows/deploy.yml
    git -C $dir diff --cached --quiet -- .github/workflows/deploy.yml
    if ($LASTEXITCODE -ne 0) {
        git -C $dir commit -q -m "Deploy through the cPanel API (shared deploy-tools action)" -- .github/workflows/deploy.yml; Check "commit in $a"
    }
    git -C $dir push; Check "push $a"
    Write-Host "$a deploy.yml pushed (this starts a deploy: GitHub -> Actions)" -ForegroundColor Green
}
Write-Host "Done. Old SSH_* secrets are no longer used and can be deleted." -ForegroundColor Green
