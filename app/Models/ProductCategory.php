<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductCategory extends Model {
    use \App\Models\Concerns\CastsDatabaseIdentifiers;
 protected $fillable = ['tenant_id', 'name', 'status']; }
