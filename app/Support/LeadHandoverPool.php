<?php
namespace App\Support;
use App\Models\{Tenant,User};
final class LeadHandoverPool
{
    public static function allows(User $user, Tenant $tenant):bool {
        $roles=$tenant->ui_settings['lead_handover']['role_ids'] ?? [];
        return $user->tenant_id===$tenant->id && $user->is_active && (bool)$roles
            && $user->roles->where('tenant_id',$tenant->id)->whereIn('id',$roles)->isNotEmpty()
            && $user->hasPermission('sales.lead.manage') && $user->hasPermission('sales.deal.manage');
    }
}
