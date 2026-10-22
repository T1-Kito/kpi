<?php
// Read-only, isolated connection check. Never loads Laravel or writes configuration.
if (PHP_SAPI !== 'cli') exit(1);
function result(bool $ok, string $code, string $message): never {
    echo json_encode(compact('ok', 'code', 'message'), JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit($ok ? 0 : 1);
}
try {
    $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    $server=trim($input['server']??'');$database=trim($input['database']??'');
    // Reject DSN separators/control characters, rather than interpolate arbitrary options.
    if ($server==='' || strlen($server)>255 || preg_match('/[;{}\x00-\x1f]/',$server)
        || !preg_match('/^[\p{L}\p{N}_ -]{1,128}$/uD',$database))
        result(false,'INPUT','Nhập máy chủ và tên database hợp lệ.');
    $auth=$input['auth']??'sql';
    if (!in_array($auth,['sql','windows'],true) || ($auth==='sql' && trim($input['username']??'')===''))
        result(false,'INPUT','Chọn phương thức đăng nhập và nhập tài khoản SQL.');
    if (!extension_loaded('pdo_sqlsrv')) result(false,'DRIVER','Thiếu trình kết nối SQL Server trong bộ ứng dụng.');
    $trust=($input['trust_certificate']??false)===true?'yes':'no';
    $pdo=new PDO("sqlsrv:Server=$server;Database=$database;Encrypt=yes;TrustServerCertificate=$trust;LoginTimeout=5;ConnectionPooling=0",
        $auth==='windows'?null:($input['username']??''), $auth==='windows'?null:($input['password']??''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::SQLSRV_ATTR_QUERY_TIMEOUT=>5]);
    $statement=$pdo->prepare('SELECT CAST(? AS nvarchar(100))');
    $statement->execute(['Khách hàng Việt Nam']);
    if($statement->fetchColumn()!=='Khách hàng Việt Nam')result(false,'UNICODE','Kết nối được nhưng kiểm tra tiếng Việt chưa đạt.');
    result(true,'OK','Kết nối thành công. Máy chủ hỗ trợ dữ liệu tiếng Việt.');
} catch(PDOException $e) {
    // Do not return driver messages: these can reveal credentials and server details.
    $state=$e->errorInfo[0]??(string)$e->getCode();$native=(int)($e->errorInfo[1]??0);
    if($native===4060||$native===916)result(false,'DATABASE','Database không tồn tại hoặc tài khoản chưa được cấp quyền truy cập.');
    if($state==='28000'||$native===18456)result(false,'LOGIN','SQL từ chối đăng nhập hoặc mở database. Kiểm tra User Mapping của database và nhật ký SQL Server để biết lý do; chưa thể kết luận sai mật khẩu.');
    if(str_contains(strtolower($e->getMessage()),'certificate'))result(false,'TLS','Không xác minh được chứng chỉ máy chủ. Nhờ quản trị cấu hình chứng chỉ; chỉ bật tin cậy chứng chỉ khi thử nội bộ.');
    if(in_array($state,['HYT00','HYT01','08001'],true))result(false,'NETWORK','Không kết nối được. Kiểm tra máy chủ/instance, cổng TCP, firewall và dịch vụ SQL Server.');
    result(false,'CONNECTION','Kiểm tra kết nối thất bại. Nhờ quản trị kiểm tra quyền và nhật ký SQL Server.');
} catch(Throwable $e) {result(false,'INPUT','Không đọc được cấu hình hoặc bộ ứng dụng chưa sẵn sàng.');}
