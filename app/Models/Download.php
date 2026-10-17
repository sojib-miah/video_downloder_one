<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Download extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
        ];
    }
}
