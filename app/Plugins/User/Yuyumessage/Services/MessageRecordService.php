<?php

namespace App\Plugins\User\Yuyumessage\Services;

use App\Models\User\YuyuMessage\Conversation;
use App\Models\User\YuyuMessage\Message;
use App\Models\User\YuyuMessage\Participant;
use App\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MessageRecordService
{
    public function send(int $conversationId, string $body, string $clientToken, ?array $location = null, array $files = [], ?bool &$created = null): array
    {
        $created = false;
        $values = Validator::make(['body' => $body, 'token' => $clientToken, 'location' => $location], [
            'body' => 'nullable|string|max:' . config('yuyu_message.message_max_length', 10000),
            'token' => 'required|uuid', 'location' => 'nullable|array|min:4|max:4',
            'location.latitude' => 'required_with:location|numeric|between:-90,90',
            'location.longitude' => 'required_with:location|numeric|between:-180,180',
            'location.accuracy' => 'required_with:location|numeric|min:0.01|max:' . max(1, (int) config('yuyu_message.location_max_accuracy_meters', 100)),
            'location.captured_at' => 'required_with:location|string|max:64|date',
        ])->validate();
        $attachments = new MessageAttachmentService();
        $uploads = $attachments->validate($files);
        abort_unless(trim($body) !== '' || $location !== null || $uploads, 422);
        $payload = ['body' => $body, 'location' => $location === null ? null : [
            'latitude' => (float) $values['location']['latitude'],
            'longitude' => (float) $values['location']['longitude'],
            'accuracy' => (float) $values['location']['accuracy'],
            'captured_at' => $values['location']['captured_at'],
        ]];
        $written = [];
        try {
            return (new MessageConversationService())->authorized($conversationId, function ($conversation, $participant, $actor) use ($payload, $clientToken, $uploads, $attachments, &$written, &$created) {
                $token = hash('sha256', strtolower($clientToken));
                $existing = Message::where('conversation_id', $conversation->id)
                    ->where('sender_id', $actor)->where('client_token', $token)->first();
                if ($existing) {
                    // Reusing a pre-join token must not reveal an earlier message.
                    abort_unless($existing->sequence >= $participant->join_sequence, 403);
                    return $this->present($existing);
                }
                $message = Message::create([
                    'conversation_id' => $conversation->id, 'sequence' => $conversation->last_sequence + 1,
                    'sender_id' => $actor, 'client_token' => $token,
                    'payload_ciphertext' => (new MessageCipher())->encrypt($payload),
                ]);
                foreach ($uploads as $upload) {
                    $attachments->store((int) $message->id, $upload, $written);
                }
                $conversation->update(['last_sequence' => $message->sequence]);
                $created = true;
                return $this->present($message);
            });
        } catch (\Throwable $error) {
            $attachments->removeFiles($written);
            throw $error;
        }
    }

    /** after/before are conversation-local sequence cursors, never global message IDs. */
    public function messages(int $conversationId, int $after = 0, ?int $before = null, int $limit = 50): array
    {
        return (new MessageConversationService())->authorized($conversationId, function ($conversation, $participant) use ($after, $before, $limit) {
            $query = $this->visibleQuery($conversation, $participant)->where('sequence', '>', max(0, $after));
            if ($before !== null) {
                $query->where('sequence', '<', $before);
            }
            $messages = $query->orderBy('sequence')->limit(max(1, min(100, $limit)))->get();
            return $this->presentMany($messages);
        });
    }

    /** Latest window or an older page. Refresh covers at most 200 currently rendered messages. */
    public function snapshot(int $conversationId, ?int $before = null, ?int $fromSequence = null): array
    {
        return (new MessageConversationService())->authorized($conversationId, function ($conversation, $participant) use ($before, $fromSequence) {
            $query = $this->visibleQuery($conversation, $participant);
            if ($before !== null) {
                $query->where('sequence', '<', $before);
            }
            if ($fromSequence !== null) {
                $query->where('sequence', '>=', max($participant->join_sequence, $fromSequence));
            }
            $messages = $query->orderByDesc('sequence')->limit($fromSequence === null ? 50 : 200)->get()->reverse()->values();
            $first = $messages->first();
            $hasOlder = $first && $this->visibleQuery($conversation, $participant)->where('sequence', '<', $first->sequence)->exists();
            return [
                'messages' => $this->presentMany($messages),
                'has_older' => (bool) $hasOlder,
                'membership_token' => hash('sha256', $participant->id . ':' . DB::table('yuyu_message_membership_history')
                    ->where('participant_id', $participant->id)->whereNull('left_at')->max('id')),
            ];
        });
    }

    public function edit(int $conversationId, int $messageId, string $body): array
    {
        Validator::make(['body' => $body], ['body' => 'required|string|max:' . config('yuyu_message.message_max_length', 10000)])->validate();
        abort_unless(trim($body) !== '', 422);
        return (new MessageConversationService())->authorized($conversationId, function ($conversation, $participant, $actor) use ($messageId, $body) {
            $message = $this->requireMessage($conversation, $participant, $messageId);
            abort_unless((int) $message->sender_id === $actor && !$message->deleted_at, 403);
            $minutes = (new MessageOperationsService())->settings()['edit_minutes'];
            abort_unless($minutes > 0 && now()->lte($message->created_at->copy()->addMinutes($minutes)), 403);
            $payload = (new MessageCipher())->decrypt($message->payload_ciphertext);
            $payload['body'] = $body;
            $message->update(['payload_ciphertext' => (new MessageCipher())->encrypt($payload), 'edited_at' => now()]);
            return $this->present($message);
        });
    }

    public function delete(int $conversationId, int $messageId): void
    {
        $keys = (new MessageConversationService())->authorized($conversationId, function ($conversation, $participant, $actor) use ($messageId) {
            $message = $this->requireMessage($conversation, $participant, $messageId);
            abort_unless((int) $message->sender_id === $actor, 403);
            $keys = DB::table('yuyu_message_attachments')->where('message_id', $messageId)->pluck('storage_key')->all();
            if (!$message->deleted_at) {
                $message->update(['payload_ciphertext' => null, 'deleted_at' => now()]);
                DB::table('yuyu_message_likes')->where('message_id', $messageId)->delete();
                DB::table('yuyu_message_attachments')->where('message_id', $messageId)->update([
                    'state' => 'deleted', 'metadata_ciphertext' => (new MessageCipher())->encrypt(['deleted' => true]), 'updated_at' => now(),
                ]);
            }
            return $keys;
        });
        (new MessageAttachmentService())->removeFiles($keys);
    }

    /** Client supplies the last rendered sequence; validate it belongs to the visible range. */
    public function markRead(int $conversationId, int $sequence): void
    {
        (new MessageConversationService())->authorized($conversationId, function ($conversation, $participant) use ($sequence) {
            abort_unless($this->visibleQuery($conversation, $participant)->where('sequence', $sequence)->exists(), 422);
            if ($sequence > $participant->last_read_sequence) {
                $participant->update(['last_read_sequence' => $sequence]);
            }
        });
    }

    /** Set the desired state explicitly: a retried HTTP request must not toggle twice. */
    public function like(int $conversationId, int $messageId, bool $liked): array
    {
        return (new MessageConversationService())->authorized($conversationId, function ($conversation, $participant, $actor) use ($messageId, $liked) {
            $message = $this->requireMessage($conversation, $participant, $messageId);
            abort_unless(!$message->deleted_at, 403);
            $query = DB::table('yuyu_message_likes')->where('message_id', $messageId)->where('user_id', $actor);
            if ($liked && !$query->exists()) {
                DB::table('yuyu_message_likes')->insert([
                    'message_id' => $messageId, 'user_id' => $actor, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif (!$liked) {
                $query->delete();
            }
            return $this->present($message);
        });
    }

    private function visibleQuery(Conversation $conversation, Participant $participant)
    {
        return Message::where('conversation_id', $conversation->id)->where('sequence', '>=', $participant->join_sequence);
    }

    private function requireMessage(Conversation $conversation, Participant $participant, int $id): Message
    {
        return $this->visibleQuery($conversation, $participant)->where('id', $id)->firstOrFail();
    }

    private function present(Message $message): array
    {
        return $this->presentMany(collect([$message]))[0];
    }

    /** Batch names, reactions and read markers so polling does not query once per message. */
    private function presentMany($messages): array
    {
        if ($messages->isEmpty()) {
            return [];
        }
        $likes = DB::table('yuyu_message_likes')->whereIn('message_id', $messages->pluck('id'))
            ->orderBy('user_id')->get()->groupBy('message_id');
        $ids = $messages->pluck('sender_id')->merge($likes->flatten(1)->pluck('user_id'))->unique();
        $names = User::whereIn('id', $ids)->pluck('name', 'id');
        $participants = Participant::whereIn('conversation_id', $messages->pluck('conversation_id')->unique())
            ->whereNull('left_at')->get()->groupBy('conversation_id');
        $attachments = DB::table('yuyu_message_attachments')->whereIn('message_id', $messages->pluck('id'))
            ->where('state', 'ready')->orderBy('id')->get()->groupBy('message_id');
        $minutes = (new MessageOperationsService())->settings()['edit_minutes'];
        return $messages->map(function ($message) use ($likes, $names, $participants, $minutes, $attachments) {
            $payload = $message->deleted_at ? ['body' => null, 'location' => null]
                : (new MessageCipher())->decrypt($message->payload_ciphertext);
            $reactions = $message->deleted_at ? collect() : $likes->get($message->id, collect());
            $own = (int) $message->sender_id === (int) Auth::id();
            $readCount = $participants->get($message->conversation_id, collect())->filter(function ($member) use ($message) {
                return (int) $member->user_id !== (int) $message->sender_id
                    && $member->join_sequence <= $message->sequence && $member->last_read_sequence >= $message->sequence;
            })->count();
            return [
                'id' => (int) $message->id, 'sequence' => $message->sequence, 'sender_id' => (int) $message->sender_id,
                'body' => $payload['body'], 'location' => $payload['location'],
                'attachments' => $message->deleted_at ? [] : $attachments->get($message->id, collect())->map(function ($row) {
                    return (new MessageAttachmentService())->present($row);
                })->all(),
                'created_at' => $message->created_at->toIso8601String(),
                'edited_at' => $message->edited_at ? $message->edited_at->toIso8601String() : null,
                'deleted' => (bool) $message->deleted_at,
                'sender_name' => $names->get($message->sender_id, '退会したユーザー'),
                'liked_user_ids' => $reactions->map(function ($like) {
                    return (int) $like->user_id;
                })->all(),
                'liked_users' => $reactions->map(function ($like) use ($names) {
                    return ['id' => (int) $like->user_id, 'name' => $names->get($like->user_id, '退会したユーザー')];
                })->all(),
                'read_count' => $message->deleted_at ? 0 : $readCount,
                'can_edit' => $own && !$message->deleted_at && $minutes > 0
                    && now()->lte($message->created_at->copy()->addMinutes($minutes)),
                'can_delete' => $own && !$message->deleted_at,
            ];
        })->values()->all();
    }
}

