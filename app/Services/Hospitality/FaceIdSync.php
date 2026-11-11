<?php

namespace App\Services\Hospitality;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class FaceIdSync
{
    private const FIELDS = [
        'rooms' => ['id', 'roomNumber', 'number', 'name', 'roomTypeCode', 'maxAdults', 'maxChildren', 'isActive'],
        'guests' => ['id', 'fullName', 'pin', 'PIN', 'phoneNumber', 'email'],
        'stays' => ['id', 'occupancyId', 'stayId', 'roomId', 'groupName', 'bookingGroupCode', 'checkInDate', 'checkOutDate', 'checkIn', 'checkOut'],
        'devices' => ['sn', 'serialNumber', 'name', 'isOnline', 'isDisabled'],
    ];

    public function __construct(private HospitalityReader $reader) {}

    public static function configured(int $tenantId): bool
    {
        return $tenantId > 0 && (string) $tenantId === (string) config('hospitality.tenant_id')
            && filled(config('hospitality.base_url')) && filled(config('hospitality.username')) && filled(config('hospitality.password'));
    }

    public static function sourceKey(bool $demo = false): string
    {
        return hash('sha256', $demo ? 'faceid:demo' : 'faceid:'.rtrim((string) config('hospitality.base_url'), '/'));
    }

    public function status(int $tenantId, bool $demo = false): array
    {
        $row = DB::table('faceid_sync_states')->where($this->scope($tenantId, $demo))->first();
        return [
            'status' => $row->status ?? 'idle',
            'attempted_at' => $row?->attempted_at ? \Carbon\CarbonImmutable::parse($row->attempted_at, config('app.timezone'))->toIso8601String() : null,
            'succeeded_at' => $row?->succeeded_at ? \Carbon\CarbonImmutable::parse($row->succeeded_at, config('app.timezone'))->toIso8601String() : null,
            'counts' => json_decode($row->counts ?? '{}', true),
        ];
    }

    public function run(int $tenantId, bool $demo = false): array
    {
        if (!$demo && !self::configured($tenantId)) throw new RuntimeException('Chưa cấu hình kết nối FaceID cho công ty này.');
        $scope = $this->scope($tenantId, $demo);
        $lock = Cache::lock("faceid-sync:{$tenantId}:{$scope['source_key']}", 1800);
        if (!$lock->get()) throw new LockTimeoutException('Đang có một lượt đồng bộ FaceID.');

        try {
            DB::table('faceid_sync_states')->updateOrInsert($scope, ['attempted_at' => now(), 'status' => 'running']);
            $sets = $demo ? $this->demo() : $this->reader->session(fn (Closure $get) => $this->fetch($get));
            $counts = DB::transaction(function () use ($sets, $scope, $demo) {
                $counts = [];
                $now = now();
                foreach ($sets as $resource => $items) {
                    $rows = [];
                    foreach ($items as $item) {
                        $row = $this->record($scope, $resource, $item, $now, $demo);
                        $rows[$row['external_id']] = $row;
                    }
                    foreach (array_chunk($rows, 100) as $chunk) {
                        DB::table('faceid_source_records')->upsert($chunk, ['tenant_id', 'source_key', 'resource', 'external_id'],
                            ['guest_id', 'label', 'pin', 'arrives_on', 'leaves_on', 'payload', 'updated_at']);
                    }
                    $counts[$resource] = count($rows);
                }
                DB::table('faceid_sync_states')->where($scope)->update(['succeeded_at' => $now, 'status' => 'success', 'counts' => json_encode($counts, JSON_THROW_ON_ERROR)]);
                return $counts;
            });
            return $counts;
        } catch (Throwable $error) {
            DB::table('faceid_sync_states')->where($scope)->update(['status' => 'failed']);
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public function catalog(int $tenantId, string $resource, array $filters, bool $demo): array
    {
        $scope = $this->scope($tenantId, $demo);
        $query = DB::table('faceid_source_records as records')->where('records.tenant_id', $tenantId)
            ->where('records.source_key', $scope['source_key'])->where('records.resource', $resource);
        if ($resource === 'guests' && !$demo) {
            $query->leftJoin('customer_faceid_links as links', function ($join) {
                $join->on('links.tenant_id', '=', 'records.tenant_id')->on('links.source_key', '=', 'records.source_key')
                    ->on('links.guest_id', '=', 'records.guest_id');
            });
            if (!empty($filters['unlinked'])) $query->whereNull('links.id');
            $query->addSelect('links.customer_id');
        }
        if ($resource === 'guests' && !empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->whereLike('records.label', $term)->orWhereLike('records.pin', $term));
        }
        if ($resource === 'stays' && !empty($filters['from'])) $query->where('records.leaves_on', '>=', $filters['from']);
        if ($resource === 'stays' && !empty($filters['to'])) $query->where('records.arrives_on', '<=', $filters['to']);
        $page = $query->addSelect('records.payload')->orderBy('records.id')->paginate(20, page: (int) ($filters['page'] ?? 1));
        return ['total' => $page->total(), 'items' => array_map(function ($row) use ($resource, $demo) {
            $data = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            if ($resource === 'guests' && !$demo) $data['link_status'] = $row->customer_id ? 'Đã liên kết' : 'Chờ liên kết';
            return $data;
        }, $page->items())];
    }

    private function scope(int $tenantId, bool $demo): array
    {
        return ['tenant_id' => $tenantId, 'source_key' => self::sourceKey($demo)];
    }

    private function fetch(Closure $get): array
    {
        $today = now('Asia/Ho_Chi_Minh');
        $sets = [];
        foreach (array_keys(self::FIELDS) as $resource) {
            $sets[$resource] = [];
            $dates = $resource === 'stays' ? [
                'from' => $today->copy()->subDays(30)->format('Y-m-d\T00:00:00'),
                'to' => $today->copy()->addDays(31)->format('Y-m-d\T00:00:00'),
            ] : [];
            $seenPages = [];
            for ($page = 1; ; $page++) {
                $body = $get("hospitality/{$resource}", $dates + ['page' => $page, 'pageSize' => 100]);
                $rows = $this->reader->items($body);
                $signature = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
                if (isset($seenPages[$signature])) throw new RuntimeException('Nguồn FaceID trả lại cùng một trang dữ liệu.');
                $seenPages[$signature] = true;
                foreach ($rows as $row) $sets[$resource][] = $row;
                if (array_is_list($body) && in_array($resource, ['rooms', 'devices'], true)) break;
                $size = filter_var($body['pageSize'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $total = filter_var($body['total'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($size !== false && $total !== false) {
                    if ($page * $size >= $total) break;
                    if (!$rows) throw new RuntimeException('Nguồn FaceID trả trang rỗng trước khi hết dữ liệu.');
                } elseif (in_array($resource, ['rooms', 'devices'], true)) {
                    break;
                } else {
                    throw new RuntimeException('Nguồn FaceID thiếu thông tin phân trang.');
                }
            }
        }
        return $sets;
    }

    private function record(array $scope, string $resource, array $item, $now, bool $demo): array
    {
        $id = match ($resource) {
            'rooms' => $item['id'] ?? $item['roomNumber'] ?? $item['number'] ?? null,
            'stays' => $item['occupancyId'] ?? $item['stayId'] ?? $item['id'] ?? null,
            'devices' => $item['sn'] ?? $item['serialNumber'] ?? null,
            'guests' => $item['id'] ?? null,
        };
        if ((!is_string($id) && !is_int($id)) || trim((string) $id) === '' || mb_strlen((string) $id) > 128) {
            throw new RuntimeException('Nguồn FaceID thiếu mã định danh hợp lệ.');
        }
        $data = array_intersect_key($item, array_flip(self::FIELDS[$resource]));
        $guestId = $resource === 'guests' && !$demo ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        if ($guestId === false) throw new RuntimeException('Nguồn FaceID trả ID khách không hợp lệ.');
        $day = fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:T|$)/', $value) ? substr($value, 0, 10) : null;
        return $scope + [
            'resource' => $resource, 'external_id' => (string) $id,
            'guest_id' => $guestId,
            'label' => isset($data['fullName']) ? mb_substr((string) $data['fullName'], 0, 255) : null,
            'pin' => isset($data['pin']) || isset($data['PIN']) ? mb_substr((string) ($data['pin'] ?? $data['PIN']), 0, 100) : null,
            'arrives_on' => $day($data['checkInDate'] ?? $data['checkIn'] ?? null),
            'leaves_on' => $day($data['checkOutDate'] ?? $data['checkOut'] ?? null),
            'payload' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function demo(): array
    {
        $today = now('Asia/Ho_Chi_Minh');
        return [
            'rooms' => [['id' => 'DEMO-A101', 'roomNumber' => 'DEMO-A101', 'name' => 'Phòng hướng vườn', 'roomTypeCode' => 'DE', 'maxAdults' => 2, 'maxChildren' => 1]],
            'guests' => [['id' => 'DEMO-K001', 'fullName' => 'Khách thử FaceID', 'pin' => 'DEMO-PIN01']],
            'stays' => [['occupancyId' => 'DEMO-LT001', 'roomId' => 'DEMO-A101', 'groupName' => 'Đoàn thử', 'bookingGroupCode' => 'DEMO-DOAN01',
                'checkInDate' => $today->format('Y-m-d\T14:00:00'), 'checkOutDate' => $today->copy()->addDay()->format('Y-m-d\T12:00:00')]],
            'devices' => [['sn' => 'DEMO-GATE01', 'name' => 'Cổng vào mẫu', 'isOnline' => true, 'isDisabled' => false]],
        ];
    }
}
