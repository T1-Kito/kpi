<?php

namespace App\Services\Hospitality;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Read-only sandbox adapter for the session/CSRF contract in API.docx. */
class HospitalityReader
{
    public function read(string $resource, array $query): array
    {
        $paths = ['rooms'=>'hospitality/rooms', 'guests'=>'hospitality/guests', 'stays'=>'hospitality/stays', 'devices'=>'hospitality/devices', 'commands'=>'hospitality/commands'];
        if (!isset($paths[$resource])) throw new RuntimeException('Loại dữ liệu chưa được hỗ trợ.');
        return $this->session(function (Closure $get) use ($paths, $resource, $query): array {
            $body = $get($paths[$resource], $query);
            $items = $this->items($body);
            $fields = match ($resource) {
                'rooms'=>['id','roomNumber','number','name','roomTypeCode','isActive','maxAdults','maxChildren'],
                'guests'=>['id','pin','PIN','fullName','phoneNumber','email'],
                'stays'=>['id','stayId','occupancyId','roomId','groupName','bookingGroupCode','checkInDate','checkOutDate','checkIn','checkOut'],
                'devices'=>['sn','serialNumber','name','type','mode','isOnline','isDisabled'],
                'commands'=>['cmdReaquestID','customerId','deviceInfoId','transferTime','returnTime','returnValue'],
            };
            return ['items'=>array_map(fn ($row)=>array_intersect_key((array) $row, array_flip($fields)), $items),
                'page'=>$body['page'] ?? 1, 'pageSize'=>$body['pageSize'] ?? count($items), 'total'=>$body['total'] ?? count($items)];
        });
    }

    public function items(array $body): array
    {
        $items = array_is_list($body) ? $body : ($body['items'] ?? null);
        if (!is_array($items) || !array_is_list($items) || count(array_filter($items, 'is_array')) !== count($items)) {
            throw new RuntimeException('Cấu trúc dữ liệu nguồn chưa khớp: cần danh sách bản ghi.');
        }
        return $items;
    }

    public function session(Closure $action): mixed
    {
        $url = rtrim((string) config('hospitality.base_url'), '/');
        $address = parse_url($url);
        $scheme = $address['scheme'] ?? null;
        $allowsHttp = config('hospitality.allow_http') === true;
        if (empty($address['host']) || isset($address['user']) || isset($address['pass'])
            || isset($address['query']) || isset($address['fragment'])
            || !in_array($scheme, ['https', 'http'], true)) {
            throw new RuntimeException('Địa chỉ API FaceID không hợp lệ.');
        }
        if ($scheme === 'http' && !$allowsHttp) {
            throw new RuntimeException('Kết nối HTTP nội bộ chưa được bật cho FaceID trên máy chủ CRM.');
        }
        if (!config('hospitality.username') || !config('hospitality.password')) throw new RuntimeException('Chưa cấu hình tài khoản API trên máy chủ CRM.');
        $client = Http::baseUrl($url.'/api/v1')->acceptJson()->connectTimeout(4)->timeout(12)
            ->withOptions(['cookies'=>new CookieJar(), 'allow_redirects'=>false]);
        $csrf = $client->get('session/csrf');
        if (!$csrf->successful() || !is_string($csrf->json('headerName')) || !is_string($csrf->json('requestToken'))) throw new RuntimeException('Không lấy được phiên CSRF. Kiểm tra địa chỉ và hợp đồng API.');
        $header = $csrf->json('headerName');
        if (!preg_match('/^[A-Za-z0-9-]+$/', $header)) throw new RuntimeException('Tên header CSRF không hợp lệ.');
        $login = (clone $client)->withHeaders([$header=>$csrf->json('requestToken')])->post('session/login', [
            'userName'=>config('hospitality.username'), 'password'=>config('hospitality.password'),
        ]);
        if (!$login->successful()) throw new RuntimeException('Đăng nhập API không thành công. Kiểm tra tài khoản, quyền và CSRF.');
        // Refresh CSRF after authentication, as required by the vendor contract.
        $fresh = $client->get('session/csrf');
        if (!$fresh->successful() || !is_string($fresh->json('requestToken'))) throw new RuntimeException('Không lấy được CSRF sau đăng nhập.');
        try {
            return $action(function (string $path, array $query = []) use ($client): array {
                $response = $client->get($path, $query);
                if (!$response->successful()) {
                    throw new RuntimeException(match ($response->status()) {
                        401 => 'Phiên API đã hết hạn hoặc chưa đăng nhập.',
                        403 => 'Tài khoản nguồn chưa có quyền AdminOnly theo tài liệu API.',
                        404 => 'Không tìm thấy dữ liệu hoặc đường dẫn ở nguồn FaceID.',
                        default => 'Nguồn API trả lỗi HTTP '.$response->status().'. Chưa nhận dữ liệu.',
                    });
                }
                $body = $response->json();
                if (!is_array($body)) throw new RuntimeException('Nguồn không trả JSON theo tài liệu.');
                return $body;
            });
        } finally {
            $freshHeader = $fresh->json('headerName');
            if (is_string($freshHeader) && preg_match('/^[A-Za-z0-9-]+$/', $freshHeader)) {
                try { (clone $client)->withHeaders([$freshHeader=>$fresh->json('requestToken')])->post('session/logout'); } catch (\Throwable) {}
            }
        }
    }
}
