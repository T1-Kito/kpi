<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void { Schema::create('service_interests',function($t){$t->id();$t->unsignedBigInteger('tenant_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name',150);$t->boolean('is_active')->default(true);$t->timestamps();$t->index(['tenant_id','parent_id']);}); }
    public function down():void { Schema::dropIfExists('service_interests'); }
};
