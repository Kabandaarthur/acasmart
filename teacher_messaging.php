<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teachers Group Chat</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen">
    <header class="bg-gradient-to-r from-slate-800 to-blue-700 text-white shadow">
        <div class="max-w-4xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-white/15 flex items-center justify-center shadow-inner">
                    <i class="fas fa-comments text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight">Group Chat</h1>
                    <p class="text-white/80 text-sm">Chat with teachers</p>
                </div>
            </div>
            <a href="teacher_dashboard.php" class="inline-flex items-center gap-2 px-4 h-11 rounded-lg border border-white/30 hover:border-white/60 transition text-white">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Dashboard</span>
            </a>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 py-6">
        <section class="bg-white rounded-2xl border border-slate-100 shadow-sm">
            <div class="px-5 py-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="fas fa-comments text-blue-600"></i>
                    <h3 class="text-lg font-semibold text-slate-900">School Chat</h3>
                </div>
                <button id="refreshChatBtn" class="inline-flex items-center gap-2 px-3 h-10 rounded-lg border border-slate-300 hover:bg-slate-100 text-slate-700">
                    <i class="fas fa-sync"></i>
                    <span>Refresh</span>
                </button>
            </div>
            <div class="px-5 pb-4">
                <div id="chatMessages" class="h-[70vh] overflow-y-auto bg-slate-50 rounded-lg p-4 border border-slate-100"></div>
                <div class="mt-4 flex gap-2">
                    <input id="chatInput" type="text" placeholder="Type a message..." class="flex-1 h-11 px-3 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                    <button id="sendChatBtn" class="inline-flex items-center gap-2 px-4 h-11 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                        <i class="fas fa-paper-plane"></i>
                        <span>Send</span>
                    </button>
                </div>
            </div>
        </section>
    </main>

    <script>
    (function(){
        var chatMessages = document.getElementById('chatMessages');
        var chatInput = document.getElementById('chatInput');
        var sendBtn = document.getElementById('sendChatBtn');
        var refreshBtn = document.getElementById('refreshChatBtn');
        var lastId = 0;
        var myUserId = <?php echo (int)$_SESSION['user_id']; ?>;
        var canNotify = false;
        try {
            if ('Notification' in window) {
                if (Notification.permission === 'granted') { canNotify = true; }
                else if (Notification.permission !== 'denied') {
                    Notification.requestPermission().then(function(p){ canNotify = (p === 'granted'); });
                }
            }
        } catch(e){}

        function showNotification(title, body){
            try {
                if (!canNotify || !('Notification' in window)) return;
                new Notification(title, { body: body });
            } catch(e) {}
        }

        // Lightweight in-app toast for mobile/foreground
        var toastWrap = document.createElement('div');
        toastWrap.className = 'fixed z-50 left-1/2 -translate-x-1/2 bottom-4 space-y-2';
        document.body.appendChild(toastWrap);
        function showToast(text){
            try {
                var t = document.createElement('div');
                t.className = 'px-4 py-2 rounded-lg shadow bg-slate-900 text-white/95 text-sm max-w-[90vw]';
                t.textContent = text;
                toastWrap.appendChild(t);
                setTimeout(function(){ t.style.opacity='0'; t.style.transition='opacity .3s'; }, 2500);
                setTimeout(function(){ toastWrap.removeChild(t); }, 2900);
            } catch(e) {}
        }

        // Action menu + dialogs
        var actionMenu = document.createElement('div');
        actionMenu.id = 'msgActionMenu';
        actionMenu.className = 'hidden fixed z-50 bg-white border border-slate-200 rounded-lg shadow-lg p-2 w-40';
        actionMenu.innerHTML = ''
            + '<button data-action="edit" class="w-full text-left px-3 py-2 rounded hover:bg-slate-100">Edit</button>'
            + '<button data-action="delete" class="w-full text-left px-3 py-2 rounded hover:bg-slate-100 text-red-600">Delete</button>'
            + '<div class="h-px bg-slate-200 my-1"></div>'
            + '<button data-action="cancel" class="w-full text-left px-3 py-2 rounded hover:bg-slate-100">Cancel</button>';
        document.body.appendChild(actionMenu);

        var editDialog = document.createElement('div');
        editDialog.id = 'editDialog';
        editDialog.className = 'hidden fixed inset-0 z-50 items-center justify-center bg-black/40';
        editDialog.innerHTML = ''
            + '<div class="bg-white rounded-xl shadow-xl w-full max-w-md p-4">'
            + '  <h3 class="text-lg font-semibold text-slate-900 mb-3">Edit Message</h3>'
            + '  <textarea id="editInput" class="w-full h-28 p-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>'
            + '  <div class="mt-4 flex justify-end gap-2">'
            + '    <button id="editCancelBtn" class="px-4 h-10 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100">Cancel</button>'
            + '    <button id="editSaveBtn" class="px-4 h-10 rounded-lg bg-blue-600 text-white hover:bg-blue-700">Save</button>'
            + '  </div>'
            + '</div>';
        document.body.appendChild(editDialog);

        var confirmDialog = document.createElement('div');
        confirmDialog.id = 'confirmDialog';
        confirmDialog.className = 'hidden fixed inset-0 z-50 items-center justify-center bg-black/40';
        confirmDialog.innerHTML = ''
            + '<div class="bg-white rounded-xl shadow-xl w-full max-w-sm p-4">'
            + '  <h3 class="text-lg font-semibold text-slate-900">Delete Message</h3>'
            + '  <p class="mt-2 text-slate-700">Are you sure you want to delete this message?</p>'
            + '  <div class="mt-4 flex justify-end gap-2">'
            + '    <button id="confirmCancelBtn" class="px-4 h-10 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100">Cancel</button>'
            + '    <button id="confirmDeleteBtn" class="px-4 h-10 rounded-lg bg-red-600 text-white hover:bg-red-700">Delete</button>'
            + '  </div>'
            + '</div>';
        document.body.appendChild(confirmDialog);

        var selectedMsg = { id: null, content: '', anchorX: 0, anchorY: 0 };

        function escapeHtml(str){
            return String(str || '').replace(/[&<>"']/g, function(m){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]);});
        }

        function render(messages){
            if (!Array.isArray(messages)) return;
            var frag = document.createDocumentFragment();
            messages.forEach(function(m){
                lastId = Math.max(lastId, m.id);
                var wrap = document.createElement('div');
                var isMe = m.sender_user_id === myUserId;
                var bubbleBg = isMe ? 'bg-green-50 border-green-200' : 'bg-white border-slate-200';
                var alignCls = isMe ? 'justify-end' : 'justify-start';
                var nameColor = isMe ? 'text-green-700' : 'text-slate-700';
                // format time as HH:MM
                var t = (m.created_at || '').replace(' ', 'T');
                var d = new Date(t);
                var hh = String(d.getHours()).padStart(2,'0');
                var mm = String(d.getMinutes()).padStart(2,'0');
                var timeStr = hh + ':' + mm;
                var senderLabel = isMe ? 'Me' : (m.sender_name || m.username || 'Teacher');
                wrap.className = 'mb-3 flex ' + alignCls;
                var bubble = document.createElement('div');
                bubble.className = 'max-w-[85%] rounded-lg px-3 py-2 border shadow-sm ' + bubbleBg;
                bubble.setAttribute('data-id', m.id);
                bubble.setAttribute('data-is-me', isMe ? '1' : '0');
                var isDeleted = (m.content || '').trim().toLowerCase() === '[message deleted]';
                var contentHtml = isDeleted
                    ? '<div class="italic text-slate-500">[message deleted]</div>'
                    : '<div class="text-slate-800 break-words">' + escapeHtml(m.content) + ' <span class="text-xs text-slate-500 align-bottom ml-2">' + escapeHtml(timeStr) + '</span></div>';
                bubble.innerHTML = ''
                    + '<div class="text-sm ' + nameColor + ' font-semibold">' + escapeHtml(senderLabel) + '</div>'
                    + contentHtml;
                // Long-press (or right-click) for own messages -> show actions
                if (isMe && !isDeleted) {
                    var pressTimer;
                    var openActionMenu = function(e){
                        e.preventDefault();
                        selectedMsg.id = m.id;
                        selectedMsg.content = m.content || '';
                        var x = (e.pageX || selectedMsg.anchorX || window.innerWidth/2);
                        var y = (e.pageY || selectedMsg.anchorY || window.innerHeight/2);
                        actionMenu.style.left = Math.max(8, Math.min(x, window.innerWidth - 180)) + 'px';
                        actionMenu.style.top = Math.max(8, Math.min(y, window.innerHeight - 120)) + 'px';
                        actionMenu.classList.remove('hidden');
                    };
                    bubble.addEventListener('contextmenu', openActionMenu);
                    bubble.addEventListener('touchstart', function(te){
                        var touch = te.touches && te.touches[0];
                        if (touch) { selectedMsg.anchorX = touch.pageX; selectedMsg.anchorY = touch.pageY; }
                        pressTimer = setTimeout(function(){ openActionMenu({ preventDefault:function(){}, pageX: selectedMsg.anchorX, pageY: selectedMsg.anchorY }); }, 550);
                    });
                    bubble.addEventListener('touchend', function(){ clearTimeout(pressTimer); });
                }
                wrap.appendChild(bubble);
                frag.appendChild(wrap);
            });
            chatMessages.appendChild(frag);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function fetchMessages(){
            var url = 'ajax/get_messages.php?since_id=' + encodeURIComponent(lastId) + '&limit=50';
            fetch(url)
                .then(function(r){ return r.text(); })
                .then(function(text){
                    var data;
                    try { data = JSON.parse(text); }
                    catch(e){ console.error('Non-JSON from get_messages:', text.substring(0, 500)); return; }
                    var msgs = data.messages || [];
                    // Notify for new incoming messages (not mine)
                    msgs.forEach(function(m){
                        if (m && m.sender_user_id !== myUserId) {
                            if (canNotify) {
                                showNotification(m.sender_name || 'Teacher', m.content || 'New message');
                            } else {
                                showToast((m.sender_name || 'Teacher') + ': ' + (m.content || 'New message'));
                            }
                        }
                    });
                    render(msgs);
                    if (msgs.length) {
                        var latest = msgs[msgs.length - 1];
                        var formData = new FormData();
                        formData.append('max_id', latest.id);
                        fetch('ajax/mark_messages_read.php', { method: 'POST', body: formData })
                            .then(function(){
                                try { if (window.opener) window.opener.postMessage({ type: 'refreshUnread' }, '*'); } catch(e){}
                            });
                    }
                })
                .catch(function(err){ console.error('fetchMessages failed', err); });
        }

        function sendMessage(){
            var content = (chatInput.value || '').trim();
            if (!content) return;
            fetch('ajax/send_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content: content })
            })
            .then(function(r){ return r.text(); })
            .then(function(text){
                var res; try { res = JSON.parse(text); } catch(e){ console.error('Non-JSON from send_message:', text); res = null; }
                if (res && res.success) {
                    chatInput.value = '';
                    fetchMessages();
                }
            })
            .catch(function(err){ console.error('sendMessage failed', err); });
        }

        // Initial load and polling
        fetchMessages();
        var poll = setInterval(fetchMessages, 5000);
        sendBtn.addEventListener('click', sendMessage);
        refreshBtn.addEventListener('click', fetchMessages);
        chatInput.addEventListener('keydown', function(e){ if (e.key === 'Enter') sendMessage(); });

        // Listen for dashboard asking to refresh
        window.addEventListener('message', function(ev){
            if (ev && ev.data && ev.data.type === 'refreshMessages') fetchMessages();
        });

        // Action menu handlers
        document.addEventListener('click', function(e){
            if (!actionMenu.classList.contains('hidden') && !actionMenu.contains(e.target)) {
                actionMenu.classList.add('hidden');
            }
        });
        actionMenu.addEventListener('click', function(e){
            var btn = e.target.closest('button');
            if (!btn) return;
            var act = btn.getAttribute('data-action');
            if (act === 'cancel') {
                actionMenu.classList.add('hidden');
                return;
            }
            if (!selectedMsg.id) return;
            if (act === 'edit') {
                actionMenu.classList.add('hidden');
                var ta = document.getElementById('editInput');
                ta.value = selectedMsg.content;
                editDialog.classList.remove('hidden');
                editDialog.classList.add('flex');
                ta.focus();
            } else if (act === 'delete') {
                actionMenu.classList.add('hidden');
                confirmDialog.classList.remove('hidden');
                confirmDialog.classList.add('flex');
            }
        });

        // Edit dialog
        document.getElementById('editCancelBtn').addEventListener('click', function(){
            editDialog.classList.add('hidden');
            editDialog.classList.remove('flex');
        });
        document.getElementById('editSaveBtn').addEventListener('click', function(){
            var btn = this;
            var content = (document.getElementById('editInput').value || '').trim();
            if (!content || !selectedMsg.id) { return; }
            var original = btn.textContent;
            btn.disabled = true; btn.textContent = 'Saving...';
            fetch('ajax/update_message.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: selectedMsg.id, content: content }) })
                .then(function(r){ return r.text(); })
                .then(function(text){
                    var res; try { res = JSON.parse(text); } catch(e){ console.error('Invalid JSON from update_message:', text); res = null; }
                    if (res && res.success) {
                        editDialog.classList.add('hidden');
                        editDialog.classList.remove('flex');
                        chatMessages.innerHTML=''; lastId = 0; fetchMessages();
                    } else {
                        alert((res && res.message) ? res.message : 'Failed to save message');
                    }
                })
                .catch(function(err){ console.error(err); alert('Network error saving message'); })
                .finally(function(){ btn.disabled = false; btn.textContent = original; });
        });

        // Confirm dialog
        document.getElementById('confirmCancelBtn').addEventListener('click', function(){
            confirmDialog.classList.add('hidden');
            confirmDialog.classList.remove('flex');
        });
        document.getElementById('confirmDeleteBtn').addEventListener('click', function(){
            var btn = this;
            if (!selectedMsg.id) return;
            var original = btn.textContent;
            btn.disabled = true; btn.textContent = 'Deleting...';
            fetch('ajax/delete_message.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: selectedMsg.id }) })
                .then(function(r){ return r.text(); })
                .then(function(text){
                    var res; try { res = JSON.parse(text); } catch(e){ console.error('Invalid JSON from delete_message:', text); res = null; }
                    if (res && res.success) {
                        confirmDialog.classList.add('hidden');
                        confirmDialog.classList.remove('flex');
                        chatMessages.innerHTML=''; lastId = 0; fetchMessages();
                    } else {
                        alert((res && res.message) ? res.message : 'Failed to delete message');
                    }
                })
                .catch(function(err){ console.error(err); alert('Network error deleting message'); })
                .finally(function(){ btn.disabled = false; btn.textContent = original; });
        });
    })();
    </script>
</body>
</html>


