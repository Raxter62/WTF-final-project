// public/js/ai_chat.js

// function toggleChat() { ... } // Removed to use main.js version

async function sendChatMessage() {
    const input = document.getElementById('chat-input');
    const msg = input.value.trim();
    if (!msg) return;

    // 加入使用者訊息
    appendMsg(msg, 'user');
    input.value = '';

    // 載入中狀態?
    appendMsg('思考中...', 'ai', 'temp-loading');

    if (typeof isDemoMode !== 'undefined' && isDemoMode) {
        // Mock response
        setTimeout(() => {
            const loader = document.getElementById('temp-loading');
            if (loader) loader.remove();
            appendMsg('這是 Demo 模式的回覆：運動很重要！', 'ai');
        }, 1000);
        return;
    }

    try {
        const res = await fetchPost('ai_coach', { message: msg });

        // 移除載入中
        const loader = document.getElementById('temp-loading');
        if (loader) loader.remove();

        if (res.success) {
            appendMsg(res.reply, 'ai');
        } else {
            appendMsg('錯誤：' + res.message, 'ai');
        }
    } catch (e) {
        const loader = document.getElementById('temp-loading');
        if (loader) loader.remove();
        appendMsg('連線發生錯誤', 'ai');
    }
}

function appendMsg(text, type, id = null) {
    const container = document.getElementById('chat-messages');
    const div = document.createElement('div');
    div.className = `chat-msg ${type}`;
    div.textContent = text;
    if (id) div.id = id;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

// 允許 Enter 鍵
document.getElementById('chat-input').addEventListener('keypress', (e) => {
    if (e.key === 'Enter') sendChatMessage();
});
