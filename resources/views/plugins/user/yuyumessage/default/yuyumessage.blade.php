@extends('core.cms_frame_base')
@section("plugin_contents_$frame->id")
<div class="ymsg" id="ymsg-{{ $frame->id }}" data-ymsg-config="{{ json_encode($message_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}">
    <p data-role="theme-note">メッセージを利用するには、このページへYuyuMessageのCSS・JavaScriptテーマを適用してください。</p>
    <div class="ymsg-status" role="status" aria-live="polite" data-role="status"></div>
    <div class="ymsg-layout">
        <aside class="ymsg-sidebar" aria-label="会話一覧">
            <div class="ymsg-list-heading"><h2>メッセージ <span class="ymsg-badge" data-role="total" hidden></span></h2><button type="button" class="ymsg-button ymsg-primary" data-action="new">新しい会話</button></div>
            <div class="ymsg-list-tools"><button type="button" class="ymsg-button ymsg-primary" data-action="open-unread" hidden>未読の会話を開く</button><button type="button" class="ymsg-button" data-action="refresh-list">更新</button></div>
            <div data-role="list" class="ymsg-list"></div>
            <button type="button" class="ymsg-button ymsg-more" data-action="list-more" hidden>会話をさらに表示</button>
            <div class="ymsg-mail-preference"><label class="ymsg-label"><span>メール通知</span><span><input type="checkbox" data-role="mail-enabled" disabled> メール通知を受け取らない</span></label><p data-role="mail-note">設定を読み込んでいます。</p></div>
        </aside>
        <section class="ymsg-main" aria-label="会話">
            <div data-role="empty" class="ymsg-empty">相手を選んでメッセージを始めましょう。</div>
            <section data-role="create-panel" class="ymsg-panel" hidden aria-label="新しい会話">
                <div class="ymsg-panel-heading"><h2>新しい会話</h2><button type="button" class="ymsg-button" data-action="close-panel">閉じる</button></div>
                <label class="ymsg-label">会話の種類<select data-role="kind" class="ymsg-input"><option value="direct">1対1</option><option value="group">グループ会話</option><option value="cms_group">所属グループ</option></select></label>
                <label class="ymsg-label" data-role="title-field" hidden>会話名<input data-role="new-title" class="ymsg-input" maxlength="100"></label>
                <div data-role="create-picker"></div>
                <label class="ymsg-label" data-role="cms-field" hidden>所属グループ<select data-role="cms-group" class="ymsg-input"><option value="">選択してください</option></select></label>
                <button type="button" class="ymsg-button ymsg-primary" data-action="create">会話を始める</button>
            </section>
            <section data-role="members-panel" class="ymsg-panel" hidden aria-label="参加者">
                <div class="ymsg-panel-heading"><h2>参加者</h2><button type="button" class="ymsg-button" data-action="close-panel">閉じる</button></div>
                <p data-role="member-note"></p><div data-role="member-list"></div>
                <div data-role="manager-tools" hidden>
                    <form data-role="rename-form"><label class="ymsg-label">会話名<input class="ymsg-input" data-role="rename-title" maxlength="100" required></label><button class="ymsg-button" type="submit">会話名を変更</button></form>
                    <h3 class="ymsg-small-heading">参加者を追加</h3><div data-role="member-picker"></div><button type="button" class="ymsg-button ymsg-primary" data-action="add-members">選んだ人を追加</button>
                </div>
                <button type="button" class="ymsg-button ymsg-danger" data-action="leave" hidden>この会話から退会</button>
            </section>
            <section data-role="conversation" class="ymsg-conversation" hidden>
                <header class="ymsg-conversation-heading"><button type="button" class="ymsg-button ymsg-mobile-back" data-action="back">一覧へ</button><h2 data-role="conversation-title"></h2><button type="button" class="ymsg-button" data-action="members">参加者</button></header>
                <div data-role="messages" class="ymsg-messages" aria-label="メッセージ履歴" tabindex="0"><button type="button" class="ymsg-button ymsg-more" data-action="older" hidden>以前のメッセージ</button><div data-role="message-items"></div></div>
                <button type="button" class="ymsg-button ymsg-new-message" data-action="bottom" hidden>新しいメッセージを見る</button>
                <form data-role="compose" class="ymsg-compose">
                    <div data-role="editing" class="ymsg-preview" hidden>メッセージを編集中 <button type="button" class="ymsg-button" data-action="cancel-edit">編集を取り消す</button></div>
                    <div data-role="location-preview" class="ymsg-preview" hidden><span data-role="location-text"></span><a data-role="location-map" class="ymsg-button" target="_blank" rel="noopener noreferrer">送信前に地図で確認</a><button type="button" class="ymsg-button" data-action="cancel-location">取り消す</button></div>
                    <div data-role="file-preview" class="ymsg-preview" hidden></div>
                    <div data-role="plus-menu" class="ymsg-plus-menu" hidden><button type="button" class="ymsg-button" data-action="location">現在地を送る</button><button type="button" class="ymsg-button" data-action="choose-image">画像</button><button type="button" class="ymsg-button" data-action="choose-video">動画</button><button type="button" class="ymsg-button" data-action="choose-file">ファイル</button><button type="button" class="ymsg-button" data-action="take-photo">写真を撮る</button><button type="button" class="ymsg-button" data-action="take-video">動画を撮る</button></div>
                    <input type="file" data-role="choose-image" accept=".jpg,.jpeg,.png,.gif,.webp,.heic" multiple hidden>
                    <input type="file" data-role="choose-video" accept=".mp4,.m4v,.mov,.webm" multiple hidden>
                    <input type="file" data-role="choose-file" accept=".pdf,.txt,.csv,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx" multiple hidden>
                    <input type="file" data-role="take-photo" accept="image/*" capture="environment" hidden>
                    <input type="file" data-role="take-video" accept="video/*" capture="environment" hidden>
                    <div class="ymsg-compose-row"><button type="button" class="ymsg-button ymsg-plus" data-action="plus" aria-label="追加メニュー" aria-expanded="false">＋</button><label class="ymsg-compose-label"><span class="ymsg-sr-only">メッセージ</span><textarea class="ymsg-input" data-role="body" rows="2" maxlength="{{ $message_config['maxLength'] }}" placeholder="メッセージを入力"></textarea></label><button type="submit" class="ymsg-button ymsg-primary" data-role="send">送信</button></div>
                </form>
            </section>
        </section>
    </div>
    <section class="ymsg-attachment-viewer" data-role="attachment-viewer" role="dialog" aria-modal="true" aria-label="添付プレビュー" hidden><div class="ymsg-attachment-dialog"><div class="ymsg-panel-heading"><h2 data-role="attachment-title"></h2><button type="button" class="ymsg-button" data-action="close-attachment">閉じる</button></div><p>表示できない形式は、メッセージのダウンロードから保存してください。</p><div data-role="attachment-content"></div></div></section>
    <noscript>メッセージの利用にはJavaScriptを有効にしてください。</noscript>
</div>
@endsection

