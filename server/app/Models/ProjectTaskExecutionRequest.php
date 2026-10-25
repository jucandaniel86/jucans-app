<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectTaskExecutionRequest extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'immutable_datetime'];
}
