<?php

namespace App\Plugins\User\Yuyumessage\Services;

use App\Models\Common\Frame;
use App\Models\Common\Page;
use Illuminate\Support\Facades\Auth;

class MessageFrameAccessService
{
    public function authorize($request, int $pageId, int $frameId): int
    {
        $actor = (new MessageDirectoryService())->currentUserId();
        abort_unless(!$request->attributes->get('http_status_code'), 403);
        $page = Page::find($pageId);
        $frame = Frame::find($frameId);
        abort_unless($page && $frame && (int) $frame->page_id === $pageId
            && strtolower($frame->plugin_name) === 'yuyumessage', 404);
        $tree = $page->getPageTreeByGoingBackParent(null);
        abort_unless($page->isVisibleAncestorsAndSelf($tree)
            && !$page->isRequestPassword($request, $tree)
            && $frame->isVisible($page, Auth::user()) && !$frame->isInvisiblePrivateFrame(), 403);
        return $actor;
    }
}

