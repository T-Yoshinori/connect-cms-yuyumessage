<?php

namespace App\Plugins\User\Yuyumessage\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Site policy: do not change existing attachment access when upload policy changes. */
class MessageOperationsService
{
    public static function extensionGroups(): array
    {
        return [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'],
            'video' => ['mp4', 'm4v', 'mov', 'webm'],
            'file' => ['pdf', 'txt', 'csv', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
        ];
    }

    public function settings(): array
    {
        $values = DB::table('configs')->where('category', 'yuyu_message')->pluck('value', 'name');
        $settings = (new MessageMailSettingsService())->settings();
        foreach (['image', 'video', 'file'] as $kind) {
            $settings[$kind . '_enabled'] = (string) ($values['yuyu_message_' . $kind . '_enabled'] ?? '1') === '1';
            $settings[$kind . '_max_mb'] = max(1, min(1000, (int) ($values['yuyu_message_' . $kind . '_max_mb']
                ?? ceil(config('yuyu_message.' . $kind . '_max_bytes', 10485760) / 1048576))));
        }
        $settings['attachment_count'] = max(1, min(20, (int) ($values['yuyu_message_attachment_count'] ?? config('yuyu_message.attachment_count', 5))));
        $settings['attachment_total_mb'] = max(1, min(1000, (int) ($values['yuyu_message_attachment_total_mb'] ?? ceil(config('yuyu_message.attachment_total_bytes', 52428800) / 1048576))));
        $settings['edit_minutes'] = max(0, min(1440, (int) ($values['yuyu_message_edit_minutes'] ?? config('yuyu_message.edit_minutes', 15))));
        $days = $values['yuyu_message_retention_days'] ?? config('yuyu_message.retention_days');
        $settings['retention_days'] = $days === null || $days === '' ? null : max(1, min(36500, (int) $days));
        $all = array_merge(...array_values(self::extensionGroups()));
        $stored = $values['yuyu_message_allowed_extensions'] ?? null;
        $settings['allowed_extensions'] = $stored === null ? $all : array_values(array_intersect($all, json_decode($stored, true) ?: []));
        return $settings;
    }

    public function save(array $input): void
    {
        $all = array_merge(...array_values(self::extensionGroups()));
        $data = Validator::make($input, [
            'mail_enabled' => 'required|boolean', 'interval_minutes' => 'required|integer|min:1|max:1440',
            'image_enabled' => 'required|boolean', 'video_enabled' => 'required|boolean', 'file_enabled' => 'required|boolean',
            'image_max_mb' => 'required|integer|min:1|max:1000', 'video_max_mb' => 'required|integer|min:1|max:1000',
            'file_max_mb' => 'required|integer|min:1|max:1000', 'attachment_count' => 'required|integer|min:1|max:20',
            'attachment_total_mb' => 'required|integer|min:1|max:1000', 'edit_minutes' => 'required|integer|min:0|max:1440',
            'retention_days' => 'nullable|integer|min:1|max:36500', 'allowed_extensions' => 'nullable|array',
            'allowed_extensions.*' => 'string|distinct|in:' . implode(',', $all),
        ])->validate();
        $data['allowed_extensions'] = array_values($data['allowed_extensions'] ?? []);
        $data['retention_days'] = $data['retention_days'] ?? null;
        DB::transaction(function () use ($data) {
            (new MessageMailSettingsService())->save($data);
            foreach ($data as $name => $value) {
                if (in_array($name, ['mail_enabled', 'interval_minutes'], true)) {
                    continue;
                }
                DB::table('configs')->updateOrInsert(
                    ['category' => 'yuyu_message', 'name' => 'yuyu_message_' . $name],
                    ['value' => is_array($value) ? json_encode($value) : ($value === null ? '' : (string) (int) $value), 'updated_at' => now()]
                );
            }
        });
    }
}

