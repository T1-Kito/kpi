<?php
namespace App\Support;
use Illuminate\Database\Connection;
final class ServiceRequestSchema {
 public const COLUMNS=['request_details','coordinator_id','department_id','supervisor_id'];
 public static function up(Connection $db):void{$db->getSchemaBuilder()->table('service_tickets',function($t){$t->text('request_details')->nullable();$t->unsignedBigInteger('coordinator_id')->nullable();$t->unsignedBigInteger('department_id')->nullable();$t->unsignedBigInteger('supervisor_id')->nullable();});}
}
