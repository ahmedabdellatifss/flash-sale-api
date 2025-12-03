<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    protected $fillable = ['product_id', 'quantity', 'token', 'expires_at', 'status'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
    //
}
