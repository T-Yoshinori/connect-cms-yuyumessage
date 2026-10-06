@extends('core.cms_frame_base_setting')
@section("core.cms_frame_edit_tab_$frame->id")
@include('plugins.user.yuyumessage.yuyumessage_frame_edit_tab')
@endsection
@section("plugin_setting_$frame->id")
@include('plugins.common.flash_message')
@include('plugins.common.errors_form_line')
<form method="POST" action="{{ url('/redirect/plugin/yuyumessage/messageMailSettingsSave/'.$page->id.'/'.$frame->id) }}#frame-{{ $frame->id }}">
{{ csrf_field() }}
<p>サイト内のすべてのYuyuMessageに共通する運用設定です。メッセージの本文を閲覧する権限には影響しません。</p>
<div class="form-group"><label for="ymsg_mail_enabled_{{ $frame->id }}">メール通知</label><select class="form-control" id="ymsg_mail_enabled_{{ $frame->id }}" name="mail_enabled"><option value="1" @if((int) old('mail_enabled', $mail_settings['mail_enabled']) === 1) selected @endif>使う</option><option value="0" @if((int) old('mail_enabled', $mail_settings['mail_enabled']) === 0) selected @endif>使わない</option></select><small class="form-text text-muted">使う場合は新規メッセージの送信時に案内します。受信者は個別に受取りを辞退できます。</small></div>
<div class="form-group"><label for="ymsg_mail_interval_{{ $frame->id }}">同じ受信者へのメール通知の最短間隔（分）</label><input class="form-control" type="number" min="1" max="1440" required id="ymsg_mail_interval_{{ $frame->id }}" name="interval_minutes" value="{{ old('interval_minutes', $mail_settings['interval_minutes']) }}"><small class="form-text text-muted">初回は送信時に通知します。間隔内の投稿はメールを省略し、後から自動送信はしません。1〜1440分で指定してください。</small></div>
<fieldset class="form-group"><legend class="h5">添付ファイル</legend>
<p>変更は新規送信から適用します。既存の添付の閲覧・ダウンロードには影響しません。サーバーのアップロード上限を超える容量は受け付けられません。</p>
<input type="hidden" name="extensions_present" value="1">
@foreach (['image' => '画像', 'video' => '動画', 'file' => '一般ファイル'] as $kind => $label)
<div class="border rounded p-3 mb-3">
<div class="form-group"><label for="ymsg_{{ $kind }}_enabled_{{ $frame->id }}">{{ $label }}の添付</label><select class="form-control" id="ymsg_{{ $kind }}_enabled_{{ $frame->id }}" name="{{ $kind }}_enabled"><option value="1" @if((int) old($kind.'_enabled', $mail_settings[$kind.'_enabled']) === 1) selected @endif>許可する</option><option value="0" @if((int) old($kind.'_enabled', $mail_settings[$kind.'_enabled']) === 0) selected @endif>許可しない</option></select></div>
<div class="form-group"><label for="ymsg_{{ $kind }}_max_{{ $frame->id }}">{{ $label }}1点の容量上限（MB）</label><input class="form-control" type="number" min="1" max="1000" required id="ymsg_{{ $kind }}_max_{{ $frame->id }}" name="{{ $kind }}_max_mb" value="{{ old($kind.'_max_mb', $mail_settings[$kind.'_max_mb']) }}"></div>
<fieldset><legend class="h6">許可するファイル形式</legend>
@foreach ($extension_groups[$kind] as $extension)
<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="allowed_extensions[]" id="ymsg_ext_{{ $extension }}_{{ $frame->id }}" value="{{ $extension }}" @if(in_array($extension, (array) (session()->has('_old_input') ? old('allowed_extensions', []) : $mail_settings['allowed_extensions']), true)) checked @endif><label class="form-check-label" for="ymsg_ext_{{ $extension }}_{{ $frame->id }}">.{{ $extension }}</label></div>
@endforeach
</fieldset></div>
@endforeach
<div class="form-group"><label for="ymsg_count_{{ $frame->id }}">1メッセージの添付数上限</label><input class="form-control" type="number" min="1" max="20" required id="ymsg_count_{{ $frame->id }}" name="attachment_count" value="{{ old('attachment_count', $mail_settings['attachment_count']) }}"></div>
<div class="form-group"><label for="ymsg_total_{{ $frame->id }}">1メッセージの添付合計容量上限（MB）</label><input class="form-control" type="number" min="1" max="1000" required id="ymsg_total_{{ $frame->id }}" name="attachment_total_mb" value="{{ old('attachment_total_mb', $mail_settings['attachment_total_mb']) }}"></div>
</fieldset>
<div class="form-group"><label for="ymsg_edit_{{ $frame->id }}">送信後の編集可能時間（分）</label><input class="form-control" type="number" min="0" max="1440" required id="ymsg_edit_{{ $frame->id }}" name="edit_minutes" value="{{ old('edit_minutes', $mail_settings['edit_minutes']) }}"><small class="form-text text-muted">0は編集を許可しない設定です。本人による削除は引き続き可能です。</small></div>
<div class="form-group"><label for="ymsg_retention_{{ $frame->id }}">メッセージの保存期間（日）</label><input class="form-control" type="number" min="1" max="36500" id="ymsg_retention_{{ $frame->id }}" name="retention_days" value="{{ old('retention_days', $mail_settings['retention_days']) }}"><small class="form-text text-muted">空欄は無期限です。設定の保存だけで削除は行いません。日数を保存した後、下の削除対象を確認してください。</small></div>
<div class="form-group text-center"><a class="btn btn-secondary mr-2" href="{{ url($page->permanent_link) }}#frame-{{ $frame->id }}">キャンセル</a><button type="submit" class="btn btn-primary">保存</button></div>
</form>
<hr>
<section aria-labelledby="ymsg_cleanup_{{ $frame->id }}"><h2 class="h5" id="ymsg_cleanup_{{ $frame->id }}">保存期限を過ぎたデータの整理</h2>
<p>削除は手動です。本文・相手の名前・会話名はここには表示しません。対象はサイト内のすべてのYuyuMessageです。</p>
@if ($retention_preview === null)
<p>保存期間が無期限のため、削除対象はありません。</p>
@elseif ($retention_preview['messages'] === 0)
<p>{{ $retention_preview['cutoff'] }} より前の削除対象はありません。</p>
@else
<p>{{ $retention_preview['cutoff'] }} より前のメッセージ：{{ $retention_preview['messages'] }}件、添付：{{ $retention_preview['attachments'] }}件、添付元データ容量：約{{ number_format($retention_preview['bytes'] / 1048576, 2) }}MB。</p>
<p class="alert alert-warning">本文・位置情報・いいね・添付ファイルを削除します。取り消せません。1回につき最大100メッセージを処理します。対象が残れば再度確認して実行してください。削除表示と再送防止のための最小限の記録は残ります。</p>
<form method="POST" action="{{ url('/redirect/plugin/yuyumessage/messageRetentionCleanup/'.$page->id.'/'.$frame->id) }}#frame-{{ $frame->id }}">
{{ csrf_field() }}
<input type="hidden" name="cleanup_token" value="{{ $retention_preview['token'] }}">
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="confirm_cleanup" value="1" required id="ymsg_confirm_cleanup_{{ $frame->id }}"><label class="form-check-label" for="ymsg_confirm_cleanup_{{ $frame->id }}">削除対象を確認し、取り消せないことを理解しました。</label></div>
<button class="btn btn-danger" type="submit">確認した期限切れデータを削除</button>
</form>
@endif
</section>
@endsection

