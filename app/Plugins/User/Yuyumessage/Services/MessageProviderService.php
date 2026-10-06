<?php

namespace App\Plugins\User\Yuyumessage\Services;

use App\Models\Common\Group;
use App\Models\Common\GroupUser;
use App\Models\User\YuyuMessage\Conversation;
use App\Models\User\YuyuMessage\Message;
use App\Models\User\YuyuMessage\Participant;
use App\User;

/** Authenticated user's data only. HTTP/page adapter is added in the UI phase. */
class MessageProviderService
{
    public function conversations(int $afterId = 0, int $limit = 50): array
    {
        $actor = (new MessageDirectoryService())->currentUserId();
        $ids = Participant::where('user_id', $actor)->whereNull('left_at')->select('conversation_id');
        $groups = GroupUser::where('user_id', $actor)->whereIn('group_id', Group::select('id'))->select('group_id');
        $candidates = Conversation::where('id', '>', max(0, $afterId))->where(function ($query) use ($ids, $groups) {
            $query->where(function ($ordinary) use ($ids) {
                $ordinary->whereIn('kind', ['direct', 'group'])->whereIn('id', $ids);
            })->orWhere(function ($cms) use ($groups) {
                $cms->where('kind', 'cms_group')->whereIn('group_id', $groups);
            });
        })->orderBy('id')->limit(max(1, min(100, $limit)))->get();
        $result = [];
        foreach ($candidates as $candidate) {
            // A removed CMS member may remain in participant snapshots: filter before returning metadata.
            if ($candidate->kind === 'cms_group' && (!Group::where('id', $candidate->group_id)->exists()
                || !GroupUser::where('group_id', $candidate->group_id)->where('user_id', $actor)->exists())) {
                continue;
            }
            $result[] = (new MessageConversationService())->authorized((int) $candidate->id, function ($conversation, $participant, $userId) {
                $visible = Message::where('conversation_id', $conversation->id)
                    ->where('sequence', '>=', $participant->join_sequence)->whereNull('deleted_at');
                $unread = (clone $visible)->where('sequence', '>', $participant->last_read_sequence)
                    ->where('sender_id', '<>', $userId)->selectRaw('COUNT(*) AS unread_count, MAX(sequence) AS unread_sequence')->first();
                $latest = (clone $visible)->orderByDesc('sequence')->first();
                $title = $conversation->title_ciphertext
                    ? (new MessageCipher())->decrypt($conversation->title_ciphertext)['title'] : '';
                if ($conversation->kind === 'direct') {
                    $otherId = Participant::where('conversation_id', $conversation->id)->where('user_id', '<>', $userId)->value('user_id');
                    $title = User::where('id', $otherId)->value('name') ?: '退会したユーザー';
                } elseif ($conversation->kind === 'cms_group') {
                    $title = Group::where('id', $conversation->group_id)->value('name');
                }
                return [
                    'id' => (int) $conversation->id, 'kind' => $conversation->kind, 'title' => $title,
                    'unread_count' => (int) $unread->unread_count, 'last_unread_sequence' => $unread->unread_sequence === null ? null : (int) $unread->unread_sequence, 'last_sequence' => $latest ? $latest->sequence : null,
                    'last_message_at' => $latest ? $latest->created_at->toIso8601String() : null,
                    'is_manager' => $participant->is_manager,
                ];
            });
        }
        return $result;
    }

    /** Compact notification metadata only; no message body or location is returned. */
    public function notifications(): array
    {
        $rows = [];
        $total = 0;
        $after = 0;
        do {
            $page = $this->conversations($after, 100);
            foreach ($page as $conversation) {
                $after = $conversation['id'];
                $total += $conversation['unread_count'];
                if ($conversation['unread_count'] > 0) {
                    $rows[] = $conversation;
                }
            }
        } while (count($page) === 100);
        usort($rows, function ($left, $right) {
            return strcmp($right['last_message_at'] ?? '', $left['last_message_at'] ?? '') ?: $right['id'] <=> $left['id'];
        });
        return ['unread_total' => $total, 'conversations' => array_slice($rows, 0, 20)];
    }

    public function unreadTotal(): int
    {
        $total = 0;
        $after = 0;
        do {
            $page = $this->conversations($after, 100);
            foreach ($page as $conversation) {
                $total += $conversation['unread_count'];
                $after = $conversation['id'];
            }
        } while (count($page) === 100);
        return $total;
    }
}

