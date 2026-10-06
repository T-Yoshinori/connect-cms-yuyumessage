<?php

namespace App\Plugins\User\Yuyumessage\Services;

use App\Enums\UserStatus;
use App\Mail\ConnectMail;
use App\Models\Common\Frame;
use App\Models\Common\Page;
use App\Models\User\YuyuMessage\Message;
use App\Models\User\YuyuMessage\Participant;
use App\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Notify current recipients after a newly created message has committed. */
class MessageMailService
{
    public function preference(): array
    {
        $actor = (new MessageDirectoryService())->currentUserId();
        $row = DB::table('yuyu_message_notification_preferences')->where('user_id', $actor)->first();
        return array_merge((new MessageMailSettingsService())->settings(), ['email_enabled' => $row ? (bool) $row->email_enabled : true]);
    }

    public function setPreference(bool $enabled): array
    {
        $actor = (new MessageDirectoryService())->currentUserId();
        DB::transaction(function () use ($actor, $enabled) {
            $this->ensurePreference($actor);
            DB::table('yuyu_message_notification_preferences')->where('user_id', $actor)->lockForUpdate()->first();
            DB::table('yuyu_message_notification_preferences')->where('user_id', $actor)
                ->update(['email_enabled' => $enabled, 'updated_at' => now()]);
        });
        return $this->preference();
    }

    private function ensurePreference(int $actor): void
    {
        DB::table('yuyu_message_notification_preferences')->insertOrIgnore([
            'user_id' => $actor, 'email_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** No timer or queue: called once after a new message is saved by the HTTP action. */
    public function notify(int $messageId, ?callable $deliver = null): array
    {
        $result = ['sent' => 0, 'failed_user_ids' => []];
        if (!(new MessageMailSettingsService())->settings()['mail_enabled']) {
            return $result;
        }
        $message = Message::find($messageId);
        if (!$message || $message->deleted_at) {
            return $result;
        }
        $original = Auth::user();
        try {
            $users = User::where('status', UserStatus::active)->where('id', '<>', $message->sender_id)
                ->whereIn('id', Participant::where('conversation_id', $message->conversation_id)->whereNull('left_at')->select('user_id'))
                ->orderBy('id')->get();
            foreach ($users as $user) {
                if (!filter_var(trim($user->email), FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                Auth::setUser($user);
                try {
                    // One attempt only: SMTP acceptance cannot be rolled back.
                    $sent = DB::transaction(function () use ($user, $message, $deliver) {
                        return $this->recipient($user, $message, $deliver);
                    });
                    $result['sent'] += $sent ? 1 : 0;
                } catch (HttpException $error) {
                    if (!in_array($error->getStatusCode(), [403, 404], true)) {
                        $result['failed_user_ids'][] = (int) $user->id;
                    }
                } catch (\Throwable $error) {
                    $result['failed_user_ids'][] = (int) $user->id;
                    Log::warning('YuyuMessage mail notification failed', ['user_id' => (int) $user->id, 'exception_type' => get_class($error)]);
                }
            }
        } finally {
            if ($original) {
                Auth::setUser($original);
            } else {
                Auth::forgetGuards();
            }
        }
        return $result;
    }

    private function recipient(User $user, Message $message, ?callable $deliver): bool
    {
        $actor = (int) $user->id;
        $this->ensurePreference($actor);
        $preference = DB::table('yuyu_message_notification_preferences')->where('user_id', $actor)->lockForUpdate()->first();
        $settings = (new MessageMailSettingsService())->settings();
        if (!$settings['mail_enabled'] || !$preference->email_enabled || ($preference->last_emailed_at
            && Carbon::parse($preference->last_emailed_at)->addMinutes($settings['interval_minutes'])->gt(now()))) {
            return false;
        }
        $url = $this->messageUrl();
        if (!$url) {
            return false;
        }
        return (new MessageConversationService())->authorized((int) $message->conversation_id, function ($conversation, $participant) use ($user, $message, $url, $deliver) {
            $current = Message::find($message->id);
            $previous = DB::table('yuyu_message_notification_states')->where('participant_id', $participant->id)->first();
            if (!$current || $current->deleted_at || $current->sequence < $participant->join_sequence
                || $current->sequence <= $participant->last_read_sequence
                || ($previous && $current->sequence <= $previous->notified_sequence)) {
                return false;
            }
            $deliver = $deliver ?: function ($recipient, $messageUrl) {
                Mail::to(trim($recipient->email))->send(new ConnectMail([
                    'subject' => '新しいメッセージが届きました', 'template' => 'plugins.user.yuyumessage.mail.unread',
                ], ['message_url' => $messageUrl]));
                if (Mail::failures()) {
                    throw new \RuntimeException('Mail delivery failed');
                }
            };
            $deliver($user, $url);
            DB::table('yuyu_message_notification_states')->updateOrInsert(['participant_id' => $participant->id], [
                'notified_sequence' => $current->sequence, 'first_unnotified_at' => null, 'claimed_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('yuyu_message_notification_preferences')->where('user_id', $user->id)
                ->update(['last_emailed_at' => now(), 'updated_at' => now()]);
            return true;
        });
    }

    private function messageUrl(): ?string
    {
        foreach (Frame::where('plugin_name', 'yuyumessage')->orderBy('id')->cursor() as $frame) {
            $page = Page::find($frame->page_id);
            if (!$page) {
                continue;
            }
            $tree = $page->getPageTreeByGoingBackParent(null);
            // Use a recipient-accessible placement without relying on the sender password session.
            if ($page->isVisibleAncestorsAndSelf($tree) && !$page->isRequestPasswordSetting($tree)
                && $frame->isVisible($page, Auth::user()) && !$frame->isInvisiblePrivateFrame()) {
                return url($page->permanent_link);
            }
        }
        return null;
    }
}

