<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Storage};
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function avatar(Request $request)
    {
        $path=$request->user()->avatar_path;
        $data=null;
        if ($path && Storage::disk('local')->exists($path)) {
            $mime=Storage::disk('local')->mimeType($path);
            if (in_array($mime,['image/jpeg','image/png','image/webp'],true)) $data='data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($path));
        }
        return response()->json(['data'=>['avatar'=>$data]],200,['Cache-Control'=>'private, no-store']);
    }

    public function updateAvatar(Request $request)
    {
        $request->validate(['avatar'=>['required','file','image','mimes:jpg,jpeg,png,webp','max:1024','dimensions:max_width=2048,max_height=2048']],
            ['avatar.required'=>'Chọn ảnh đại diện.','avatar.max'=>'Ảnh tối đa 1 MB.','avatar.dimensions'=>'Ảnh tối đa 2048 × 2048 điểm ảnh.','avatar.image'=>'Vui lòng chọn ảnh hợp lệ.','avatar.mimes'=>'Chọn ảnh JPG, PNG hoặc WebP.']);
        $user=$request->user();$old=$user->avatar_path;
        $path=$request->file('avatar')->store('avatars/'.$user->tenant_id,'local');
        try {
            DB::transaction(function()use($user,$path,$request){
                $user->avatar_path=$path;$user->save();
                app(AuditLogger::class)->record('user',$user->id,'update_own_avatar',$user,null,['avatar_updated'=>true],$request);
            });
        } catch (\Throwable $e) { Storage::disk('local')->delete($path);throw $e; }
        if ($old) Storage::disk('local')->delete($old);
        return $this->avatar($request);
    }

    public function password(Request $request)
    {
        $data=$request->validate(['current_password'=>'required|string','password'=>'required|string|min:8|max:128|confirmed'],
            ['current_password.required'=>'Nhập mật khẩu hiện tại.','password.required'=>'Nhập mật khẩu mới.','password.min'=>'Mật khẩu mới tối thiểu 8 ký tự.','password.max'=>'Mật khẩu tối đa 128 ký tự.','password.confirmed'=>'Hai lần nhập mật khẩu mới chưa khớp.']);
        DB::transaction(function()use($request,$data){
            $user=\App\Models\User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (!Hash::check($data['current_password'],$user->password)) throw ValidationException::withMessages(['current_password'=>'Mật khẩu hiện tại không đúng.']);
            if (Hash::check($data['password'],$user->password)) throw ValidationException::withMessages(['password'=>'Mật khẩu mới phải khác mật khẩu hiện tại.']);
            $user->password=$data['password'];$user->save();
            app(AuditLogger::class)->record('user',$user->id,'change_own_password',$user,null,['password_changed'=>true],$request);
        });
        return response()->json(['data'=>['message'=>'Đã đổi mật khẩu. Vui lòng đăng nhập lại.']]);
    }
}
