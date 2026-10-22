<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class LocalDatabaseBackup
{
    public function directory(): string
    {
        return storage_path('app/private/database-backups');
    }

    public function create(): string
    {
        $connection=DB::connection();
        if ($connection->getDriverName()!=='sqlite') throw new RuntimeException('Chỉ hỗ trợ SQLite ở bản này. SQL Server cần quy trình BACKUP/RESTORE riêng đã được kiểm thử.');
        File::ensureDirectoryExists($this->directory());
        $path=$this->directory().'/crm-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
        try {
            // SQLite produces a consistent snapshot, including committed WAL data.
            $connection->getPdo()->exec('VACUUM INTO '.$connection->getPdo()->quote($path));
            $this->check($path);
            $manifest=['format'=>1,'driver'=>'sqlite','created_at'=>now()->toIso8601String(),'sha256'=>hash_file('sha256',$path),'bytes'=>filesize($path),'scope'=>'database_only'];
            File::put($path.'.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            return basename($path);
        } catch (\Throwable $e) {
            // Keep incomplete snapshots for diagnosis, but never mark them verified.
            throw new RuntimeException('Sao lưu chưa hoàn tất: '.$e->getMessage(),0,$e);
        }
    }

    private function path(string $name): string
    {
        if (!preg_match('/^crm-\d{8}-\d{6}-[a-f0-9]{8}\.sqlite$/D',$name)) throw new RuntimeException('Tên bản sao lưu không hợp lệ.');
        $path=$this->directory().'/'.$name;
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Không tìm thấy bản sao lưu.');
        return $path;
    }

    private function check(string $path): void
    {
        $db=new \PDO('sqlite:file:'.str_replace('\\','/',$path).'?mode=ro');
        if ($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok') throw new RuntimeException('Database không vượt kiểm tra toàn vẹn.');
        if (!$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'")->fetchColumn()) throw new RuntimeException('Không phải database CRM có bảng migrations.');
    }

    public function verify(string $name): array
    {
        $path=$this->path($name);
        if (!is_file($path.'.json')) throw new RuntimeException('Thiếu thông tin kiểm chứng.');
        $manifest=json_decode(File::get($path.'.json'),true,512,JSON_THROW_ON_ERROR);
        if (!hash_equals($manifest['sha256']??'',hash_file('sha256',$path))) throw new RuntimeException('Checksum không khớp: bản sao lưu đã thay đổi.');
        $this->check($path);
        return $manifest;
    }

    public function restoreCopy(string $name): string
    {
        $this->verify($name);
        $target=$this->directory().'/restore-test-'.bin2hex(random_bytes(8)).'.sqlite';
        $input=fopen($this->path($name),'rb');
        $output=fopen($target,'xb');
        try {stream_copy_to_stream($input,$output);} finally {fclose($input);fclose($output);}
        $this->check($target);
        if (!hash_equals(hash_file('sha256',$this->path($name)),hash_file('sha256',$target))) throw new RuntimeException('Bản khôi phục không khớp.');
        return $target;
    }
}
