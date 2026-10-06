<?php

namespace App\Models\User\YuyuMessage;

use Illuminate\Database\Eloquent\Model;

class Participant extends Model
{
    protected $table = 'yuyu_message_participants';
    protected $guarded = ['id'];
    protected $casts = [
        'is_manager' => 'boolean', 'joined_at' => 'datetime', 'left_at' => 'datetime',
        'join_sequence' => 'integer', 'last_read_sequence' => 'integer',
    ];
}

