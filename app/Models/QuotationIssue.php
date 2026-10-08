<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class QuotationIssue extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['file_path'];
    protected function casts(): array { return ['issued_at' => 'datetime', 'customer_decided_at' => 'datetime']; }
}
