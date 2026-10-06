<?php

namespace App\Plugins\User\Yuyumessage;

use App\Plugins\User\UserPluginBase;
use App\Plugins\User\Yuyumessage\Services\MessageConversationService;
use App\Plugins\User\Yuyumessage\Services\MessageAttachmentService;
use App\Plugins\User\Yuyumessage\Services\MessageDirectoryService;
use App\Plugins\User\Yuyumessage\Services\MessageFrameAccessService;
use App\Plugins\User\Yuyumessage\Services\MessageMailService;
use App\Plugins\User\Yuyumessage\Services\MessageOperationsService;
use App\Plugins\User\Yuyumessage\Services\MessageRetentionService;
use App\Plugins\User\Yuyumessage\Services\MessageProviderService;
use App\Plugins\User\Yuyumessage\Services\MessageRecordService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

/**
 * @plugin_title YuyuMessage
 * @plugin_desc ログインユーザー間でメッセージを交換します。
 */
class YuyumessagePlugin extends UserPluginBase
{
    public $use_getpost = false;

    public function getPublicFunctions()
    {
        return [
            'get' => ['editView', 'messageList', 'messageNotifications', 'messageMailPreference', 'messageAttachment', 'messageSearch', 'messageGroups', 'messageHistory'],
            'post' => ['messageCreate', 'messageSend', 'messageRead', 'messageEdit', 'messageDelete', 'messageLike', 'messageMembers', 'messageMailPreferenceSave', 'messageMailSettingsSave', 'messageRetentionCleanup'],
        ];
    }

    public function declareRole()
    {
        $roles = [];
        foreach ($this->getPublicFunctions() as $functions) {
            foreach ($functions as $function) {
                $roles[$function] = [];
            }
        }
        $roles['editView'] = ['frames.edit'];
        $roles['messageMailSettingsSave'] = ['frames.edit'];
        $roles['messageRetentionCleanup'] = ['frames.edit'];
        return $roles;
    }

    public function getFirstFrameEditAction()
    {
        return 'editView';
    }

    private function mailSettingsGuard($request, $page_id, $frame_id, string $method): void
    {
        abort_unless($request->isMethod($method), 405);
        (new MessageDirectoryService())->currentUserId();
        abort_unless($this->frame && (int) $this->frame->id === (int) $frame_id
            && strtolower($this->frame->plugin_name) === 'yuyumessage', 403, '設定対象のフレームを確認できません。');
        // Editing must use the CMS frame-edit permission, not public message visibility.
        // Hidden or inherited frames remain editable by their authorized operators.
        abort_unless($this->isCan('frames.edit', null, 'yuyumessage', $this->buckets, $this->frame), 403, 'このフレームの編集権限が必要です。');
    }

    public function editView($request, $page_id, $frame_id)
    {
        $this->mailSettingsGuard($request, $page_id, $frame_id, 'GET');
        return $this->view('mail_settings', [
            'mail_settings' => (new MessageOperationsService())->settings(),
            'extension_groups' => MessageOperationsService::extensionGroups(),
            'retention_preview' => (new MessageRetentionService())->preview(),
        ]);
    }

    public function messageMailSettingsSave($request, $page_id, $frame_id)
    {
        $this->mailSettingsGuard($request, $page_id, $frame_id, 'POST');
        $service = new MessageOperationsService();
        $input = array_merge($service->settings(), $request->all());
        if ($request->has('extensions_present') && !$request->has('allowed_extensions')) {
            $input['allowed_extensions'] = [];
        }
        $service->save($input);
        $request->flash_message = '運用設定を保存しました。';
        return collect(['redirect_path' => url('/plugin/yuyumessage/editView/' . $page_id . '/' . $frame_id) . '#frame-' . $frame_id]);
    }

    public function messageRetentionCleanup($request, $page_id, $frame_id)
    {
        $this->mailSettingsGuard($request, $page_id, $frame_id, 'POST');
        $data = Validator::make($request->all(), [
            'confirm_cleanup' => 'accepted', 'cleanup_token' => 'required|string|max:8192',
        ])->validate();
        $result = (new MessageRetentionService())->cleanup($data['cleanup_token']);
        $request->flash_message = '期限切れメッセージ' . $result['messages'] . '件、添付' . $result['attachments'] . '件を削除しました。残り' . $result['remaining'] . '件。';
        if ($result['failed_attachments']) {
            $request->flash_message .= ' 添付' . $result['failed_attachments'] . '件のファイル削除が未完了です。権限を確認して再実行してください。';
        }
        return collect(['redirect_path' => url('/plugin/yuyumessage/editView/' . $page_id . '/' . $frame_id) . '#frame-' . $frame_id]);
    }

    public function index($request, $page_id, $frame_id)
    {
        if (!auth()->check()) {
            return $this->viewError('403_inframe');
        }
        $actor = (new MessageFrameAccessService())->authorize($request, (int) $page_id, (int) $frame_id);
        $selected = $request->input('ymsg_' . $frame_id . '_conversation', 0);
        $selected = is_scalar($selected) && ctype_digit((string) $selected) ? (int) $selected : 0;
        $operations = (new MessageOperationsService())->settings();
        return $this->view('yuyumessage', [
            'message_config' => [
                'apiBase' => url('/json/yuyumessage'), 'pageId' => (int) $page_id, 'frameId' => (int) $frame_id,
                'userId' => $actor, 'selectedId' => $selected,
                'conversationSeconds' => max(3, min(60, (int) config('yuyu_message.conversation_poll_seconds', 5))),
                'listSeconds' => max(10, min(300, (int) config('yuyu_message.notification_poll_seconds', 30))),
                'locationMaxAccuracy' => max(1, (int) config('yuyu_message.location_max_accuracy_meters', 100)),
                'attachmentKinds' => ['image' => $operations['image_enabled'], 'video' => $operations['video_enabled'], 'file' => $operations['file_enabled']],
                'allowedExtensions' => $operations['allowed_extensions'],
                'attachmentCount' => $operations['attachment_count'],
                'attachmentTotalBytes' => $operations['attachment_total_mb'] * 1048576,
                'imageMaxBytes' => $operations['image_max_mb'] * 1048576,
                'videoMaxBytes' => $operations['video_max_mb'] * 1048576,
                'fileMaxBytes' => $operations['file_max_mb'] * 1048576,
                'maxLength' => (int) config('yuyu_message.message_max_length', 10000),
            ],
        ]);
    }

    public function messageList($request, $page_id, $frame_id)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'GET');
        $data = Validator::make($request->all(), ['after' => 'nullable|integer|min:0'])->validate();
        $provider = new MessageProviderService();
        $rows = $provider->conversations((int) ($data['after'] ?? 0), 100);
        return $this->json($actor, ['conversations' => $rows, 'has_more' => count($rows) === 100, 'unread_total' => $provider->unreadTotal()]);
    }

    public function messageNotifications($request, $page_id, $frame_id)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'GET');
        $data = (new MessageProviderService())->notifications();
        $data['message_page_url'] = url(\App\Models\Common\Page::find($page_id)->permanent_link);
        return $this->json($actor, $data);
    }

    public function messageSearch($request, $page_id, $frame_id)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'GET');
        $this->rateLimit($actor, 'search', 60);
        $data = Validator::make($request->all(), ['name' => 'nullable|string|max:100'])->validate();
        return $this->json($actor, ['users' => (new MessageDirectoryService())->searchUsers($data['name'] ?? '')]);
    }

    public function messageMailPreference($request, $page_id, $frame_id)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'GET');
        return $this->json($actor, (new MessageMailService())->preference());
    }

    public function messageMailPreferenceSave($request, $page_id, $frame_id)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'POST');
        $data = Validator::make($request->all(), ['email_enabled' => 'required|boolean'])->validate();
        return $this->json($actor, (new MessageMailService())->setPreference((bool) $data['email_enabled']));
    }

    public function messageGroups($request, $page_id, $frame_id)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'GET');
        return $this->json($actor, ['groups' => (new MessageDirectoryService())->groups()]);
    }

    public function messageHistory($request, $page_id, $frame_id, $id = null)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'GET');
        $data = Validator::make($request->all(), [
            'before' => 'nullable|integer|min:1', 'from_sequence' => 'nullable|integer|min:1',
        ])->validate();
        abort_unless(empty($data['before']) || empty($data['from_sequence'])
            || (int) $data['before'] > (int) $data['from_sequence'], 422);
        $conversationId = $this->positiveId($id);
        $snapshot = (new MessageRecordService())->snapshot(
            $conversationId,
            isset($data['before']) ? (int) $data['before'] : null,
            isset($data['from_sequence']) ? (int) $data['from_sequence'] : null
        );
        $snapshot['conversation'] = (new MessageConversationService())->details($conversationId);
        return $this->json($actor, $snapshot);
    }

    public function messageCreate($request, $page_id, $frame_id)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'POST');
        $this->rateLimit($actor, 'create', 20);
        $data = Validator::make($request->all(), [
            'kind' => 'required|in:direct,group,cms_group', 'recipient_id' => 'required_if:kind,direct|nullable|integer|min:1',
            'group_id' => 'required_if:kind,cms_group|nullable|integer|min:1',
            'title' => 'required_if:kind,group|nullable|string|max:100',
            'users' => 'required_if:kind,group|nullable|array|max:99', 'users.*' => 'integer|min:1|distinct',
        ])->validate();
        $service = new MessageConversationService();
        if ($data['kind'] === 'direct') {
            $id = $service->direct((int) $data['recipient_id']);
        } elseif ($data['kind'] === 'cms_group') {
            $id = $service->cmsGroup((int) $data['group_id']);
        } else {
            $id = $service->group($data['title'], $data['users']);
        }
        return $this->json($actor, ['conversation_id' => $id]);
    }

    public function messageSend($request, $page_id, $frame_id, $id = null)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'POST');
        $this->rateLimit($actor, 'write', 120);
        if (is_string($request->input('location'))) {
            try {
                $request->merge(['location' => json_decode($request->input('location'), true, 512, JSON_THROW_ON_ERROR)]);
            } catch (\JsonException $error) {
                abort(422, '位置情報の形式を確認してください。');
            }
        }
        $data = Validator::make($request->all(), [
            'attachments' => 'nullable|array|max:' . (new MessageOperationsService())->settings()['attachment_count'], 'attachments.*' => 'file',
            'body' => 'nullable|string', 'client_token' => 'required|uuid', 'location' => 'nullable|array',
        ])->validate();
        $created = false;
        $message = (new MessageRecordService())->send(
            $this->positiveId($id),
            $data['body'] ?? '', $data['client_token'], $data['location'] ?? null, $request->file('attachments', []), $created
        );
        $warning = false;
        if ($created) {
            try {
                $report = app(MessageMailService::class)->notify((int) $message['id']);
                $warning = !empty($report['failed_user_ids']);
            } catch (\Throwable $error) {
                // Message and attachments are already committed. Never make SMTP failure a send failure.
                $warning = true;
                \Illuminate\Support\Facades\Log::warning('YuyuMessage mail notification failed after message commit', ['exception_type' => get_class($error)]);
            }
        }
        return $this->json($actor, ['message' => $message, 'mail_warning' => $warning]);
    }

    public function messageAttachment($request, $page_id, $frame_id, $id = null)
    {
        $this->guard($request, $page_id, $frame_id, 'GET');
        $data = Validator::make($request->all(), ['attachment_id' => 'required|integer|min:1', 'download' => 'nullable|boolean'])->validate();
        return (new MessageAttachmentService())->response($this->positiveId($id), (int) $data['attachment_id'], $request);
    }

    public function messageRead($request, $page_id, $frame_id, $id = null)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'POST');
        $data = Validator::make($request->all(), ['sequence' => 'required|integer|min:1'])->validate();
        (new MessageRecordService())->markRead($this->positiveId($id), (int) $data['sequence']);
        return $this->json($actor, ['ok' => true]);
    }

    public function messageEdit($request, $page_id, $frame_id, $id = null)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'POST');
        $this->rateLimit($actor, 'write', 120);
        $data = Validator::make($request->all(), ['message_id' => 'required|integer|min:1', 'body' => 'required|string'])->validate();
        return $this->json($actor, ['message' => (new MessageRecordService())->edit($this->positiveId($id), (int) $data['message_id'], $data['body'])]);
    }

    public function messageDelete($request, $page_id, $frame_id, $id = null)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'POST');
        $this->rateLimit($actor, 'write', 120);
        $data = Validator::make($request->all(), ['message_id' => 'required|integer|min:1'])->validate();
        (new MessageRecordService())->delete($this->positiveId($id), (int) $data['message_id']);
        return $this->json($actor, ['ok' => true]);
    }

    public function messageLike($request, $page_id, $frame_id, $id = null)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'POST');
        $this->rateLimit($actor, 'write', 120);
        $data = Validator::make($request->all(), ['message_id' => 'required|integer|min:1', 'liked' => 'required|boolean'])->validate();
        return $this->json($actor, ['message' => (new MessageRecordService())->like($this->positiveId($id), (int) $data['message_id'], (bool) $data['liked'])]);
    }

    public function messageMembers($request, $page_id, $frame_id, $id = null)
    {
        $actor = $this->guard($request, $page_id, $frame_id, 'POST');
        $this->rateLimit($actor, 'write', 120);
        $data = Validator::make($request->all(), [
            'operation' => 'required|in:add,remove,transfer,rename,leave',
            'user_id' => 'required_if:operation,add,remove,transfer|nullable|integer|min:1',
            'title' => 'required_if:operation,rename|nullable|string|max:100',
        ])->validate();
        $service = new MessageConversationService();
        $conversationId = $this->positiveId($id);
        if ($data['operation'] === 'rename') {
            $service->rename($conversationId, $data['title']);
        } elseif ($data['operation'] === 'leave') {
            $service->removeParticipant($conversationId, $actor);
        } elseif ($data['operation'] === 'add') {
            $service->addParticipant($conversationId, (int) $data['user_id']);
        } elseif ($data['operation'] === 'transfer') {
            $service->transferManager($conversationId, (int) $data['user_id']);
        } else {
            $service->removeParticipant($conversationId, (int) $data['user_id']);
        }
        return $this->json($actor, ['ok' => true]);
    }

    private function guard($request, $pageId, $frameId, string $method): int
    {
        abort_unless($request->isMethod($method), 405);
        return (new MessageFrameAccessService())->authorize($request, (int) $pageId, (int) $frameId);
    }

    private function positiveId($id): int
    {
        $data = Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        return (int) $data['id'];
    }

    private function rateLimit(int $actor, string $operation, int $limit): void
    {
        $limiter = app(RateLimiter::class);
        $key = 'yuyu-message:' . $operation . ':' . $actor;
        abort_unless(!$limiter->tooManyAttempts($key, $limit), 429);
        $limiter->hit($key, 60);
    }

    private function json(int $actor, array $data): JsonResponse
    {
        return new JsonResponse(['user_id' => $actor] + $data, 200, ['Cache-Control' => 'no-store, private']);
    }
}

