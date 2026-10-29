<?php

namespace Tests\Unit;

use App\Support\InstallationLicense;
use PHPUnit\Framework\TestCase;

class InstallationLicenseTest extends TestCase
{
    public function test_signed_license_requires_correct_machine_and_key(): void
    {
        $opensslConfig = tempnam(sys_get_temp_dir(), 'vkopenssl');
        file_put_contents($opensslConfig, "[req]\ndistinguished_name=dn\n[dn]\n");
        try { $private = openssl_pkey_new(['private_key_bits' => 2048, 'config' => $opensslConfig]); }
        finally { unlink($opensslConfig); }
        $public = openssl_pkey_get_details($private)['key'];
        $payload = json_encode(['version'=>1,'product'=>'VK-CRM','company'=>'Khách thử','machine'=>'A','expires'=>null]);
        openssl_sign($payload, $signature, $private, OPENSSL_ALGO_SHA256);
        $json = json_encode(['payload'=>base64_encode($payload),'signature'=>base64_encode($signature)]);
        $this->assertSame('Khách thử', InstallationLicense::validate($json, $public, 'A')['company']);
        foreach ([[$json,$public,'B'],[str_replace(base64_encode($payload),base64_encode($payload.' '),$json),$public,'A'],['{}',$public,'A']] as $args) {
            try { InstallationLicense::validate(...$args); $this->fail('Invalid license accepted'); }
            catch (\RuntimeException $error) { $this->assertNotEmpty($error->getMessage()); }
        }
    }

    public function test_obfuscation_preserves_literal_bytes_and_executable_behavior(): void
    {
        require_once dirname(__DIR__,2).'/scripts/php-release-obfuscator.php';
        $source = <<<'PHP'
<?php
// business comment
return ['text'=>'Tiếng Việt', 'slash'=>'a\\b', 'quote'=>'it\'s', 'money'=>'$value', 'math'=>3*7];
PHP;
        $protected = obfuscateReleasePhp($source);
        $this->assertStringNotContainsString('business comment', $protected);
        $this->assertStringNotContainsString('Tiếng Việt', $protected);
        $files=[];
        try {
            foreach ([$source,$protected] as $code) { $path=tempnam(sys_get_temp_dir(),'vklicense');file_put_contents($path,$code);$files[]=$path; }
            $this->assertSame(require $files[0], require $files[1]);
        } finally { foreach ($files as $path) unlink($path); }
    }
}
