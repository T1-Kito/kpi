<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Hospitality\HospitalityReader;
use App\Services\Hospitality\FaceIdSync;
use App\Support\DataScope;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomerFaceIdController extends Controller
{
    public function __construct(private HospitalityReader $reader) {}

    public function index(Request $request, Customer $customer)
    {
        $this->authorizeCustomer($request, $customer);
        $configured = $this->configured($customer);
        return response()->json(['data' => [
            'configured' => $configured,
            'can_link' => $request->user()->hasPermission('user.manage'),
            'guests' => $configured ? $this->links($customer)->orderBy('guest_name')->get(['guest_id', 'guest_name']) : [],
        ]]);
    }

    public function search(Request $request, Customer $customer)
    {
        $this->authorizeCustomer($request, $customer);
        $data = $request->validate(['search' => 'required|string|max:100', 'page' => 'sometimes|integer|min:1']);
        $this->requireConnection($customer);
        return $this->source(fn () => $this->reader->read('guests', [
            'search' => $data['search'], 'page' => $data['page'] ?? 1, 'pageSize' => 15,
        ]));
    }

    public function link(Request $request, Customer $customer)
    {
        $this->authorizeCustomer($request, $customer);
        $data = $request->validate(['guest_id' => 'required|integer|min:1']);
        $this->requireConnection($customer);
        $guestId = (int) $data['guest_id'];
        return $this->source(function () use ($customer, $guestId) {
            $guest = $this->reader->session(fn (Closure $get) => $get("hospitality/guests/{$guestId}"));
            if ((int) ($guest['id'] ?? 0) !== $guestId || !is_string($guest['fullName'] ?? null) || trim($guest['fullName']) === '') {
                throw new RuntimeException('Nguồn chưa trả đúng thông tin khách để xác nhận liên kết.');
            }
            $key = ['tenant_id' => $customer->tenant_id, 'source_key' => $this->sourceKey(), 'guest_id' => $guestId];
            try {
                DB::table('customer_faceid_links')->insert($key + [
                    'customer_id' => $customer->id, 'guest_name' => mb_substr($guest['fullName'], 0, 200),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                if ((int) DB::table('customer_faceid_links')->where($key)->value('customer_id') !== (int) $customer->id) {
                    return response()->json(['message' => 'Khách FaceID này đã được liên kết với hồ sơ CRM khác.'], 409);
                }
            }
            return ['guest_id' => $guestId];
        });
    }

    public function unlink(Request $request, Customer $customer, int $guest)
    {
        $this->authorizeCustomer($request, $customer);
        $this->links($customer)->where('guest_id', $guest)->delete();
        return response()->noContent();
    }

    public function access(Request $request, Customer $customer, int $guest)
    {
        $this->authorizeLinkedGuest($request, $customer, $guest);
        return $this->source(fn () => $this->reader->session(function (Closure $get) use ($guest) {
            $rights = $this->reader->items($get("hospitality/guests/{$guest}/device-access"));
            $devices = $rights ? collect($this->reader->items($get('hospitality/devices')))->keyBy('sn') : collect();
            return ['checked_at' => now()->toIso8601String(), 'rights' => array_map(function ($right) use ($devices) {
                $serial = $right['deviceSN'] ?? null;
                return [
                    'serial' => $serial, 'name' => $devices->get($serial)['name'] ?? null,
                    'starts_at' => $right['joinDate'] ?? null, 'expires_at' => $right['expiryDate'] ?? null,
                    'sent' => isset($right['isSent']) ? filter_var($right['isSent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
                ];
            }, $rights)];
        }));
    }

    public function history(Request $request, Customer $customer, int $guest)
    {
        $this->authorizeLinkedGuest($request, $customer, $guest);
        $data = $request->validate(['serial' => 'required|string|max:120', 'page' => 'sometimes|integer|min:1']);
        $today = now('Asia/Ho_Chi_Minh');
        return $this->source(fn () => $this->reader->session(function (Closure $get) use ($guest, $data, $today) {
            $body = $get('hospitality/access-history', [
                'guestId' => $guest, 'sn' => $data['serial'], 'page' => $data['page'] ?? 1, 'pageSize' => 20,
                'from' => $today->copy()->subDays(6)->startOfDay()->format('Y-m-d\TH:i:s'),
                'to' => $today->copy()->addDay()->startOfDay()->format('Y-m-d\TH:i:s'),
            ]);
            return ['items' => array_map(fn ($row) => array_intersect_key($row, array_flip(['punchTime', 'event', 'status', 'isSkipped'])), $this->reader->items($body)),
                'total' => $body['total'] ?? null];
        }));
    }

    public function stay(Request $request, Customer $customer, int $guest)
    {
        $this->authorizeLinkedGuest($request, $customer, $guest);
        $data = $request->validate(['stay_id' => 'required|integer|min:1', 'day' => 'required|date_format:Y-m-d', 'page' => 'sometimes|integer|min:1']);
        $stayId = (int) $data['stay_id'];
        $page = (int) ($data['page'] ?? 1);
        $day = \Carbon\CarbonImmutable::parse($data['day'], 'Asia/Ho_Chi_Minh');
        return $this->source(fn () => $this->reader->session(function (Closure $get) use ($guest, $stayId, $page, $day) {
            $body = $get('hospitality/stays', [
                'from' => $day->format('Y-m-d\T00:00:00'),
                'to' => $day->addDay()->format('Y-m-d\T00:00:00'),
                'page' => $page, 'pageSize' => 100,
            ]);
            $rows = $this->reader->items($body);
            $stay = collect($rows)->first(fn ($row) => (int) ($row['occupancyId'] ?? $row['stayId'] ?? $row['id'] ?? 0) === $stayId);
            if (!$stay) return ['stay' => null, 'more' => $page * ($body['pageSize'] ?? 100) < ($body['total'] ?? 0)];

            $members = $this->reader->items($get("hospitality/stays/{$stayId}/guests"));
            abort_unless(collect($members)->contains(fn ($row) => (int) ($row['customerId'] ?? $row['guestId'] ?? $row['id'] ?? 0) === $guest), 404, 'Lượt lưu trú không khớp khách đã chọn.');
            $rooms = $this->reader->items($get('hospitality/rooms'));
            $room = collect($rooms)->first(fn ($row) => isset($stay['roomId'], $row['id']) && (string) $row['id'] === (string) $stay['roomId']);
            return ['stay' => [
                'id' => $stayId, 'group' => $stay['groupName'] ?? null, 'booking_code' => $stay['bookingGroupCode'] ?? null,
                'room' => $room['roomNumber'] ?? $room['number'] ?? null,
                'check_in' => $stay['checkInDate'] ?? $stay['checkIn'] ?? null,
                'check_out' => $stay['checkOutDate'] ?? $stay['checkOut'] ?? null,
            ], 'more' => false];
        }));
    }

    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        $user = $request->user();
        abort_unless($customer->tenant_id === $user->tenant_id && !$customer->merged_into_id
            && DataScope::owned(Customer::whereKey($customer->id), $user, 'sales_owner_id', 'salesOwner')->exists(), 404);
    }

    private function authorizeLinkedGuest(Request $request, Customer $customer, int $guest): void
    {
        $this->authorizeCustomer($request, $customer);
        $this->requireConnection($customer);
        abort_unless($this->links($customer)->where('guest_id', $guest)->exists(), 404);
    }

    private function links(Customer $customer)
    {
        return DB::table('customer_faceid_links')->where([
            'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id, 'source_key' => $this->sourceKey(),
        ]);
    }

    private function sourceKey(): string
    {
        return FaceIdSync::sourceKey();
    }

    private function configured(Customer $customer): bool
    {
        return FaceIdSync::configured((int) $customer->tenant_id);
    }

    private function requireConnection(Customer $customer): void
    {
        abort_unless($this->configured($customer), 422, 'Chưa cấu hình kết nối FaceID cho công ty này.');
    }

    private function source(Closure $read)
    {
        try {
            $data = $read();
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return response()->json(['message' => 'Chưa kết nối được với hệ thống FaceID.'], 502);
        } catch (RuntimeException $error) {
            if ($error::class !== RuntimeException::class) throw $error;
            return response()->json(['message' => $error->getMessage()], 502);
        }
        return is_array($data) ? response()->json(['data' => $data]) : $data;
    }
}
