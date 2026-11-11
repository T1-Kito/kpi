<?php

use App\Support\FaceIdSyncSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        FaceIdSyncSchema::up(Schema::getFacadeRoot());
    }

    public function down(): void
    {
        Schema::dropIfExists('faceid_sync_states');
        Schema::dropIfExists('faceid_source_records');
    }
};
