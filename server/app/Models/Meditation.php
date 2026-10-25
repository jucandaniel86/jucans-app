<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Meditation extends Model
{
    protected $fillable = ['title', 'audio', 'category'];
}
