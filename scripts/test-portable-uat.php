<?php
require __DIR__.'/../vendor/autoload.php';
$root=dirname(__DIR__);$reuse=null;
$sourcePackage=$root.'/storage/app/private/release-packages/vk-crm-uat-source-20261017-064115-1212b9.zip';
foreach(array_slice($argv,1) as $argument){
    if(str_starts_with($argument,'--source=')){
        $name=substr($argument,9);
        if(!preg_match('/^vk-crm-uat-source-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.zip$/D',$name))throw new RuntimeException('Invalid source package');
        $sourcePackage=$root.'/storage/app/private/release-packages/'.$name;
    }else{$reuse=$argument;}
}
if($reuse && !preg_match('/^portable-smoke-[a-f0-9]{10}$/D',$reuse))throw new RuntimeException('Invalid test fixture');
$target=$root.'/.tmp/'.($reuse??('portable-smoke-'.bin2hex(random_bytes(5))));
if(!$reuse){mkdir($target,0700,true);
foreach([$sourcePackage,$root.'/storage/app/private/portable-payload.zip'] as $file){$zip=new ZipArchive();if($zip->open($file)!==true)throw new RuntimeException('Payload not ready');$zip->extractTo($target);$zip->close();}}
$php=$target.'/runtime/php.exe';$args=[$php,'-c',$target.'/runtime/php.ini','-d','extension_dir='.$target.'/runtime/ext'];
$process=new Symfony\Component\Process\Process([...$args,$target.'/initialize-uat.php'],$target);
$password=bin2hex(random_bytes(16));
$process->setInput(json_encode(['company'=>'Portable smoke only','email'=>'portable@example.test','password'=>$password]));$process->setTimeout(120);
if(!$reuse){$process->run();
echo $process->getOutput();echo $process->getErrorOutput();if(!$process->isSuccessful())exit(1);
}else{$db=new PDO('sqlite:'.$target.'/database/uat.sqlite');$update=$db->prepare('UPDATE users SET password=? WHERE email=?');$update->execute([password_hash($password,PASSWORD_BCRYPT),'portable@example.test']);$db=null;}
$check=new Symfony\Component\Process\Process([...$args,'artisan','about','--only=environment'],$target);$check->run();echo $check->getOutput();echo $check->getErrorOutput();
if(!$check->isSuccessful())exit(1);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('No test port');
$address=stream_socket_get_name($socket,false);fclose($socket);
$server=new Symfony\Component\Process\Process([...$args,'-S',$address,'-t',$target.'/public',$target.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],$target.'/public');
$server->start();
try {
    $request=function(string $path,?array $body=null,?string $token=null)use($address){
        $curl=curl_init('http://'.$address.$path);$headers=['Accept: application/json'];
        if($token)$headers[]='Authorization: Bearer '.$token;
        if($body!==null){$headers[]='Content-Type: application/json';curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body));}
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers]);
        $result=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);return [$status,json_decode($result?:'',true)];
    };
    for($attempt=0;$attempt<20;$attempt++){[$status]=$request('/');if($status===200)break;usleep(200000);}
    if($status!==200)throw new RuntimeException('HTTP shell failed');
    [$status,$body]=$request('/api/v1/auth/login',['email'=>'portable@example.test','password'=>$password]);
    if($status!==200||empty($body['data']['access_token']))throw new RuntimeException('Portable login failed');
    [$status,$customers]=$request('/api/v1/customers',null,$body['data']['access_token']);
    if($status!==200||!isset($customers['data'])||!is_array($customers['data']))throw new RuntimeException('Portable customer permissions failed');
    // Reused fixtures intentionally retain customers created by earlier test runs.
    if(!$reuse&&count($customers['data'])!==0)throw new RuntimeException('Fresh portable package contains customer data');
    $token=$body['data']['access_token'];
    [$status,$customer]=$request('/api/v1/customers',['name'=>'Portable test customer','sales_owner_id'=>$body['data']['user']['id']],$token);
    if($status!==201)throw new RuntimeException('Portable customer creation failed');
    $customerId=$customer['data']['id'];
    [$status,$care]=$request('/api/v1/customers/'.$customerId.'/care',['title'=>'Portable care test','due_at'=>gmdate('c',time()+86400)],$token);
    if($status!==201)throw new RuntimeException('Portable care creation failed');
    [$status]=$request('/api/v1/customers/'.$customerId.'/care/'.$care['data']['id'].'/complete',['outcome'=>'contacted','note'=>'Portable test only'],$token);
    if($status!==200)throw new RuntimeException('Portable care completion failed');
    $server->stop(2);$server->start();
    for($attempt=0;$attempt<20;$attempt++){[$status]=$request('/up');if($status===200)break;usleep(200000);}
    [$status,$saved]=$request('/api/v1/customers/'.$customerId.'/care',null,$token);
    if($status!==200||($saved['data']['last_result']??'')!=='Đã liên hệ: Portable test only')throw new RuntimeException('Portable persistence after restart failed');
    echo "Portable HTTP, login, customer creation, care completion and persistence after restart: PASS\n";
}finally{$server->stop(2);}
echo 'Isolated smoke folder retained: '.$target.PHP_EOL;
