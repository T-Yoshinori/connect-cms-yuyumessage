<?php

namespace App\Plugins\User\Yuyumessage\Services;

use App\Models\Common\Group;
use App\Models\Common\GroupUser;
use App\Models\User\YuyuMessage\Conversation;
use App\Models\User\YuyuMessage\Participant;
use Illuminate\Support\Facades\DB;

/** Internal operations require a transaction holding the conversation row lock. */
class MessageMembershipService
{
    public function join(Conversation $conversation, int $userId, bool $manager = false, ?int $sourceId = null): Participant
    {
        $participant = Participant::firstOrNew(['conversation_id' => $conversation->id, 'user_id' => $userId]);
        if ($participant->exists && !$participant->left_at) {
            return $participant;
        }
        $participant->fill([
            'is_manager' => $manager, 'join_sequence' => $conversation->last_sequence + 1,
            'last_read_sequence' => $conversation->last_sequence,
            'source_membership_id' => $sourceId, 'joined_at' => now(), 'left_at' => null,
        ])->save();
        DB::table('yuyu_message_membership_history')->insert([
            'participant_id' => $participant->id, 'join_sequence' => $participant->join_sequence,
            'joined_at' => now(),
        ]);
        return $participant;
    }

    public function leave(Conversation $conversation, Participant $participant): void
    {
        if ($participant->left_at) {
            return;
        }
        DB::table('yuyu_message_membership_history')->where('participant_id', $participant->id)
            ->whereNull('left_at')->update(['left_at' => now(), 'leave_sequence' => $conversation->last_sequence]);
        $participant->fill(['left_at' => now(), 'is_manager' => false])->save();
    }

    /** Bulk soft deletes do not fire Eloquent events: always consult current source rows. */
    public function syncGroup(Conversation $conversation): void
    {
        if ($conversation->kind !== 'cms_group') {
            return;
        }
        $sources = Group::where('id', $conversation->group_id)->exists()
            ? GroupUser::where('group_id', $conversation->group_id)->orderBy('id')->get()
            : collect();
        $sources = $sources->keyBy('user_id');
        $participants = Participant::where('conversation_id', $conversation->id)->get();
        foreach ($participants as $participant) {
            $source = $sources->get($participant->user_id);
            if (!$source || (int) $source->id !== (int) $participant->source_membership_id) {
                $this->leave($conversation, $participant);
            }
        }
        foreach ($sources as $source) {
            $this->join($conversation, (int) $source->user_id, false, (int) $source->id);
        }
    }

    public function requireParticipant(Conversation $conversation, int $userId): Participant
    {
        $participant = Participant::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)->whereNull('left_at')->first();
        abort_unless($participant, 403);
        if ($conversation->kind === 'cms_group') {
            abort_unless(Group::where('id', $conversation->group_id)->exists()
                && GroupUser::where('id', $participant->source_membership_id)
                    ->where('group_id', $conversation->group_id)->where('user_id', $userId)->exists(), 403);
        }
        return $participant;
    }
}

