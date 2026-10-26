<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void {Schema::create('crm_intake_rows',fn($t)=>\App\Support\CrmIntakeImport::schema($t));}
    public function down():void {Schema::dropIfExists('crm_intake_rows');}
};
