<?php

namespace App\Plugins\User\Yuyumessage\Services;

use App\Enums\UserStatus;
use App\Models\Common\Group;
use App\Models\Common\GroupUser;
use App\Models\User\YuyuMessage\Conversation;
use App\Models\User\YuyuMessage\Participant;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MessageConversationService
{
    public function direct(int $recipientId): int
    {
        $actor = (new MessageDirectoryService())->currentUserId();
        abort_unless($recipientId !== $actor, 422);
        return DB::transaction(function () use ($actor, $recipientId) {
            $ids = [$actor, $recipientId];
            sort($ids, SORT_NUMERIC);
            // Stable user lock ordering serializes simultaneous requests for this pair on MySQL.
            $users = User::whereIn('id', $ids)->where('status', UserStatus::active)
                ->orderBy('id')->lockForUpdate()->get();
            abort_unless($users->count() === 2, 422);
            $key = implode(':', $ids);
            $conversation = Conversation::where('direct_key', $key)->lockForUpdate()->first();
            if (!$conversation) {
                $conversation = Conversation::create(['kind' => 'direct', 'direct_key' => $key, 'created_by' => $actor]);
                foreach ($ids as $id) {
                    (new MessageMembershipService())->join($conversation, $id);
                }
            }
            (new MessageMembershipService())->requireParticipant($conversation, $actor);
            return (int) $conversation->id;
        }, 3);
    }

    public function group(string $title, array $userIds): int
    {
        $actor = (new MessageDirectoryService())->currentUserId();
        $data = Validator::make(['title' => trim($title), 'users' => $userIds], [
            'title' => 'required|string|max:100', 'users' => 'required|array|min:1|max:99',
            'users.*' => 'required|integer|min:1|distinct',
        ])->validate();
        return DB::transaction(function () use ($actor, $data) {
            $ids = array_values(array_unique(array_merge([$actor], array_map('intval', $data['users']))));
            sort($ids, SORT_NUMERIC);
            abort_unless(count($ids) >= 2, 422);
            abort_unless(User::whereIn('id', $ids)->where('status', UserStatus::active)
                ->orderBy('id')->lockForUpdate()->get()->count() === count($ids), 422);
            $conversation = Conversation::create([
                'kind' => 'group', 'title_ciphertext' => (new MessageCipher())->encrypt(['title' => $data['title']]),
                'created_by' => $actor,
            ]);
            foreach ($ids as $id) {
                (new MessageMembershipService())->join($conversation, $id, $id === $actor);
            }
            return (int) $conversation->id;
        }, 3);
    }

    public function cmsGroup(int $groupId): int
    {
        $actor = (new MessageDirectoryService())->currentUserId();
        return DB::transaction(function () use ($actor, $groupId) {
            $group = Group::where('id', $groupId)->lockForUpdate()->firstOrFail();
            abort_unless(GroupUser::where('group_id', $groupId)->where('user_id', $actor)->exists(), 403);
            $conversation = Conversation::firstOrCreate(['group_id' => $groupId], [
                'kind' => 'cms_group', 'created_by' => $actor,
                'title_ciphertext' => (new MessageCipher())->encrypt(['title' => $group->name]),
            ]);
            $conversation = Conversation::where('id', $conversation->id)->lockForUpdate()->firstOrFail();
            $membership = new MessageMembershipService();
            $membership->syncGroup($conversation);
            $membership->requireParticipant($conversation, $actor);
            return (int) $conversation->id;
        }, 3);
    }

    /** No actor ID from request input: the authenticated, active CMS user is authoritative. */
    public function authorized(int $conversationId, callable $operation)
    {
        $actor = (new MessageDirectoryService())->currentUserId();
        return DB::transaction(function () use ($conversationId, $operation, $actor) {
            $conversation = Conversation::where('id', $conversationId)->lockForUpdate()->firstOrFail();
            $membership = new MessageMembershipService();
            $membership->syncGroup($conversation);
            $participant = $membership->requireParticipant($conversation, $actor);
            return $operation($conversation, $participant, $actor);
        }, 3);
    }

    public function addParticipant(int $conversationId, int $userId): void
    {
        $this->authorized($conversationId, function ($conversation, $participant) use ($userId) {
            $this->requireManager($conversation, $participant);
            abort_unless(User::where('id', $userId)->where('status', UserStatus::active)->exists(), 422);
            abort_unless(Participant::where('conversation_id', $conversation->id)->whereNull('left_at')->count() < 100
                || Participant::where('conversation_id', $conversation->id)->where('user_id', $userId)->whereNull('left_at')->exists(), 422);
            (new MessageMembershipService())->join($conversation, $userId);
        });
    }

    public function removeParticipant(int $conversationId, int $userId): void
    {
        $this->authorized($conversationId, function ($conversation, $participant, $actor) use ($userId) {
            abort_unless($conversation->kind === 'group', 403);
            abort_unless($actor === $userId || $participant->is_manager, 403);
            $target = Participant::where('conversation_id', $conversation->id)
                ->where('user_id', $userId)->whereNull('left_at')->firstOrFail();
            // Avoid an orphan group: transfer management before the manager leaves.
            abort_unless(!$target->is_manager, 422);
            (new MessageMembershipService())->leave($conversation, $target);
        });
    }

    public function transferManager(int $conversationId, int $userId): void
    {
        $this->authorized($conversationId, function ($conversation, $participant) use ($userId) {
            $this->requireManager($conversation, $participant);
            $target = (new MessageMembershipService())->requireParticipant($conversation, $userId);
            abort_unless(User::where('id', $userId)->where('status', UserStatus::active)->exists(), 422);
            Participant::where('conversation_id', $conversation->id)->update(['is_manager' => false]);
            $target->update(['is_manager' => true]);
        });
    }

    public function rename(int $conversationId, string $title): void
    {
        $data = Validator::make(['title' => trim($title)], ['title' => 'required|string|max:100'])->validate();
        $this->authorized($conversationId, function ($conversation, $participant) use ($data) {
            $this->requireManager($conversation, $participant);
            $conversation->update(['title_ciphertext' => (new MessageCipher())->encrypt($data)]);
        });
    }

    public function details(int $conversationId): array
    {
        return $this->authorized($conversationId, function ($conversation, $participant) {
            $title = $conversation->title_ciphertext ? (new MessageCipher())->decrypt($conversation->title_ciphertext)['title'] : '';
            if ($conversation->kind === 'direct') {
                $other = Participant::where('conversation_id', $conversation->id)->where('user_id', '<>', $participant->user_id)->value('user_id');
                $title = User::where('id', $other)->value('name') ?: '退会したユーザー';
            } elseif ($conversation->kind === 'cms_group') {
                $title = Group::where('id', $conversation->group_id)->value('name');
            }
            $participants = Participant::where('conversation_id', $conversation->id)->whereNull('left_at')->orderBy('id')->get();
            $users = User::whereIn('id', $participants->pluck('user_id'))->get(['id', 'name', 'status'])->keyBy('id');
            return [
                'id' => (int) $conversation->id, 'kind' => $conversation->kind, 'title' => $title,
                'is_manager' => $participant->is_manager,
                'participants' => $participants->map(function ($member) use ($users) {
                    $user = $users->get($member->user_id);
                    return [
                        'id' => (int) $member->user_id, 'name' => $user ? $user->name : '退会したユーザー',
                        'active' => $user && (int) $user->status === UserStatus::active,
                        'is_manager' => $member->is_manager,
                    ];
                })->all(),
            ];
        });
    }

    private function requireManager(Conversation $conversation, Participant $participant): void
    {
        abort_unless($conversation->kind === 'group' && $participant->is_manager, 403);
    }
}

