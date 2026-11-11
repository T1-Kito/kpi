<?php

use Illuminate\Database\Migrations\Migration;
use App\Support\CustomerFaceIdSchema;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        CustomerFaceIdSchema::up(Schema::getFacadeRoot());
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_faceid_links');
    }
};
