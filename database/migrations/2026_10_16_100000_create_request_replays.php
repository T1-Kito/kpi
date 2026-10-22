<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {Schema::create('request_replays',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('operation',80);$t->string('request_key',120);$t->string('payload_hash',64);$t->longText('response_body');$t->integer('response_status');$t->timestamps();$t->unique(['user_id','operation','request_key']);});}
    public function down(): void {Schema::dropIfExists('request_replays');}
};
