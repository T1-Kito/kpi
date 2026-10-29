<?php
namespace App\Support;

use App\Models\PrintTemplate;
use Illuminate\Http\Request;

final class PrintTemplateSelection
{
    public static function resolve(Request $request, string $module): ?PrintTemplate
    {
        $data = $request->validate(['template_id' => ['nullable', 'integer', 'min:1']]);
        $query = PrintTemplate::where('tenant_id', $request->user()->tenant_id)
            ->where('module', $module)->where('status', 'active');
        if (!empty($data['template_id'])) {
            // An explicit invalid selection must never silently use another template.
            return $query->whereKey($data['template_id'])->first();
        }
        return $query->orderByDesc('is_default')->latest('id')->first();
    }
}
