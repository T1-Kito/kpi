$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Drawing
$projectRoot=Split-Path -Parent $PSScriptRoot
$source=Join-Path $projectRoot 'logocrmdeptop.png'
$output=Join-Path $projectRoot 'deployment\installer\VK-CRM.ico'
$sourceImage=[Drawing.Image]::FromFile($source)
try {
    $frames=foreach($size in @(16,32,48,64,128,256)){
        $bitmap=[Drawing.Bitmap]::new($size,$size,[Drawing.Imaging.PixelFormat]::Format32bppArgb)
        try{
            $graphics=[Drawing.Graphics]::FromImage($bitmap)
            try{$graphics.InterpolationMode=[Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic;$graphics.Clear([Drawing.Color]::Transparent);$graphics.DrawImage($sourceImage,0,0,$size,$size)}finally{$graphics.Dispose()}
            $stream=[IO.MemoryStream]::new()
            try{$bitmap.Save($stream,[Drawing.Imaging.ImageFormat]::Png);[pscustomobject]@{Size=$size;Bytes=$stream.ToArray()}}finally{$stream.Dispose()}
        }finally{$bitmap.Dispose()}
    }
    $file=[IO.File]::Open($output,[IO.FileMode]::Create,[IO.FileAccess]::Write)
    $writer=[IO.BinaryWriter]::new($file)
    try{
        $writer.Write([uint16]0);$writer.Write([uint16]1);$writer.Write([uint16]$frames.Count)
        $offset=6+16*$frames.Count
        foreach($frame in $frames){
            $dimension=if($frame.Size -eq 256){0}else{$frame.Size}
            $writer.Write([byte]$dimension);$writer.Write([byte]$dimension);$writer.Write([byte]0);$writer.Write([byte]0)
            $writer.Write([uint16]1);$writer.Write([uint16]32);$writer.Write([uint32]$frame.Bytes.Length);$writer.Write([uint32]$offset)
            $offset+=$frame.Bytes.Length
        }
        foreach($frame in $frames){$writer.Write([byte[]]$frame.Bytes)}
    }finally{$writer.Dispose();$file.Dispose()}
}finally{$sourceImage.Dispose()}
Get-Item -LiteralPath $output | Select-Object FullName,Length
