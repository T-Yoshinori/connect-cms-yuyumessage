<?php

namespace App\Plugins\User\Yuyumessage\Services;

use Illuminate\Support\Facades\Crypt;

/** Laravel APP_KEY encryption; never decrypt in model accessors or logs. */
class MessageCipher
{
    public function encrypt(array $payload): string
    {
        return Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $ciphertext): array
    {
        return json_decode(Crypt::decryptString($ciphertext), true, 512, JSON_THROW_ON_ERROR);
    }
}

