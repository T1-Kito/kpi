<?php
namespace Tests\Feature;

use App\Support\BackupBundle;
use Illuminate\Support\Facades\{DB,File};
use Tests\TestCase;

class BackupBundleTest extends TestCase
{
    public function test_encrypted_bundle_verifies_and_restores_without_touching_live_files(): void
    {
        $original=storage_path();
        $temporary=sys_get_temp_dir().'/crm-backup-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($temporary.'/framework');
        $this->app->useStoragePath($temporary);
        try {
            DB::statement('CREATE TABLE IF NOT EXISTS migrations (id integer primary key, migration text, batch integer)');
            File::ensureDirectoryExists(storage_path('app/public'));
            File::put(storage_path('app/public/test-upload.txt'),'upload original');
            $backup=app(BackupBundle::class);
            $password='OnlyForBackupTest-12345';
            try {$backup->create($password); $this->fail('Must require maintenance');}
            catch (\RuntimeException $e) {$this->assertStringContainsString('bảo trì',$e->getMessage());}
            $this->app->maintenanceMode()->activate([]);
            $name=$backup->create($password);
            $zip=new \ZipArchive(); $zip->open($backup->directory().'/'.$name);
            $this->assertFalse(@$zip->getFromName('configuration/.env')); $zip->close();
            $this->assertGreaterThanOrEqual(3,$backup->verify($name,$password)['files']);
            $restored=$backup->verify($name,$password,true)['restore_directory'];
            $this->assertSame('upload original',File::get($restored.'/uploads/public/test-upload.txt'));
            $this->assertSame('upload original',File::get(storage_path('app/public/test-upload.txt')));
            $this->assertFileExists($restored.'/configuration/.env');
            try {$backup->verify($name,'IncorrectPassword12345'); $this->fail('Wrong password must fail');}
            catch (\RuntimeException $e) {$this->assertStringContainsString('mật khẩu',$e->getMessage());}
            try {$backup->verify('../escape.zip',$password); $this->fail('Traversal must fail');}
            catch (\RuntimeException $e) {$this->assertStringContainsString('không hợp lệ',$e->getMessage());}
        } finally {
            $this->app->maintenanceMode()->deactivate();
            $this->app->useStoragePath($original);
            // Exact unique test directory, never user data.
            File::deleteDirectory($temporary);
        }
    }
}
