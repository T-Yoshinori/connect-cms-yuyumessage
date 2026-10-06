<?php

namespace App\Plugins\User\Yuyumessage\Services;

use App\Models\User\YuyuMessage\Conversation;
use App\Models\User\YuyuMessage\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Aggregate-only preview; revoke data first, then delete private files with retryable keys. */
class MessageRetentionService
{
    private function candidates(string $cutoff, int $maxId)
    {
        return Message::where('created_at', '<', $cutoff)->where('id', '<=', $maxId)->where(function ($query) {
            $query->whereNotNull('payload_ciphertext')->orWhereExists(function ($attachments) {
                $attachments->selectRaw('1')->from('yuyu_message_attachments')->whereColumn('message_id', 'yuyu_message_messages.id');
            });
        });
    }

    public function preview(): ?array
    {
        $days = (new MessageOperationsService())->settings()['retention_days'];
        if ($days === null) {
            return null;
        }
        $cutoff = now()->subDays($days)->toDateTimeString();
        $maxId = (int) Message::max('id');
        $query = $this->candidates($cutoff, $maxId);
        $files = DB::table('yuyu_message_attachments')->whereIn('message_id', (clone $query)->select('id'));
        $preview = ['cutoff' => $cutoff, 'messages' => (clone $query)->count(),
            'attachments' => (clone $files)->count(), 'bytes' => (int) (clone $files)->sum('byte_size')];
        $preview['token'] = Crypt::encryptString(json_encode([
            'user_id' => (new MessageDirectoryService())->currentUserId(), 'days' => $days, 'cutoff' => $cutoff,
            'max_id' => $maxId, 'expires_at' => now()->addMinutes(10)->timestamp,
        ]));
        return $preview;
    }

    public function cleanup(string $token): array
    {
        try {
            $snapshot = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            abort_unless(false, 422, '削除対象をもう一度確認してください。');
        }
        $days = (new MessageOperationsService())->settings()['retention_days'];
        abort_unless($days !== null && isset($snapshot['user_id'], $snapshot['days'], $snapshot['cutoff'], $snapshot['max_id'], $snapshot['expires_at'])
            && (int) $snapshot['user_id'] === (new MessageDirectoryService())->currentUserId()
            && (int) $snapshot['days'] === $days && (int) $snapshot['expires_at'] >= now()->timestamp, 422, '保存期間が変更されたか確認が期限切れです。もう一度確認してください。');
        // A confirmation never includes messages inserted after the preview or a later cutoff.
        $query = $this->candidates(Carbon::parse($snapshot['cutoff'])->toDateTimeString(), (int) $snapshot['max_id']);
        $messages = (clone $query)->orderBy('conversation_id')->orderBy('id')->limit(100)->get(['id', 'conversation_id']);
        $result = ['messages' => 0, 'attachments' => 0, 'failed_attachments' => 0, 'remaining' => 0];
        foreach ($messages as $message) {
            $files = DB::transaction(function () use ($message, $snapshot) {
                Conversation::where('id', $message->conversation_id)->lockForUpdate()->firstOrFail();
                $current = $this->candidates($snapshot['cutoff'], (int) $snapshot['max_id'])->where('id', $message->id)->first();
                if (!$current) {
                    return null;
                }
                $current->update(['payload_ciphertext' => null, 'deleted_at' => $current->deleted_at ?: now()]);
                DB::table('yuyu_message_likes')->where('message_id', $message->id)->delete();
                DB::table('yuyu_message_attachments')->where('message_id', $message->id)->update([
                    'state' => 'purging', 'metadata_ciphertext' => (new MessageCipher())->encrypt(['deleted' => true]), 'updated_at' => now(),
                ]);
                return DB::table('yuyu_message_attachments')->where('message_id', $message->id)->get(['id', 'storage_key']);
            });
            if ($files === null) {
                continue;
            }
            $result['messages']++;
            foreach ($files as $file) {
                try {
                    (new MessageAttachmentService())->removeFiles([$file->storage_key]);
                    DB::table('yuyu_message_attachments')->where('id', $file->id)->where('state', 'purging')->delete();
                    $result['attachments']++;
                } catch (\Throwable $error) {
                    // Keep the revoked row/storage key so the next manual cleanup can retry.
                    $result['failed_attachments']++;
                }
            }
        }
        $result['remaining'] = (clone $query)->count();
        return $result;
    }
}

