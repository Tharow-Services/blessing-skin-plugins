$Plugins = [System.Collections.ArrayList]@();
$PluginsDir = ".\plugins";
$FileList = Get-ChildItem -Path ("{0}\*\package.json" -f $PluginsDir);
$Dist = ".dist";
$DestUrl = "https://github.com/Tharow-Services/blessing-skin-server/releases/download/{0}/{1}.zip";
$ReleaseName = "16SEP22"
$OutFile = ".\update.json";
$Composer = "C:\ProgramData\ComposerSetup\bin\composer.bat"
$ComposerArgs = @("install");
$CompressArchiveExclude = $("composer.json");
Remove-Item -Path $Dist -Recurse -Force;
mkdir -Path $Dist -Force;

# Globals
$ProgressPreference = SilentlyContinue;
$NewFileList = $FileList | Where DirectoryName -EQ "C:\Users\TherayTharow\IdeaProjects\blessing-skin-plugins\plugins\yggdrasil-api";
foreach ($i in $NewFileList) {
    $package = (Get-Content -Path $i.FullName -Raw | ConvertFrom-Json)
    $dest = ("{0}\{1}.zip" -f $Dist, $i.Directory.Name)
    # Handle Packages With Composer Vendor.
    $ComposerFile = Join-Path -Path $i.DirectoryName -ChildPath "composer.json";
    if ([System.IO.File]::Exists($ComposerFile)) {
        Start-Process -FilePath $Composer -WorkingDirectory $i.Directory.FullName -ArgumentList $ComposerArgs -NoNewWindow -Wait -PassThru;
        Remove-Item -Path $ComposerFile.Replace(".json",".lock");
    }

    #Compress Archive
    Write-Host("Starting Compression")
    $PluginFileList = Get-ChildItem -Path $i.DirectoryName -Recurse -Exclude $CompressArchiveExclude
    Compress-Archive -Path $i.DirectoryName -DestinationPath $dest;
    Write-Host("Compression Done");
    $market = @{
        "name"=$package.name;
        "version"=$package.author;
        "title"=$package.title;
        "description"=$package.description;
        "author"=$package.author;
        "require"=$package.require;
        "dist"= @{
            "type"="zip";
            "url"=($DestUrl -f $ReleaseName, $i.Directory.Name);
            "shasum"=(Get-FileHash -Path $dest -Algorithm SHA1)
        }
    }
    $tmpInt = $Plugins.Add($market);
    Write-Host("Plugin {0} was added with id {1}" -f $package.name,$tmpInt);
}

$PluginsManifest = @{
    "version"= 1;
    "packages"= $Plugins;
}


Set-Content -Path $OutFile -Value (ConvertTo-Json $PluginsManifest -Depth 9) -Force;
