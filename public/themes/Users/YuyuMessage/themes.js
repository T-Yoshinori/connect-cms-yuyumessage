// YuyuMessage: conversation, attachments and site notifications. Edit in Theme Management.
(function () {
    'use strict';
    if (window.YuyuMessage) { window.YuyuMessage.init(); return; }
    const apps = new WeakMap();
    const el = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    const button = (text, action) => {
        const node = el('button', text, 'ymsg-button');
        node.type = 'button';
        if (action) node.dataset.action = action;
        return node;
    };
    const uuid = () => {
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, n => n.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
    };
    const date = value => value ? new Date(value).toLocaleString('ja-JP', {month:'numeric',day:'numeric',hour:'2-digit',minute:'2-digit'}) : '';

    // Optional presentation fields may be absent when older PHP code is still cached.
    // Author capabilities default to false; never infer permissions in the browser.
    function normalizeMessage(message) {
        if (!message || !Number.isInteger(Number(message.id)) || Number(message.id) < 1
            || !Number.isInteger(Number(message.sequence)) || Number(message.sequence) < 1
            || !Number.isInteger(Number(message.sender_id)) || Number(message.sender_id) < 1
            || (!message.deleted && typeof message.body !== 'string')) {
            throw new Error('メッセージの応答を確認できませんでした。ページを再読み込みしてください。');
        }
        return {...message, id:Number(message.id), sequence:Number(message.sequence), sender_id:Number(message.sender_id),
            sender_name:typeof message.sender_name === 'string' ? message.sender_name : `ユーザー #${message.sender_id}`,
            liked_user_ids:Array.isArray(message.liked_user_ids) ? message.liked_user_ids.map(Number) : [],
            attachments:Array.isArray(message.attachments) ? message.attachments : [],
            liked_users:Array.isArray(message.liked_users) ? message.liked_users : [],
            read_count:Number.isFinite(Number(message.read_count)) ? Number(message.read_count) : 0,
            can_edit:message.can_edit === true, can_delete:message.can_delete === true};
    }

    // Keep the stored body as plain text; build links with DOM methods only.
    function appendLinkedText(node, text) {
        const pattern = /https?:\/\/[^\s<>"'`「」『』]+/gi;
        let offset = 0;
        for (const match of text.matchAll(pattern)) {
            let candidate = match[0].replace(/[.,!?;:。、！？）］｝]+$/u, '');
            for (const [opening, closing] of [['(', ')'], ['[', ']'], ['{', '}']]) {
                while (candidate.endsWith(closing) && candidate.split(closing).length > candidate.split(opening).length) candidate = candidate.slice(0, -1);
            }
            let url;
            try { url = new URL(candidate); } catch (_) { continue; }
            if (!['http:', 'https:'].includes(url.protocol) || !url.hostname || url.username || url.password) continue;
            node.append(document.createTextNode(text.slice(offset, match.index)));
            const link = el('a', candidate);
            link.href = url.href; link.target = '_blank'; link.rel = 'noopener noreferrer';
            node.append(link);
            offset = match.index + candidate.length;
        }
        node.append(document.createTextNode(text.slice(offset)));
    }

    function start(root) {
        const cfg = JSON.parse(root.dataset.ymsgConfig);
        const q = name => root.querySelector(`[data-role="${name}"]`);
        const required=['choose-image','choose-video','choose-file','take-photo','take-video','file-preview','attachment-viewer','attachment-content','attachment-title'];
        if (required.some(name=>!q(name))) {
            const notice='画面テンプレートが旧版です。yuyumessage.blade.phpを更新し、php artisan view:clearを実行して再読み込みしてください。';
            const status=q('status') || root.appendChild(el('p'));
            status.textContent=notice; status.dataset.error='true';
            if (q('send')) q('send').disabled=true;
            const plus=root.querySelector('[data-action="plus"]'); if (plus) plus.disabled=true;
            return {root,destroy:()=>{}};
        }
        if (q('theme-note')) q('theme-note').hidden=true;
        const extensionGroups = {image:['jpg','jpeg','png','gif','webp','heic'],video:['mp4','m4v','mov','webm'],file:['pdf','txt','csv','zip','doc','docx','xls','xlsx','ppt','pptx']};
        const allowedExtensions = cfg.allowedExtensions || Object.values(extensionGroups).flat();
        for (const [kind, actions] of Object.entries({image:['choose-image','take-photo'],video:['choose-video','take-video'],file:['choose-file']})) {
            const permitted = extensionGroups[kind].filter(extension=>allowedExtensions.includes(extension));
            for (const action of actions) {
                const input=q(action), control=root.querySelector(`[data-action="${action}"]`);
                input.accept=permitted.map(extension=>'.'+extension).join(',');
                input.disabled=cfg.attachmentKinds?.[kind]===false || !permitted.length;
                if (control) control.hidden=input.disabled;
            }
        }

        const actionButton = name => root.querySelector(`[data-action="${name}"]`);
        const state = {id:0, epoch:0, detail:null, messages:new Map(), nodes:new Map(), drafts:new Map(),
            files:[], location:null, editing:null, editBackup:null, pending:null, sending:false, membership:null,
            pages:1, firstUnread:null, listBusy:false, polling:false, stopped:false, latest:true, windowEnd:null, marked:0,
            hasOlder:false, pollTimer:null, listTimer:null, failures:0};
        const showStatus = (text, error = false) => { q('status').textContent = text; q('status').dataset.error = String(error); };
        const clearConversation = () => {
            clearTimeout(state.pollTimer); clearTimeout(readTimer);
            state.id = 0; state.epoch++; state.messages.clear(); state.nodes.clear(); state.detail = null;
            q('message-items').textContent = ''; q('conversation-title').textContent = '';
            q('body').value = ''; state.files = []; state.location = null; state.editing = null; state.pending = null; closeAttachment();
            q('file-preview').textContent=''; q('file-preview').hidden=true;
            q('conversation').hidden = true; q('members-panel').hidden = true;
            q('create-panel').hidden = true; q('empty').hidden = false; root.classList.remove('ymsg-open');
            q('member-list').textContent = ''; q('rename-title').value = '';
        };
        const stop = () => {
            state.stopped = true; clearTimeout(state.pollTimer); clearTimeout(state.listTimer);
            state.drafts.clear(); clearConversation(); createPicker.reset(); memberPicker.reset(); q('list').textContent = ''; q('total').hidden = true;
        };
        async function api(action, id = null, data = {}, method = 'GET') {
            if (state.stopped) throw new Error('ページを再読み込みしてください。');
            const url = new URL(`${cfg.apiBase}/${action}/${cfg.pageId}/${cfg.frameId}${id ? '/' + id : ''}`, window.location.href);
            if (url.origin !== window.location.origin) throw new Error('接続先を確認してください。');
            const opts = {method, credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}};
            if (method === 'GET') Object.entries(data).forEach(([key, value]) => url.searchParams.set(key, value));
            else {
                if (!data.files?.length) opts.headers['Content-Type'] = 'application/json';
                opts.headers['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]')?.content || '';
                if (data.files?.length) {
                    const form = new FormData();
                    form.append('body',data.body); form.append('client_token',data.client_token);
                    if (data.location) form.append('location',JSON.stringify(data.location));
                    data.files.forEach(file=>form.append('attachments[]',file,file.name)); opts.body=form;
                } else opts.body = JSON.stringify(data);
            }
            const controller = new AbortController(); opts.signal = controller.signal;
            const timer = setTimeout(() => controller.abort(), data.files?.length ? 120000 : 20000);
            try {
                const response = await fetch(url, opts);
                const result = await response.json().catch(() => null);
                if (!response.ok) {
                    const messages = {403:'この操作を行う権限がありません。',404:'会話またはページが見つかりません。',
                        419:'ログイン状態が切れました。ページを再読み込みしてください。',429:'操作が続いています。少し待ってからお試しください。',
                        422:'入力内容を確認してください。'};
                    const operation = action === 'messageSend' ? 'メッセージを送信できませんでした。入力内容は保持しています。'
                        : action === 'messageEdit' ? '変更を保存できませんでした。入力内容は保持しています。'
                        : action === 'messageHistory' ? '会話を読み込めませんでした。'
                        : action === 'messageList' ? '会話一覧を読み込めませんでした。' : '操作を完了できませんでした。';
                    const error = new Error(messages[response.status] || `${operation}（HTTP ${response.status}）時間をおいて再度お試しください。`);
                    error.status = response.status;
                    if (response.status === 422 && result?.errors) error.message = Object.values(result.errors).flat().join(' ');
                    throw error;
                }
                if (!result || typeof result !== 'object' || !Number.isInteger(Number(result.user_id)) || Number(result.user_id) < 1) {
                    throw new Error('サーバーからの応答を確認できませんでした。ログイン状態を確認し、ページを再読み込みしてください。');
                }
                if (Number(result.user_id) !== cfg.userId) {
                    stop(); throw new Error('ログインユーザーが変更されました。ページを再読み込みしてください。');
                }
                if (state.stopped) throw new Error('ページを再読み込みしてください。');
                if (['messageSend','messageEdit','messageLike'].includes(action)) result.message = normalizeMessage(result.message);
                if (action === 'messageList' && !Array.isArray(result.conversations)) {
                    throw new Error('会話一覧の応答形式を確認できませんでした。更新ファイルを確認してください。');
                }
                if (action === 'messageHistory') {
                    if (!Array.isArray(result.messages) || !result.conversation || !Array.isArray(result.conversation.participants)) {
                        throw new Error('会話の応答形式を確認できませんでした。更新したPHPファイルとキャッシュを確認してください。');
                    }
                    result.messages = result.messages.map(normalizeMessage);
                }
                return result;
            } catch (error) {
                if (error.name === 'AbortError' || error instanceof TypeError) {
                    throw new Error(action === 'messageSend' || action === 'messageEdit'
                        ? '送信結果を確認できませんでした。入力内容は保持しています。接続を確認して再送してください。'
                        : 'サーバーに接続できませんでした。接続を確認して再度お試しください。');
                }
                throw error;
            } finally { clearTimeout(timer); }
        }
        function saveDraft() {
            if (state.id) state.drafts.set(state.id, {body:state.editing ? state.editBackup?.body || '' : q('body').value,
                files:state.editing ? state.editBackup?.files || [] : state.files, location:state.editing ? state.editBackup?.location || null : state.location, pending:state.pending});
        }
        function previews() {
            q('file-preview').textContent='';
            state.files.forEach((file,index)=>{
                const row=el('div',undefined,'ymsg-file-row'); row.append(el('span',`${file.name} (${Math.ceil(file.size/1024)}KB)`));
                const remove=button('取り消す','remove-file'); remove.dataset.fileIndex=index; remove.disabled=state.sending; row.append(remove); q('file-preview').append(row);
            });
            q('file-preview').hidden=!state.files.length;
            q('editing').hidden = !state.editing;
            q('location-preview').hidden = !state.location;
            q('location-text').textContent = state.location ? `取得した位置（推定誤差 約${Math.round(state.location.accuracy)}m）を添付します。` : '';
            if (state.location) q('location-map').href = 'https://www.google.com/maps?q=' + encodeURIComponent(`${state.location.latitude},${state.location.longitude}`);
            else q('location-map').removeAttribute('href');
            q('send').textContent = state.sending ? '送信中…' : state.editing ? '変更を保存' : state.pending ? '再送して確認' : '送信';
            q('send').disabled = state.sending || !state.detail; q('body').disabled = state.sending;
            actionButton('plus').disabled = !!state.editing || state.sending;
        }
        function hidePanels() {
            q('create-panel').hidden = true; q('members-panel').hidden = true;
            q('conversation').hidden = !state.id; q('empty').hidden = !!state.id;
        }
        function boundaryError(error, epoch) {
            if (epoch !== state.epoch || state.stopped) return;
            if (error.status === 403 || error.status === 404) {
                state.drafts.delete(state.id); clearConversation();
                showStatus('この会話を閲覧できなくなりました。', true); refreshList().catch(() => {});
            } else if (error.status === 419) { stop(); showStatus(error.message, true); }
            else showStatus(error.message || '通信に失敗しました。', true);
        }
        function picker(container, multiple) {
            const selected = new Map(); let version = 0;
            const form = el('form', undefined, 'ymsg-picker-search');
            const input = el('input', undefined, 'ymsg-input'); input.placeholder = 'ユーザー名で検索'; input.maxLength = 100;
            input.setAttribute('aria-label', 'ユーザー名');
            const submit = button('検索'); submit.type = 'submit'; form.append(input, submit);
            const results = el('div', undefined, 'ymsg-picker-results');
            const chips = el('div', undefined, 'ymsg-selected');
            container.append(form, results, chips);
            const render = () => {
                chips.textContent = '';
                selected.forEach(user => {
                    const chip = el('span', undefined, 'ymsg-chip'); chip.append(el('span', `${user.name} #${user.id}`));
                    const remove = button('×'); remove.setAttribute('aria-label', `${user.name}を選択から外す`);
                    remove.onclick = () => { selected.delete(user.id); render(); };
                    chip.append(remove); chips.append(chip);
                });
            };
            form.onsubmit = async event => {
                event.preventDefault(); const current = ++version; const name = input.value.trim();
                results.textContent = ''; if (!name) return;
                submit.disabled = true;
                try {
                    const data = await api('messageSearch', null, {name});
                    if (current !== version) return;
                    if (!data.users.length) results.append(el('p', '該当するユーザーはいません。'));
                    data.users.forEach(user => {
                        const row = el('div', undefined, 'ymsg-picker-result'); row.append(el('span', `${user.name} #${user.id}`));
                        const add = button('追加'); add.onclick = () => {
                            if (!multiple()) selected.clear();
                            selected.set(user.id, user); render();
                        };
                        row.append(add); results.append(row);
                    });
                    results.append(el('small', 'ユーザー番号で同姓同名を区別できます。'));
                } catch (error) { showStatus(error.message, true); }
                finally { submit.disabled = false; }
            };
            return {selected, reset() { version++; selected.clear(); input.value = ''; results.textContent = ''; render(); }, render};
        }
        const createPicker = picker(q('create-picker'), () => q('kind').value === 'group');
        const memberPicker = picker(q('member-picker'), () => true);
        async function refreshList() {
            if (state.listBusy || state.stopped) return;
            if (!root.isConnected) { stop(); return; }
            state.listBusy = true;
            try {
                let after = 0, rows = [], total = 0, hasMore = false;
                for (let page = 0; page < state.pages; page++) {
                    const data = await api('messageList', null, {after});
                    rows = rows.concat(data.conversations); total = data.unread_total; hasMore = data.has_more;
                    if (!hasMore || !data.conversations.length) break;
                    after = data.conversations[data.conversations.length - 1].id;
                }
                rows.sort((a,b) => Number(b.unread_count > 0) - Number(a.unread_count > 0) || (b.last_message_at || '').localeCompare(a.last_message_at || '') || b.id - a.id);
                state.firstUnread = rows.find(row => row.unread_count > 0)?.id || null;
                actionButton('open-unread').hidden = !state.firstUnread;
                const list = q('list'); const position = list.scrollTop; list.textContent = '';
                if (!rows.length) list.append(el('p', '会話はまだありません。', 'ymsg-panel'));
                rows.forEach(row => {
                    const node = button(undefined); node.className = 'ymsg-list-item'; node.dataset.conversation = row.id;
                    node.setAttribute('aria-current', String(row.id === state.id));
                    const title = el('span', undefined, 'ymsg-list-title'); title.append(el('span', row.title));
                    if (row.unread_count) title.append(el('span', `未読 ${row.unread_count}件`, 'ymsg-badge'));
                    node.append(title, el('span', row.last_message_at ? date(row.last_message_at) : 'メッセージなし', 'ymsg-list-date'));
                    list.append(node);
                    if (state.id === row.id && !state.latest && row.last_sequence > state.windowEnd) actionButton('bottom').hidden = false;
                });
                list.scrollTop = position; q('total').hidden = !total; q('total').textContent = total;
                actionButton('list-more').hidden = !hasMore;
            } catch (error) {
                if (error.status === 403 || error.status === 419) stop();
                showStatus(error.message, true);
            } finally { state.listBusy = false; }
        }
        function messageNode(message) {
            const own = message.sender_id === cfg.userId;
            const item = el('article', undefined, `ymsg-message${own ? ' ymsg-message-own' : ''}${message.deleted ? ' ymsg-message-deleted' : ''}`);
            item.dataset.messageId = message.id; item.dataset.sequence = message.sequence;
            item.append(el('div', own ? '自分' : message.sender_name, 'ymsg-message-author'));
            const bubble = el('div', undefined, 'ymsg-bubble');
            if (message.deleted) bubble.textContent='メッセージが削除されました。';
            else appendLinkedText(bubble, message.body);
            if (message.location && !message.deleted) {
                const link = el('a', '取得した位置を地図で開く', 'ymsg-location');
                link.href = 'https://www.google.com/maps?q=' + encodeURIComponent(`${message.location.latitude},${message.location.longitude}`);
                link.target = '_blank'; link.rel = 'noopener noreferrer';
                bubble.append(link, el('small', `${date(message.location.captured_at)}・精度 約${Math.round(message.location.accuracy)}m`, 'ymsg-location'));
            }
            if (!message.deleted) message.attachments.forEach(attachment=>{
                const row=el('div',undefined,'ymsg-attachment');
                const url=new URL(`${cfg.apiBase}/messageAttachment/${cfg.pageId}/${cfg.frameId}/${state.id}`,window.location.href);
                url.searchParams.set('attachment_id',attachment.id);
                row.append(el('span',`${attachment.name} (${Math.ceil(attachment.byte_size/1024)}KB)`));
                if (['image','video'].includes(attachment.kind)) {
                    const preview=button(attachment.kind==='image' ? '画像を開く' : '動画を再生','preview-attachment');
                    preview.dataset.messageId=message.id; preview.dataset.attachmentId=attachment.id; row.append(preview);
                }
                const download=el('a','ダウンロード','ymsg-button'); url.searchParams.set('download','1'); download.href=url.href; row.append(download); bubble.append(row);
            });
            item.append(bubble);
            const meta = el('div', undefined, 'ymsg-message-meta'); meta.append(el('time', date(message.created_at)));
            if (message.edited_at) meta.append(el('span', '編集済み'));
            if (own && message.read_count) meta.append(el('span', state.detail?.kind === 'direct' ? '既読' : `既読 ${message.read_count}`));
            item.append(meta);
            if (!message.deleted) {
                const actions = el('div', undefined, 'ymsg-message-actions');
                const like = button(`いいね ${message.liked_user_ids.length}`, 'like');
                like.dataset.messageId = message.id; like.setAttribute('aria-pressed', String(message.liked_user_ids.includes(cfg.userId)));
                actions.append(like);
                if (message.liked_users.length) { const people = button('反応した人', 'reactions'); people.dataset.messageId = message.id; actions.append(people); }
                if (message.can_edit) { const edit = button('編集', 'edit'); edit.dataset.messageId = message.id; actions.append(edit); }
                if (message.can_delete) { const remove = button('削除', 'delete'); remove.dataset.messageId = message.id; actions.append(remove); }
                item.append(actions);
            }
            return item;
        }
        function renderMessages(forceBottom = false) {
            const scroller = q('messages'); const bottom = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 45;
            const viewport = scroller.getBoundingClientRect();
            const anchor = Array.from(q('message-items').children).find(n => n.getBoundingClientRect().bottom >= viewport.top);
            const anchorId = anchor?.dataset.messageId, anchorTop = anchor?.getBoundingClientRect().top;
            const focused = document.activeElement?.dataset.messageId ? {id:document.activeElement.dataset.messageId, action:document.activeElement.dataset.action} : null;
            const fragment = document.createDocumentFragment();
            Array.from(state.messages.values()).sort((a,b) => a.sequence - b.sequence).forEach(message => {
                const signature = JSON.stringify(message); let cached = state.nodes.get(message.id);
                if (!cached || cached.signature !== signature) cached = {signature, node:messageNode(message)};
                state.nodes.set(message.id, cached); fragment.append(cached.node);
            });
            q('message-items').replaceChildren(fragment);
            for (const id of state.nodes.keys()) if (!state.messages.has(id)) state.nodes.delete(id);
            actionButton('older').hidden = !state.hasOlder;
            if (forceBottom || (bottom && state.latest)) { scroller.scrollTop = scroller.scrollHeight; actionButton('bottom').hidden = true; }
            else if (anchorId) {
                const restored = q('message-items').querySelector(`[data-message-id="${anchorId}"]`);
                if (restored) scroller.scrollTop += restored.getBoundingClientRect().top - anchorTop;
            }
            if (focused) q('message-items').querySelector(`button[data-message-id="${focused.id}"][data-action="${focused.action}"]`)?.focus({preventScroll:true});
            markVisible();
        }
        function acceptSnapshot(data, mode) {
            if (state.membership && state.membership !== data.membership_token) {
                state.messages.clear(); state.nodes.clear(); state.marked = 0; state.drafts.delete(state.id);
                q('body').value = ''; state.files = []; state.editing = null; state.location = null; closeAttachment(); state.pending = null; previews();
            }
            state.membership = data.membership_token; state.detail = data.conversation; previews();
            q('conversation-title').textContent = state.detail.title;
            const previousMax = Math.max(0, ...Array.from(state.messages.values(), m => m.sequence));
            if (mode === 'initial' || mode === 'refresh') state.messages.clear();
            data.messages.forEach(message => state.messages.set(message.id, message));
            let ordered = Array.from(state.messages.values()).sort((a,b) => a.sequence - b.sequence);
            if (ordered.length > 200) {
                ordered = mode === 'older' ? ordered.slice(0,200) : ordered.slice(-200);
                state.messages = new Map(ordered.map(m => [m.id,m]));
                if (mode === 'older') { state.latest = false; state.windowEnd = ordered[ordered.length - 1].sequence; }
            }
            state.hasOlder = data.has_older;
            if (mode === 'refresh' && data.messages.some(m => m.sequence > previousMax)
                && q('messages').scrollHeight - q('messages').scrollTop - q('messages').clientHeight >= 45) actionButton('bottom').hidden = false;
            renderMessages(mode === 'initial');
            if (!q('members-panel').hidden) renderMembers();
        }
        async function openConversation(id) {
            if (state.sending) { showStatus('送信が完了するまでお待ちください。'); return; }
            saveDraft(); closeAttachment(); clearTimeout(state.pollTimer); state.epoch++; state.id = Number(id);
            state.membership = null; state.detail = null; state.messages.clear(); state.nodes.clear(); state.marked = 0;
            state.latest = true; state.windowEnd = null; state.editing = null; state.failures = 0;
            q('message-items').textContent = ''; q('conversation-title').textContent = '読み込み中…';
            const draft = state.drafts.get(state.id) || {}; q('body').value = draft.body || '';
            state.files = draft.files || []; state.location = draft.location || null; state.pending = draft.pending || null; previews();
            hidePanels(); root.classList.add('ymsg-open'); showStatus('');
            root.querySelectorAll('[data-conversation]').forEach(node => node.setAttribute('aria-current', String(Number(node.dataset.conversation) === state.id)));
            const epoch = state.epoch, conversationId = state.id;
            try {
                const data = await api('messageHistory', conversationId);
                if (epoch !== state.epoch) return;
                acceptSnapshot(data, 'initial');
            } catch (error) { boundaryError(error, epoch); }
            finally { if (epoch === state.epoch) schedulePoll(); }
        }
        async function poll() {
            if (state.polling || !state.id || state.stopped || document.hidden) return;
            if (!root.isConnected) { stop(); return; }
            state.polling = true; const epoch = state.epoch, id = state.id;
            try {
                const rows = Array.from(state.messages.values());
                const args = rows.length ? {from_sequence:Math.min(...rows.map(m => m.sequence))} : {};
                if (!state.latest && state.windowEnd) args.before = state.windowEnd + 1;
                const data = await api('messageHistory', id, args);
                if (epoch !== state.epoch) return;
                acceptSnapshot(data, 'refresh'); state.failures = 0;
            } catch (error) { if (epoch === state.epoch) state.failures++; boundaryError(error, epoch); }
            finally { state.polling = false; schedulePoll(); }
        }
        function schedulePoll() {
            clearTimeout(state.pollTimer);
            if (state.id && !state.stopped && !document.hidden) state.pollTimer = setTimeout(poll, Math.min(60000,cfg.conversationSeconds * 1000 * Math.pow(2,Math.min(state.failures,4))));
        }
        let readTimer;
        function markVisible() {
            clearTimeout(readTimer);
            if (document.hidden || !state.id || q('conversation').hidden || state.stopped) return;
            readTimer = setTimeout(async () => {
                const rect = q('messages').getBoundingClientRect(), top = Math.max(rect.top,0), bottom = Math.min(rect.bottom,window.innerHeight);
                if (bottom <= top || document.hidden || q('conversation').hidden) return;
                let sequence = 0;
                q('message-items').querySelectorAll('[data-sequence]').forEach(node => {
                    const bounds = node.getBoundingClientRect();
                    if (bounds.bottom > top && bounds.bottom <= bottom) sequence = Math.max(sequence,Number(node.dataset.sequence));
                });
                if (sequence <= state.marked) return;
                const epoch = state.epoch, id = state.id;
                try {
                    await api('messageRead', id, {sequence}, 'POST');
                    if (epoch !== state.epoch) return;
                    state.marked = sequence; refreshList(); document.dispatchEvent(new CustomEvent('yuyu-message:read'));
                } catch (error) { boundaryError(error, epoch); }
            }, 400);
        }
        async function send(event) {
            event.preventDefault(); if (!state.id || state.sending) return;
            if (!state.detail) { showStatus('会話を読み込んでから送信してください。',true); return; }
            if ((state.editing || !state.pending) && !q('body').value.trim() && !state.location && !state.files.length) { showStatus('メッセージを入力するか、ファイル・現在地を添付してください。'); return; }
            const id = state.id, epoch = state.epoch;
            const current = {body:q('body').value, location:state.location, files:state.files.slice()};
            const editing = state.editing;
            const payload = editing ? {message_id:editing, body:current.body} : state.pending || {...current, client_token:uuid()};
            if (!editing) state.pending = payload;
            state.sending = true; let confirmed = false; previews(); showStatus('');
            try {
                const data = await api(editing ? 'messageEdit' : 'messageSend', id, payload, 'POST');
                if (epoch !== state.epoch) return;
                confirmed = true;
                if (editing) { cancelEdit(); }
                else {
                    state.pending = null;
                    if (current.body === payload.body && JSON.stringify(current.location) === JSON.stringify(payload.location) && current.files.length === (payload.files || []).length && current.files.every((file,index)=>file===payload.files[index])) {
                        q('body').value = ''; state.files = []; state.location = null; state.drafts.delete(id);
                    } else showStatus('前回の送信を確認しました。入力中の文章は残しています。');
                    state.latest = true; state.windowEnd = null;
                }
                state.messages.set(data.message.id,data.message); renderMessages(true);
                const snapshot = await api('messageHistory', id);
                if (epoch === state.epoch) acceptSnapshot(snapshot, 'initial');
                refreshList();
                if (data.mail_warning) showStatus('メッセージは送信済みです。メール通知の一部を送信できませんでした。',true);
            } catch (error) {
                if (error.status && error.status < 500 && !editing) state.pending = null;
                if (epoch !== state.epoch) return;
                showStatus(confirmed ? 'メッセージは保存されました。会話の表示を更新できませんでした。ページを再読み込みしてください。'
                    : error.message || '送信を確認できませんでした。再送して確認できます。', true);
            } finally { state.sending = false; previews(); saveDraft(); }
        }
        function cancelEdit() {
            q('body').value = state.editBackup?.body || ''; state.location = state.editBackup?.location || null; state.files = state.editBackup?.files || [];
            state.editing = null; state.editBackup = null; previews();
        }
        let previewFocus=null;
        function closeAttachment() {
            if (!q('attachment-viewer')) return;
            q('attachment-content').textContent=''; q('attachment-title').textContent=''; q('attachment-viewer').hidden=true;
            if (previewFocus?.isConnected) previewFocus.focus(); previewFocus=null;
        }
        function openAttachment(attachment,focus) {
            closeAttachment(); previewFocus=focus;
            const url=new URL(`${cfg.apiBase}/messageAttachment/${cfg.pageId}/${cfg.frameId}/${state.id}`,window.location.href);
            url.searchParams.set('attachment_id',attachment.id);
            const media=el(attachment.kind==='image' ? 'img' : 'video'); media.src=url.href;
            if (attachment.kind==='image') media.alt=attachment.name;
            else { media.controls=true; media.playsInline=true; media.preload='metadata'; media.tabIndex=0; }
            q('attachment-title').textContent=attachment.name; q('attachment-content').append(media);
            q('attachment-viewer').hidden=false; actionButton('close-attachment').focus();
        }
        for (const kind of ['choose-image','choose-video','choose-file','take-photo','take-video']) q(kind).addEventListener('change',event=>{
            if (state.sending || state.editing) { event.target.value=''; return; }
            const incoming=Array.from(event.target.files || []); event.target.value='';
            const all=state.files.concat(incoming);
            if (all.length>(cfg.attachmentCount || 5) || all.reduce((sum,file)=>sum+file.size,0)>(cfg.attachmentTotalBytes || 52428800)) {
                showStatus(`添付は最大${cfg.attachmentCount || 5}件、合計${(cfg.attachmentTotalBytes || 52428800)/1048576}MBまでです。`,true); return;
            }
            const formats=/\.(jpe?g|png|gif|webp|heic|mp4|m4v|mov|webm|pdf|txt|csv|zip|docx?|xlsx?|pptx?)$/i;
            if (incoming.some(file=>!formats.test(file.name) || !allowedExtensions.includes(file.name.split('.').pop().toLowerCase()) || cfg.attachmentKinds?.[(/\.(jpe?g|png|gif|webp|heic)$/i.test(file.name) ? 'image' : /\.(mp4|m4v|mov|webm)$/i.test(file.name) ? 'video' : 'file')]===false || !file.size || file.size>(/\.(jpe?g|png|gif|webp|heic)$/i.test(file.name) ? cfg.imageMaxBytes || 10485760 : /\.(mp4|m4v|mov|webm)$/i.test(file.name) ? cfg.videoMaxBytes || 52428800 : cfg.fileMaxBytes || 20971520))) {
                showStatus(`許可された添付形式と容量を確認してください。画像${(cfg.imageMaxBytes || 10485760)/1048576}MB・動画${(cfg.videoMaxBytes || 52428800)/1048576}MB・その他${(cfg.fileMaxBytes || 20971520)/1048576}MBまでです。`,true); return;
            }
            state.files=all; previews(); saveDraft(); showStatus('送信する添付を確認してください。');
        });
        root.addEventListener('keydown',event=>{
            if (q('attachment-viewer').hidden) return;
            if (event.key==='Escape') closeAttachment();
            else if (event.key==='Tab') {
                const nodes=Array.from(q('attachment-viewer').querySelectorAll('button,video'));
                const first=nodes[0], last=nodes[nodes.length-1];
                if (event.shiftKey && document.activeElement===first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement===last) { event.preventDefault(); first.focus(); }
            }
        });
        async function location() {
            q('plus-menu').hidden = true; actionButton('plus').setAttribute('aria-expanded','false');
            if (!navigator.geolocation) { showStatus('この端末では位置情報を取得できません。', true); return; }
            const epoch = state.epoch; state.location = null; previews(); saveDraft(); showStatus('現在地を取得しています…');
            navigator.geolocation.getCurrentPosition(position => {
                if (epoch !== state.epoch || state.editing || state.stopped) return;
                const maximum = Number(cfg.locationMaxAccuracy) || 100;
                if (!Number.isFinite(position.coords.accuracy) || position.coords.accuracy <= 0 || position.coords.accuracy > maximum) {
                    showStatus(`位置の推定誤差が約${Math.round(position.coords.accuracy)}mあるため添付しません。許容する誤差は${maximum}m以内です。位置情報を有効にしたスマートフォン等で取得してください。`,true);
                    return;
                }
                state.location = {latitude:position.coords.latitude, longitude:position.coords.longitude,
                    accuracy:position.coords.accuracy, captured_at:new Date(position.timestamp).toISOString()};
                previews(); saveDraft(); showStatus('送信前に地図で位置を確認してください。端末の推定位置であり、正確な住所を保証するものではありません。');
            }, error => { if (epoch === state.epoch) showStatus(error.code === 1 ? '位置情報の利用が許可されていません。' : '位置情報を取得できませんでした。', true); },
            {enableHighAccuracy:true,timeout:15000,maximumAge:0});
        }
        async function kindChanged() {
            createPicker.reset(); const kind = q('kind').value;
            q('title-field').hidden = kind !== 'group'; q('create-picker').hidden = kind === 'cms_group'; q('cms-field').hidden = kind !== 'cms_group';
            if (kind === 'cms_group') {
                const data = await api('messageGroups'); q('cms-group').textContent = ''; const blank = el('option','選択してください'); blank.value = ''; q('cms-group').append(blank);
                data.groups.forEach(group => { const option = el('option',group.name); option.value = group.id; q('cms-group').append(option); });
                if (!data.groups.length) showStatus('所属しているグループはありません。');
            }
        }
        async function createConversation() {
            const kind = q('kind').value, ids = Array.from(createPicker.selected.keys());
            if ((kind === 'direct' && ids.length !== 1) || (kind === 'group' && (!ids.length || !q('new-title').value.trim()))
                || (kind === 'cms_group' && !q('cms-group').value)) { showStatus('相手と必要な会話名を指定してください。', true); return; }
            const btn = actionButton('create'); btn.disabled = true;
            try {
                const data = await api('messageCreate',null,{kind,recipient_id:kind === 'direct' ? ids[0] : null,
                    users:kind === 'group' ? ids : null,title:kind === 'group' ? q('new-title').value : null,
                    group_id:kind === 'cms_group' ? Number(q('cms-group').value) : null},'POST');
                await openConversation(data.conversation_id); await refreshList();
            } finally { btn.disabled = false; }
        }
        function renderMembers() {
            if (!state.detail) return;
            const detail = state.detail; q('member-list').textContent = '';
            q('member-note').textContent = detail.kind === 'cms_group' ? '所属グループのメンバーが参加します。所属の変更はグループ管理で行います。' : '途中から参加した人は、参加以降のメッセージを閲覧できます。';
            q('manager-tools').hidden = detail.kind !== 'group' || !detail.is_manager;
            actionButton('leave').hidden = detail.kind !== 'group';
            actionButton('leave').disabled = detail.is_manager;
            if (detail.kind === 'group' && detail.is_manager) q('member-note').textContent += ' 退会する前に管理を引き継いでください。';
            q('rename-title').value = detail.title;
            detail.participants.forEach(member => {
                const row = el('div',undefined,'ymsg-member'); row.append(el('span',`${member.name} #${member.id}${member.is_manager ? '（会話管理者）' : ''}${!member.active ? '（利用停止）' : ''}`));
                if (detail.kind === 'group' && detail.is_manager && member.id !== cfg.userId) {
                    const controls = el('span');
                    if (member.active) { const transfer = button('管理を引き継ぐ','transfer'); transfer.dataset.userId = member.id; controls.append(transfer); }
                    const remove = button('除外','remove-member'); remove.dataset.userId = member.id; controls.append(remove); row.append(controls);
                }
                q('member-list').append(row);
            });
        }
        async function memberOperation(operation,userId = null,title = null) {
            const id = state.id, epoch = state.epoch;
            await api('messageMembers',id,{operation,user_id:userId,title},'POST');
            if (epoch !== state.epoch) return;
            if (operation === 'leave') { state.drafts.delete(id); clearConversation(); await refreshList(); return; }
            const data = await api('messageHistory',id);
            if (epoch === state.epoch) { state.detail = data.conversation; q('conversation-title').textContent = state.detail.title; renderMembers(); }
            refreshList();
        }
        root.addEventListener('click',async event => {
            const target = event.target.closest('button'); if (!target || !root.contains(target)) return;
            if (target.dataset.conversation) { openConversation(target.dataset.conversation); return; }
            const action = target.dataset.action;
            if (state.sending && ['choose-image','choose-video','choose-file','take-photo','take-video','remove-file','open-unread','refresh-list','new','back','members','close-panel','bottom','edit','cancel-edit','cancel-location','create'].includes(action)) return;
            const message = state.messages.get(Number(target.dataset.messageId));
            try {
                if (action === 'new') {
                    if (state.sending) return; saveDraft(); createPicker.reset(); q('new-title').value = ''; q('kind').value = 'direct';
                    await kindChanged(); q('conversation').hidden = true; q('members-panel').hidden = true; q('empty').hidden = true; q('create-panel').hidden = false; root.classList.add('ymsg-open');
                } else if (action === 'close-panel') { hidePanels(); if (!state.id) root.classList.remove('ymsg-open'); markVisible(); }
                else if (action === 'back') { saveDraft(); clearConversation(); refreshList(); }
                else if (action === 'open-unread' && state.firstUnread) await openConversation(state.firstUnread);
                else if (action === 'refresh-list') await refreshList();
                else if (action === 'list-more') { state.pages++; await refreshList(); }
                else if (action === 'create') await createConversation();
                else if (action === 'plus') { q('plus-menu').hidden = !q('plus-menu').hidden; target.setAttribute('aria-expanded',String(!q('plus-menu').hidden)); }
                else if (['choose-image','choose-video','choose-file','take-photo','take-video'].includes(action)) {
                    if (state.editing || state.sending) return;
                    q(action).click();
                }
                else if (action === 'remove-file') { state.files.splice(Number(target.dataset.fileIndex),1); previews(); saveDraft(); }
                else if (action === 'preview-attachment') {
                    const attachment=state.messages.get(Number(target.dataset.messageId))?.attachments.find(a=>a.id===Number(target.dataset.attachmentId));
                    if (attachment) openAttachment(attachment,target);
                }
                else if (action === 'close-attachment') closeAttachment();
                else if (action === 'location') location();
                else if (action === 'cancel-location') { state.location = null; previews(); saveDraft(); }
                else if (action === 'cancel-edit') cancelEdit();
                else if (action === 'members') { memberPicker.reset(); hidePanels(); q('conversation').hidden = true; q('members-panel').hidden = false; renderMembers(); }
                else if (action === 'older') {
                    target.disabled = true; const epoch = state.epoch, first = Math.min(...Array.from(state.messages.values(),m => m.sequence));
                    const data = await api('messageHistory',state.id,{before:first});
                    if (epoch === state.epoch) acceptSnapshot(data,'older');
                } else if (action === 'bottom') {
                    if (!state.latest) await openConversation(state.id);
                    else { q('messages').scrollTop = q('messages').scrollHeight; target.hidden = true; markVisible(); }
                } else if (action === 'edit' && message) {
                    if (state.sending) return;
                    if (!state.editing) state.editBackup = {body:q('body').value,location:state.location,files:state.files.slice()};
                    state.editing = message.id; state.files = []; state.location = null; q('body').value = message.body; previews(); q('body').focus();
                } else if (action === 'reactions' && message) showStatus('いいね：' + message.liked_users.map(user => user.name).join('、'));
                else if (action === 'like' && message) {
                    target.disabled = true; const epoch = state.epoch;
                    const data = await api('messageLike',state.id,{message_id:message.id,liked:!message.liked_user_ids.includes(cfg.userId)},'POST');
                    if (epoch === state.epoch) { state.messages.set(message.id,data.message); renderMessages(); }
                } else if (action === 'delete' && message && window.confirm('このメッセージを削除しますか？')) {
                    await api('messageDelete',state.id,{message_id:message.id},'POST'); await poll(); refreshList();
                } else if (action === 'remove-member' && window.confirm('この参加者を会話から除外しますか？')) await memberOperation('remove',Number(target.dataset.userId));
                else if (action === 'transfer' && window.confirm('この参加者に会話の管理を引き継ぎますか？')) await memberOperation('transfer',Number(target.dataset.userId));
                else if (action === 'leave' && window.confirm('この会話から退会しますか？')) await memberOperation('leave');
                else if (action === 'add-members') {
                    target.disabled = true;
                    const epoch = state.epoch;
                    for (const userId of Array.from(memberPicker.selected.keys())) {
                        if (epoch !== state.epoch) break;
                        await memberOperation('add',userId); memberPicker.selected.delete(userId); memberPicker.render();
                    }
                }
            } catch (error) { showStatus(error.message || '操作に失敗しました。',true); }
            finally { if (target.isConnected) target.disabled = false; }
        });
        q('compose').addEventListener('submit',send);
        q('body').addEventListener('input',saveDraft);
        q('kind').addEventListener('change',() => kindChanged().catch(error => showStatus(error.message,true)));
        q('rename-form').addEventListener('submit',event => { event.preventDefault(); memberOperation('rename',null,q('rename-title').value).catch(error => showStatus(error.message,true)); });
        q('messages').addEventListener('scroll',markVisible,{passive:true});
        window.addEventListener('scroll',markVisible,{passive:true}); window.addEventListener('resize',markVisible);
        function scheduleList() {
            clearTimeout(state.listTimer);
            if (!state.stopped && !document.hidden) state.listTimer = setTimeout(async () => { await refreshList(); scheduleList(); },cfg.listSeconds * 1000);
        }
        document.addEventListener('visibilitychange',() => {
            clearTimeout(state.pollTimer); clearTimeout(state.listTimer); clearTimeout(readTimer);
            if (!document.hidden && !state.stopped) { refreshList().then(scheduleList); poll(); markVisible(); }
        });
        const app = {root, destroy:stop}; apps.set(root,app);
        const mail = q('mail-enabled'), mailNote = q('mail-note');
        if (mail && mailNote) {
            const showMail = data => {
                if (typeof data.mail_enabled !== 'boolean' || typeof data.email_enabled !== 'boolean' || !Number.isInteger(data.interval_minutes)) throw new Error('メール通知の設定を確認できませんでした。');
                mail.checked = !data.email_enabled;
                mailNote.textContent = !data.mail_enabled ? '運用者がメール通知を無効にしています。' : `新しいメッセージの送信時に案内します。同じ受信者への通知は最短${data.interval_minutes}分間隔です。`;
                mail.disabled = !data.mail_enabled;
            };
            api('messageMailPreference').then(showMail).catch(error => { mailNote.textContent = error.message; });
            mail.addEventListener('change',async () => {
                const previous = !mail.checked; mail.disabled = true;
                try { showMail(await api('messageMailPreferenceSave',null,{email_enabled:!mail.checked},'POST')); }
                catch (error) { mail.checked = previous; if (!state.stopped) mail.disabled = false; showStatus(error.message,true); }
            });
        }
        refreshList().then(scheduleList);
        if (cfg.selectedId) openConversation(cfg.selectedId);
        return app;
    }
    const init = () => document.querySelectorAll('[data-ymsg-config]').forEach(root => { if (!apps.has(root)) start(root); });
    window.YuyuMessage = {init};
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',init); else init();
})();

// Site notifications
(function () {
    'use strict';
    function start() {
        if (window.YuyuMessageNotifications) return;
        // Editable in Connect-CMS Theme Management; no page/frame number setup required.
        const intervalSeconds = 30;
        const logout = document.querySelector('#logout-form');
        const frame = document.querySelector('[data-ymsg-config]');
        if (!logout && !frame) return;
        let apiBase;
        if (logout) apiBase = new URL('./api/yuyumessage/notifications', logout.action);
        else {
            const local = JSON.parse(frame.dataset.ymsgConfig);
            apiBase = new URL(local.apiBase.replace(/\/json\/yuyumessage\/?$/, '/api/yuyumessage/notifications'), window.location.href);
        }
        const node=(tag,text) => { const value=document.createElement(tag); if (text!==undefined) value.textContent=text; return value; };
        const root=node('aside'); root.className='ymsg-notice'; root.setAttribute('aria-label','メッセージ通知'); root.hidden=true;
        const panel=node('section'); panel.className='ymsg-notice-panel'; panel.hidden=true; panel.setAttribute('role','status'); panel.setAttribute('aria-live','polite');
        const heading=node('div'); heading.className='ymsg-notice-heading'; heading.append(node('h2','未読のメッセージ'));
        const close=node('button','閉じる'); close.type='button'; close.className='ymsg-notice-close'; close.onclick=()=>{panel.hidden=true;}; heading.append(close);
        const rows=node('div'); panel.append(heading,rows);
        const entry=node('a','メッセージ'); entry.className='ymsg-notice-link'; root.append(panel,entry); document.body.append(root);
        let frameId=null, actor=null, seen={}, timer=null, busy=false, stopped=false, failures=0, pageURL=null, controller=null, refreshAgain=false;
        const interval=Math.max(10,Math.min(300,intervalSeconds))*1000;
        const endpoint=apiBase;
        if (endpoint.origin!==window.location.origin) { root.remove(); return; }
        function stop() {
            stopped=true; clearTimeout(timer); if (controller) controller.abort();
            root.hidden=true; rows.textContent=''; panel.hidden=true; seen={}; entry.textContent='メッセージ'; entry.removeAttribute('href');
        }
        function seenKey() { return `yuyu-message-notice-seen:${actor}`; }
        function loadSeen() {
            try {
                const data=JSON.parse(sessionStorage.getItem(seenKey())||'{}');
                if (!data || typeof data!=='object' || Array.isArray(data)) return;
                for (const [id,sequence] of Object.entries(data)) {
                    if (/^[1-9][0-9]*$/.test(id) && Number.isSafeInteger(sequence) && sequence>0) seen[id]=sequence;
                }
            } catch (_) { /* Session storage may be disabled; in-memory deduplication still works. */ }
        }
        function saveSeen() {
            const keys=Object.keys(seen);
            for (const id of keys.slice(0,Math.max(0,keys.length-200))) delete seen[id];
            try { sessionStorage.setItem(seenKey(),JSON.stringify(seen)); } catch (_) { /* Optional metadata cache. */ }
        }
        function linkTo(id) {
            const url=new URL(pageURL.href);
            url.searchParams.set(`ymsg_${frameId}_conversation`,String(id)); url.hash=`frame-${frameId}`;
            return url.href;
        }
        function schedule() {
            clearTimeout(timer);
            if (!stopped && !document.hidden) timer=setTimeout(poll,Math.min(300000,interval*Math.pow(2,Math.min(failures,3))));
        }
        async function poll() {
            if (busy) { refreshAgain=true; return; }
            if (stopped || document.hidden) return;
            busy=true; controller=new AbortController();
            const timeout=setTimeout(()=>controller.abort(),20000);
            try {
                const response=await fetch(endpoint.href,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal});
                if (!response.ok) {
                    if ([403,404,419].includes(response.status)) stop();
                    throw new Error(`通知API: HTTP ${response.status}。メッセージページの権限と更新ファイルを確認してください。`);
                }
                const data=await response.json();
                if (!Number.isSafeInteger(Number(data.user_id)) || Number(data.user_id)<1 || !Array.isArray(data.conversations)
                    || !Number.isSafeInteger(Number(data.unread_total)) || Number(data.unread_total)<0) throw new Error('通知APIの応答形式を確認できません。');
                if (stopped || document.hidden) return;
                if (actor!==null && actor!==Number(data.user_id)) { stop(); return; }
                if (actor===null) { actor=Number(data.user_id); loadSeen(); }
                if (data.available===false) { root.hidden=true; panel.hidden=true; rows.textContent=''; entry.textContent='メッセージ'; entry.removeAttribute('href'); failures=0; return; }
                frameId=Number(data.frame_id);
                if (!Number.isSafeInteger(frameId) || frameId<1) throw new Error('通知のフレームを確認できません。');
                const target=data.message_page_url;
                if (typeof target!=='string' || !target) throw new Error('通知のリンク先がありません。');
                const url=new URL(target,window.location.href);
                if (url.origin!==window.location.origin) throw new Error('通知のリンク先を確認してください。');
                pageURL=url;
                entry.href=pageURL.href; entry.textContent=data.unread_total ? `メッセージ 未読 ${data.unread_total}件` : 'メッセージ';
                entry.setAttribute('aria-label',entry.textContent); root.hidden=false;
                const fresh=[];
                for (const conversation of data.conversations) {
                    const sequence=Number(conversation.last_unread_sequence), id=Number(conversation.id);
                    if (!Number.isSafeInteger(id) || id<1 || !Number.isSafeInteger(sequence) || sequence<1 || Number(conversation.unread_count)<=0) continue;
                    if (sequence>(seen[id]||0)) fresh.push(conversation);
                    seen[id]=Math.max(seen[id]||0,sequence);
                }
                if (!data.unread_total) { panel.hidden=true; rows.textContent=''; }
                else if (fresh.length && !document.hidden) {
                    rows.textContent='';
                    fresh.slice(0,3).forEach(conversation=>{
                        const link=node('a',`${conversation.title}：未読 ${conversation.unread_count}件`);
                        link.className='ymsg-notice-row'; link.href=linkTo(conversation.id); rows.append(link);
                    });
                    if (fresh.length>3) rows.append(node('p',`ほか${fresh.length-3}件の会話にも新着があります。`));
                    panel.hidden=false;
                }
                saveSeen(); failures=0;
            } catch (error) {
                if (!stopped) { failures++; console.warn('YuyuMessage通知:',error.message); }
            } finally {
                clearTimeout(timeout); busy=false; controller=null;
                if (refreshAgain && !stopped && !document.hidden) { refreshAgain=false; poll(); }
                else { refreshAgain=false; schedule(); }
            }
        }
        document.addEventListener('visibilitychange',()=>{
            clearTimeout(timer);
            if (!document.hidden && !stopped) poll();
        });
        document.addEventListener('yuyu-message:read',()=>{ clearTimeout(timer); poll(); });
        window.YuyuMessageNotifications={refresh:poll,destroy:stop};
        poll();
    }
    if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',start,{once:true}); else start();
})();

