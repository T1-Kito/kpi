<?php
$root=dirname(__DIR__);
$payload=$root.'/storage/app/private/portable-payload.zip';
if(!is_file($payload))throw new RuntimeException('Payload missing');
// Embedded at build time: integrity check, not a digital publisher signature.
file_put_contents($root.'/deployment/installer/portable.sha256',hash_file('sha256',$payload));
echo "Runtime payload checksum generated.\n";
