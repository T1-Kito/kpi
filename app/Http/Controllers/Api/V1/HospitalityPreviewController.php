<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Hospitality\FaceIdSync;
use App\Services\Hospitality\HospitalityReader;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HospitalityPreviewController extends Controller
{
    public function status(Request $request, FaceIdSync $sync)
    {
        $tenant = (int) $request->user()->tenant_id;
        return response()->json(['data' => [
            'configured' => FaceIdSync::configured($tenant), 'read_only' => true,
            'sync' => $sync->status($tenant), 'demo_sync' => $sync->status($tenant, true),
        ]]);
    }

    private function configured(Request $request): bool
    {
        return FaceIdSync::configured((int) $request->user()->tenant_id);
    }

    public function sync(Request $request, FaceIdSync $sync)
    {
        $data = $request->validate(['demo' => 'required|boolean']);
        $tenant = (int) $request->user()->tenant_id;
        $demo = (bool) $data['demo'];
        abort_unless($demo || $this->configured($request), 422, 'Chưa cấu hình kết nối FaceID cho công ty này.');
        try {
            $sync->run($tenant, $demo);
        } catch (LockTimeoutException $error) {
            return response()->json(['message' => $error->getMessage()], 409);
        } catch (\Throwable) {
            return response()->json(['message' => 'Đồng bộ chưa thành công. Vui lòng kiểm tra kết nối và dữ liệu nguồn.'], 502);
        }
        return response()->json(['data' => $sync->status($tenant, $demo)]);
    }

    public function catalog(Request $request, FaceIdSync $sync)
    {
        $data = $request->validate([
            'resource' => 'required|in:rooms,guests,stays,devices', 'demo' => 'sometimes|boolean',
            'page' => 'sometimes|integer|min:1', 'search' => 'nullable|string|max:100',
            'unlinked' => 'sometimes|boolean', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
        ]);
        $demo = !empty($data['demo']);
        abort_unless($demo || $this->configured($request), 422, 'Chưa cấu hình kết nối FaceID cho công ty này.');
        return response()->json(['data' => $sync->catalog((int) $request->user()->tenant_id, $data['resource'], $data, $demo)]);
    }

    public function read(Request $request, HospitalityReader $reader)
    {
        $data = $request->validate([
            'resource'=>['required',Rule::in(['rooms','guests','stays','devices','commands'])],
            'page'=>['sometimes','integer','min:1'], 'pageSize'=>['sometimes','integer','min:1','max:200'],
            'search'=>['nullable','string','max:100'], 'from'=>['nullable','date_format:Y-m-d'],
            'to'=>['nullable','date_format:Y-m-d','after_or_equal:from'],
        ]);
        if (!$this->configured($request)) return response()->json(['message'=>'Chưa cấu hình sandbox cho công ty này. Bạn vẫn có thể thử bằng dữ liệu mô phỏng.'],422);
        if ($data['resource']==='stays' && (empty($data['from']) || empty($data['to']))) return response()->json(['message'=>'Chọn từ ngày và đến ngày để tra lượt lưu trú.'],422);
        $query=['page'=>$data['page'] ?? 1,'pageSize'=>$data['pageSize'] ?? 20];
        if ($data['resource']==='guests' && !empty($data['search'])) $query['search']=$data['search'];
        if ($data['resource']==='stays') {
            if (strtotime($data['to'])-strtotime($data['from'])>365*86400) return response()->json(['message'=>'Khoảng tra cứu tối đa 366 ngày.'],422);
            $query['from']=$data['from'].'T00:00:00'; $query['to']=$data['to'].'T23:59:59';
        }
        try { return response()->json(['data'=>$reader->read($data['resource'],$query),'mode'=>'sandbox']); }
        catch (\RuntimeException $e) { return response()->json(['message'=>$e->getMessage()],502); }
        catch (\Throwable) { return response()->json(['message'=>'Chưa kết nối được nguồn API. Kiểm tra mạng, chứng chỉ và cấu hình sandbox.'],502); }
    }
}
