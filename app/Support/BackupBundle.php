<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class BackupBundle
{
    public function __construct(private readonly LocalDatabaseBackup $database) {}

    public function directory(): string { return storage_path('app/private/backup-bundles'); }

    private function password(string $password): void
    {
        if (strlen($password)<16) throw new RuntimeException('Mật khẩu sao lưu cần ít nhất 16 ký tự. Hãy cất riêng khỏi máy chủ.');
    }

    public function create(string $password): string
    {
        $this->password($password);
        if (config('filesystems.default')!=='local') throw new RuntimeException('Gói này chỉ hỗ trợ lưu trữ local; chưa sao lưu được file trên dịch vụ ngoài.');
        if (!app()->isDownForMaintenance()) throw new RuntimeException('Cần bật bảo trì và dừng worker/tiến trình ghi dữ liệu trước khi sao lưu gói.');
        if (!is_file(base_path('.env'))) throw new RuntimeException('Không có file .env để sao lưu cấu hình.');
        File::ensureDirectoryExists($this->directory());
        $name='bundle-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.zip';
        $path=$this->directory().'/'.$name;
        $snapshot=$this->database->create();
        $files=['database.sqlite'=>$this->database->directory().'/'.$snapshot,'configuration/.env'=>base_path('.env')];
        foreach (['public','private'] as $disk) {
            $root=storage_path('app/'.$disk);
            if (!is_dir($root)) continue;
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
                if ($disk==='private' && in_array(explode('/',$relative)[0],['database-backups','backup-bundles'],true)) continue;
                if ($file->isLink() || !$file->isFile()) throw new RuntimeException('Không sao lưu liên kết hoặc file đặc biệt: '.$relative);
                $files['uploads/'.$disk.'/'.$relative]=$file->getPathname();
            }
        }
        $zip=new ZipArchive();
        if ($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Không tạo được gói sao lưu.');
        $zip->setPassword($password);
        $manifest=['format'=>1,'driver'=>'sqlite','created_at'=>now()->toIso8601String(),'files'=>[]];
        try {
            foreach ($files as $entry=>$file) {
                if (filesize($file)>64*1024*1024) throw new RuntimeException('File vượt giới hạn 64 MB của bản sao lưu hiện tại: '.$entry);
                $manifest['files'][$entry]=['sha256'=>hash_file('sha256',$file),'bytes'=>filesize($file)];
                if (!$zip->addFile($file,$entry) || !$zip->setEncryptionName($entry,ZipArchive::EM_AES_256)) throw new RuntimeException('Không mã hóa được file.');
            }
            $zip->addFromString('manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            $zip->setEncryptionName('manifest.json',ZipArchive::EM_AES_256);
        } finally { if (!$zip->close()) throw new RuntimeException('Không hoàn tất được gói sao lưu.'); }
        $this->verify($name,$password);
        return $name;
    }

    public function verify(string $name,string $password, bool $restoreTest=false): array
    {
        $this->password($password);
        if (!preg_match('/^bundle-\d{8}-\d{6}-[a-f0-9]{8}\.zip$/D',$name)) throw new RuntimeException('Tên gói không hợp lệ.');
        $path=$this->directory().'/'.$name;
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Không tìm thấy gói.');
        $zip=new ZipArchive();
        if ($zip->open($path,ZipArchive::RDONLY)!==true) throw new RuntimeException('Gói không đọc được.');
        $zip->setPassword($password);
        $destination=null;
        try {
            $raw=$zip->getFromName('manifest.json');
            if ($raw===false) throw new RuntimeException('Sai mật khẩu hoặc gói hỏng.');
            $manifest=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if (($manifest['format']??null)!==1 || ($manifest['driver']??null)!=='sqlite' || !isset($manifest['files']['database.sqlite'],$manifest['files']['configuration/.env'])) throw new RuntimeException('Thiếu database hoặc cấu hình.');
            if ($zip->numFiles!==count($manifest['files'])+1) throw new RuntimeException('Gói có file ngoài danh sách kiểm chứng.');
            if ($restoreTest) {
                $destination=$this->directory().'/restore-test-'.bin2hex(random_bytes(8));
                File::makeDirectory($destination);
            }
            foreach ($manifest['files'] as $entry=>$metadata) {
                if (!preg_match('#^(database\.sqlite|configuration/\.env|uploads/(public|private)/[^\\\\:]+)$#D',$entry) || in_array('..',explode('/',$entry),true) || str_contains($entry,"\0")) throw new RuntimeException('Đường dẫn trong gói không an toàn.');
                $stat=$zip->statName($entry);
                if (!$stat || $stat['size']>64*1024*1024) throw new RuntimeException('File vượt giới hạn khôi phục 64 MB.');
                $content=$zip->getFromName($entry);
                if ($content===false || strlen($content)!==$metadata['bytes'] || !hash_equals($metadata['sha256'],hash('sha256',$content))) throw new RuntimeException('File sai checksum: '.$entry);
                if ($destination) {
                    File::ensureDirectoryExists(dirname($destination.'/'.$entry));
                    File::put($destination.'/'.$entry,$content);
                }
            }
            if ($destination) {
                $pdo=new \PDO('sqlite:file:'.str_replace('\\','/',$destination.'/database.sqlite').'?mode=ro');
                if ($pdo->query('PRAGMA integrity_check')->fetchColumn()!=='ok') throw new RuntimeException('Database khôi phục không toàn vẹn.');
            }
            return ['files'=>count($manifest['files']),'restore_directory'=>$destination];
        } finally {$zip->close();}
    }
}
