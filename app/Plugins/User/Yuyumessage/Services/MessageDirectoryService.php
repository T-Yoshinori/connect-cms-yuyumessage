<?php

namespace App\Plugins\User\Yuyumessage\Services;

use App\Enums\UserStatus;
use App\Models\Common\Group;
use App\Models\Common\GroupUser;
use App\User;
use Illuminate\Support\Facades\Auth;

class MessageDirectoryService
{
    public function currentUserId(): int
    {
        $id = Auth::id();
        abort_unless($id && User::where('id', $id)->where('status', UserStatus::active)->exists(), 403);
        return (int) $id;
    }

    public function searchUsers(string $name): array
    {
        $actor = $this->currentUserId();
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            return [];
        }
        // Use ! as SQL LIKE escape so %/_ in a name cannot enumerate all users.
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $name) . '%';
        return User::where('status', UserStatus::active)->where('id', '<>', $actor)
            ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orderBy('name')->orderBy('id')
            ->limit(max(1, min(50, (int) config('yuyu_message.search_limit', 20))))
            ->get(['id', 'name'])->toArray();
    }

    public function groups(): array
    {
        $actor = $this->currentUserId();
        return Group::whereIn('id', GroupUser::where('user_id', $actor)->select('group_id'))
            ->orderBy('name')->get(['id', 'name'])->toArray();
    }
}

