<?php

namespace App\Models\User\YuyuMessage;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $table = 'yuyu_message_conversations';
    protected $guarded = ['id'];
    protected $hidden = ['title_ciphertext', 'direct_key'];
    protected $casts = ['last_sequence' => 'integer'];
    protected $attributes = ['last_sequence' => 0];
}

