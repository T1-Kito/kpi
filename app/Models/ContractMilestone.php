<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ContractMilestone extends Model { protected $fillable=['contract_id','name','amount','due_date','status','accepted_date','note']; protected function casts():array{return ['due_date'=>'date','accepted_date'=>'date','amount'=>'decimal:2'];} public function contract():BelongsTo{return $this->belongsTo(Contract::class);} }
