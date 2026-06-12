<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskStatusHistory extends Model
{
    protected $fillable = ['task_id', 'from_status', 'to_status', 'changed_by', 'reason'];
}
