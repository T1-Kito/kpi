<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerDuplicateReview;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Http\Request;

class CustomerDuplicateService
{
    public function scan(int $tenantId): array
    {
        $seen = [];
        $pairs = [];
        Customer::query()->where('tenant_id', $tenantId)->whereNull('merged_into_id')
            ->select('id', 'tax_code', 'phone', 'email')->orderBy('id')
            ->chunkById(500, function ($customers) use (&$seen, &$pairs) {
                foreach ($customers as $customer) {
                    $keys = [
                        'tax_code' => preg_replace('/[^0-9-]/', '', (string) $customer->tax_code),
                        'phone' => preg_replace('/[^0-9]/', '', (string) $customer->phone),
                        'email' => strtolower(trim((string) $customer->email)),
                    ];
                    foreach ($keys as $field => $value) {
                        if ($value === '') continue;
                        $key = $field.':'.$value;
                        if (isset($seen[$key]) && $seen[$key] !== $customer->id) {
                            $pairKey = $seen[$key].':'.$customer->id;
                            $pairs[$pairKey][$field] = true;
                        } else {
                            $seen[$key] = $customer->id;
                        }
                    }
                }
            });

        $created = 0;
        foreach ($pairs as $pairKey => $fields) {
            [$firstId, $secondId] = array_map('intval', explode(':', $pairKey));
            $review = CustomerDuplicateReview::firstOrCreate(
                ['tenant_id' => $tenantId, 'first_customer_id' => $firstId, 'second_customer_id' => $secondId],
                ['matched_on' => implode(',', array_keys($fields)), 'status' => 'open'],
            );
            if ($review->wasRecentlyCreated) $created++;
            elseif ($review->status === 'open') $review->update(['matched_on' => implode(',', array_keys($fields))]);
        }
        return ['detected' => count($pairs), 'new_reviews' => $created];
    }

    public function merge(CustomerDuplicateReview $review, int $retainedId, User $reviewer, string $reason, AuditLogger $audit, Request $request): Customer
    {
        return DB::transaction(function () use ($review, $retainedId, $reviewer, $reason, $audit, $request) {
            $review = CustomerDuplicateReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            abort_unless($review->status === 'open', 409, 'Trường hợp này đã được xử lý.');
            abort_unless(in_array($retainedId, [$review->first_customer_id, $review->second_customer_id], true), 422, 'Hồ sơ giữ lại không thuộc cặp đang xét.');
            $sourceId = $retainedId === $review->first_customer_id ? $review->second_customer_id : $review->first_customer_id;
            $retained = Customer::whereKey($retainedId)->lockForUpdate()->firstOrFail();
            $source = Customer::whereKey($sourceId)->lockForUpdate()->firstOrFail();
            abort_unless($retained->tenant_id === $review->tenant_id && $source->tenant_id === $review->tenant_id, 404);
            abort_if($retained->merged_into_id || $source->merged_into_id, 409, 'Một hồ sơ đã được gộp trước đó. Vui lòng quét lại.');
            abort_if($retained->customer_type !== $source->customer_type, 422, 'Không thể gộp khách cá nhân với tổ chức.');
            abort_if($retained->tax_code && $source->tax_code && $retained->tax_code !== $source->tax_code, 422, 'Hai hồ sơ có mã số thuế khác nhau; cần xử lý dữ liệu trước khi gộp.');

            $before = ['retained' => $retained->toArray(), 'source' => $source->toArray()];
            $fill = [];
            foreach (['tax_code', 'contact_name', 'phone', 'email', 'billing_address', 'address', 'legal_representative', 'representative_position', 'payment_terms', 'bank_name', 'bank_account_no', 'bank_account_name'] as $field) {
                if (! $retained->$field && $source->$field) $fill[$field] = $source->$field;
            }
            if ($fill) $retained->update($fill);

            $tableCounts = [];
            foreach (['leads', 'deals', 'quotations', 'sales_orders', 'contracts', 'service_tickets', 'warranty_claims'] as $table) {
                $tableCounts[$table] = DB::table($table)->where('tenant_id', $review->tenant_id)
                    ->where('customer_id', $sourceId)->update(['customer_id' => $retainedId]);
            }
            $hasPrimary = DB::table('customer_contacts')->where('customer_id', $retainedId)->where('is_primary', true)->exists();
            $contactUpdate = ['customer_id' => $retainedId];
            if ($hasPrimary) $contactUpdate['is_primary'] = false;
            DB::table('customer_contacts')->where('customer_id', $sourceId)->update($contactUpdate);
            if (! DB::table('customer_contacts')->where('customer_id', $retainedId)->where('is_primary', true)->exists()) {
                $firstContactId = DB::table('customer_contacts')->where('customer_id', $retainedId)->min('id');
                if ($firstContactId) DB::table('customer_contacts')->where('id', $firstContactId)->update(['is_primary' => true]);
            }
            $primary = $retained->contacts()->where('is_primary', true)->first();
            if ($primary) $primary->save();
            $source->update(['merged_into_id' => $retainedId, 'status' => 'inactive']);
            $review->update(['status' => 'merged', 'retained_customer_id' => $retainedId, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'reason' => $reason]);
            CustomerDuplicateReview::where('tenant_id', $review->tenant_id)->where('status', 'open')
                ->where(fn ($q) => $q->where('first_customer_id', $sourceId)->orWhere('second_customer_id', $sourceId))
                ->update(['status' => 'merged', 'retained_customer_id' => $retainedId, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'reason' => 'Hồ sơ đã được gộp qua trường hợp #'.$review->id]);
            $audit->record('customer', $retainedId, 'merge_customer', $reviewer, $before, [
                'retained' => $retained->fresh()->toArray(), 'source_id' => $sourceId, 'moved_records' => $tableCounts,
                'reviewer_id' => $reviewer->id, 'reason' => $reason,
            ], $request, $reason);
            return $retained->refresh();
        });
    }
}
