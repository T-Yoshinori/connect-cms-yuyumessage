<?php

namespace App\Plugins\User\Yuyumessage\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Site-wide operational settings, saved by the authorized frame editor only. */
class MessageMailSettingsService
{
    public function settings(): array
    {
        $values = DB::table('configs')->where('category', 'yuyu_message')->pluck('value', 'name');
        return [
            'mail_enabled' => (string) ($values['yuyu_message_mail_enabled'] ?? '1') === '1',
            'interval_minutes' => max(1, min(1440, (int) ($values['yuyu_message_mail_interval_minutes'] ?? config('yuyu_message.mail_interval_minutes', 60)))),
        ];
    }

    public function save(array $input): void
    {
        $data = Validator::make($input, ['mail_enabled' => 'required|boolean', 'interval_minutes' => 'required|integer|min:1|max:1440'])->validate();
        DB::transaction(function () use ($data) {
            foreach (['mail_enabled', 'interval_minutes'] as $name) {
                DB::table('configs')->updateOrInsert(
                    ['category' => 'yuyu_message', 'name' => 'yuyu_message_' . ($name === 'interval_minutes' ? 'mail_interval_minutes' : $name)],
                    ['value' => (string) (int) $data[$name], 'updated_at' => now()]
                );
            }
        });
    }
}

