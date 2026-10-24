<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;

/** Activation is an explicit admin action, never an Excel field. */
final class ImportedUserActivation
{
    public static function pendingIds(int $tenant):array
    {
        return DB::table('audit_logs')->where('tenant_id',$tenant)->where('entity_type','user')->where('action','import_inactive_user')
            ->whereNotIn('entity_id',DB::table('audit_logs')->where('tenant_id',$tenant)->where('entity_type','user')->where('action','activate_imported_user')->select('entity_id'))
            ->pluck('entity_id')->map(fn($id)=>(int)$id)->all();
    }
}
