if (!(Test-Path updated.json)) {
    exit
}

git config --global user.name 'Theray Tharow - Bot'
git config --global user.email 'tharowt@tharow.net'

$token = $env:GH_TOKEN

Set-Location .dist
git add .

$shouldUpdate = git status -s
if ($shouldUpdate) {
  git commit -m "Publish"
  git remote set-url origin "https://tharowt:$token@github.com/Tharow-Services/plugins-dist.git"
  git push origin master
}


