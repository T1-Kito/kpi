<?php
namespace Tests\Feature;

use App\Models\{PrintTemplate, Tenant, User};
use App\Support\PrintTemplateSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PrintTemplateSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_selection_and_default_are_scoped_to_company_module_and_active_status(): void
    {
        $this->seed();
        $user = User::where('email', 'admin@vk-kpi.local')->firstOrFail();
        $other = Tenant::create(['code'=>'OTHER-PRINT', 'name'=>'Other', 'status'=>'active']);
        foreach (['contract', 'sales_order', 'goods_issue'] as $module) {
            $make = fn ($suffix, $extra=[]) => PrintTemplate::create(array_merge([
                'tenant_id'=>$user->tenant_id, 'code'=>$module.$suffix, 'name'=>$suffix,
                'module'=>$module, 'status'=>'active', 'is_default'=>false,
                'content_html'=>'', 'file_path'=>'template.docx', 'file_name'=>'template.docx',
            ], $extra));
            $default = $make('default', ['is_default'=>true]);
            $chosen = $make('chosen');
            $foreign = $make('foreign', ['tenant_id'=>$other->id]);
            $inactive = $make('inactive', ['status'=>'inactive']);
            $wrong = $make('wrong', ['module'=>'quotation']);
            $request = function ($id=null) use ($user) {
                $request = Request::create('/export', 'GET', $id === null ? [] : ['template_id'=>$id]);
                $request->setUserResolver(fn () => $user);
                return $request;
            };
            $this->assertSame($default->id, PrintTemplateSelection::resolve($request(), $module)->id);
            $this->assertSame($chosen->id, PrintTemplateSelection::resolve($request($chosen->id), $module)->id);
            foreach ([$foreign->id, $inactive->id, $wrong->id, 999999] as $id) {
                $this->assertNull(PrintTemplateSelection::resolve($request($id), $module));
            }
        }
    }

    public function test_malformed_template_id_is_rejected(): void
    {
        $request = Request::create('/export', 'GET', ['template_id'=>'invalid']);
        $this->expectException(ValidationException::class);
        PrintTemplateSelection::resolve($request, 'contract');
    }
}
