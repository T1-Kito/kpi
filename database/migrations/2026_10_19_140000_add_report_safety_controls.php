<?php

use App\Support\BusinessIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Refuse to merge or discard existing duplicates. Check before changing the schema.
        $supplierKeys = []; $warrantyKeys = [];
        foreach (DB::table('suppliers')->orderBy('id')->cursor() as $row) {
            $tax = BusinessIdentity::tax($row->tax_code);
            $key = $tax === null ? null : hash('sha256', $tax);
            if ($key && isset($supplierKeys[$row->tenant_id.':'.$key])) {
                throw new RuntimeException('Duplicate supplier tax codes: resolve existing records before migration. No records were merged.');
            }
            if ($key) $supplierKeys[$row->tenant_id.':'.$key] = true;
        }
        foreach (DB::table('warranty_claims')->orderBy('id')->cursor() as $row) {
            $key = BusinessIdentity::warranty($row->product_name ?? '', $row->serial_number ?? '');
            if (isset($warrantyKeys[$row->tenant_id.':'.$key])) {
                throw new RuntimeException('Duplicate warranty registrations: resolve existing records before migration. No records were merged.');
            }
            $warrantyKeys[$row->tenant_id.':'.$key] = true;
        }

        Schema::table('users', fn(Blueprint $t) => $t->unsignedInteger('token_version')->default(1));
        Schema::create('revoked_access_tokens', function(Blueprint $t) {
            $t->string('token_hash',64)->primary();
            $t->timestamp('expires_at');
        });
        Schema::create('code_sequences', function(Blueprint $t) {
            $t->string('namespace',64)->primary();
            $t->unsignedBigInteger('last_value')->default(0);
        });
        Schema::table('suppliers', fn(Blueprint $t) => $t->string('tax_identity',64)->nullable());
        Schema::table('warranty_claims', fn(Blueprint $t) => $t->string('registration_identity',64)->nullable());
        foreach (DB::table('suppliers')->orderBy('id')->get(['id','tax_code']) as $row) {
            $tax = BusinessIdentity::tax($row->tax_code);
            DB::table('suppliers')->where('id',$row->id)->update(['tax_identity'=>$tax === null ? null : hash('sha256',$tax)]);
        }
        foreach (DB::table('warranty_claims')->orderBy('id')->get(['id','product_name','serial_number']) as $row) {
            DB::table('warranty_claims')->where('id',$row->id)->update(['registration_identity'=>BusinessIdentity::warranty($row->product_name ?? '',$row->serial_number ?? '')]);
        }
        foreach (['suppliers'=>['tax_identity','suppliers_tax_identity_unique'],'warranty_claims'=>['registration_identity','warranty_registration_unique']] as $table=>[$column,$index]) {
            if (DB::getDriverName() === 'sqlsrv') {
                $wrapped=DB::connection()->getQueryGrammar()->wrapTable($table);
                $indexName=DB::connection()->getQueryGrammar()->wrap(DB::connection()->getTablePrefix().$index);
                DB::statement("CREATE UNIQUE INDEX $indexName ON $wrapped ([tenant_id], [$column]) WHERE [$column] IS NOT NULL");
            } else {
                Schema::table($table, fn(Blueprint $t) => $t->unique(['tenant_id',$column],$index));
            }
        }
    }

    public function down(): void
    {
        Schema::table('suppliers',function(Blueprint $t) { $t->dropUnique('suppliers_tax_identity_unique'); $t->dropColumn('tax_identity'); });
        Schema::table('warranty_claims',function(Blueprint $t) { $t->dropUnique('warranty_registration_unique'); $t->dropColumn('registration_identity'); });
        Schema::dropIfExists('code_sequences');
        Schema::dropIfExists('revoked_access_tokens');
        Schema::table('users',fn(Blueprint $t) => $t->dropColumn('token_version'));
    }
};
