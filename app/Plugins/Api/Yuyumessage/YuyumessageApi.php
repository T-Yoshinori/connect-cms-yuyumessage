<?php

namespace App\Plugins\Api\Yuyumessage;

use App\Models\Common\Frame;
use App\Models\Common\Page;
use App\Plugins\Api\ApiPluginBase;
use App\Plugins\User\Yuyumessage\Services\MessageDirectoryService;
use App\Plugins\User\Yuyumessage\Services\MessageFrameAccessService;
use App\Plugins\User\Yuyumessage\Services\MessageProviderService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Session-authenticated notifications through the CMS standard API plugin route. */
class YuyumessageApi extends ApiPluginBase
{
    public function getAllowedApiMethods(): array
    {
        return ['notifications'];
    }

    public function notifications($request, $arg1 = null, $arg2 = null, $arg3 = null, $arg4 = null, $arg5 = null)
    {
        abort_unless($request->isMethod('GET'), 405);
        $actor = (new MessageDirectoryService())->currentUserId();
        $access = new MessageFrameAccessService();
        foreach (Frame::where('plugin_name', 'yuyumessage')->orderBy('id')->cursor() as $frame) {
            try {
                $access->authorize($request, (int) $frame->page_id, (int) $frame->id);
            } catch (HttpException $error) {
                if (!in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }
                continue;
            }
            $data = (new MessageProviderService())->notifications();
            $data['user_id'] = $actor;
            $data['frame_id'] = (int) $frame->id;
            $data['message_page_url'] = url(Page::find($frame->page_id)->permanent_link);
            $data['available'] = true;
            return $this->privateJson($data);
        }
        return $this->privateJson(['user_id' => $actor, 'available' => false, 'unread_total' => 0, 'conversations' => []]);
    }

    private function privateJson(array $data): JsonResponse
    {
        return new JsonResponse($data, 200, ['Cache-Control' => 'no-store, private']);
    }
}

