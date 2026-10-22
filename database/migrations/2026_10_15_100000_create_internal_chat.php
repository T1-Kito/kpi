<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('chat_conversations', function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->string('name')->nullable();$t->string('direct_key')->nullable();$t->foreignId('created_by')->constrained('users');$t->timestamps();$t->unique(['tenant_id','direct_key']);});
        Schema::create('chat_members', function(Blueprint $t){$t->id();$t->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->unsignedBigInteger('last_read_id')->default(0);$t->unique(['conversation_id','user_id']);});
        Schema::create('chat_messages',function(Blueprint $t){$t->id();$t->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();$t->foreignId('sender_id')->constrained('users');$t->text('body');$t->uuid('client_id');$t->timestamps();$t->unique(['sender_id','client_id']);$t->index(['conversation_id','id']);});
    }
    public function down(): void {Schema::dropIfExists('chat_messages');Schema::dropIfExists('chat_members');Schema::dropIfExists('chat_conversations');}
};
