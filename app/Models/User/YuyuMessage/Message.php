<?php

namespace App\Models\User\YuyuMessage;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $table = 'yuyu_message_messages';
    protected $guarded = ['id'];
    protected $hidden = ['payload_ciphertext', 'client_token'];
    protected $casts = ['sequence' => 'integer', 'edited_at' => 'datetime', 'deleted_at' => 'datetime'];
}

