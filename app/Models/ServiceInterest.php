<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ServiceInterest extends Model
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;
    protected $fillable=['tenant_id','parent_id','name','is_active'];
    protected $casts=['is_active'=>'boolean'];
}
