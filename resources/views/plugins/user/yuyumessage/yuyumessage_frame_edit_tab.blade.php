<li class="nav-item"><a class="nav-link {{ ($action ?? '') === 'editView' ? 'active' : '' }}" href="{{ url('/plugin/yuyumessage/editView/'.$page->id.'/'.$frame->id) }}#frame-{{ $frame->id }}">運用設定</a></li>

