// public/js/main.js

const API_URL = 'submit.php';
let currentUser = null;

// Demo 模式旗標：避免 isDemoMode 未定義導致整支 main.js 停掉
let isDemoMode = false;
let globalTimeRange = '1d'; // 1d, 1wk, 1m, 3m

// 運動圖示對照
const SPORT_ICONS = {
    '跑步': '🏃', '重訓': '🏋️', '腳踏車': '🚴',
    '游泳': '🏊', '瑜珈': '🧘', '其他': '🤸'
};

document.addEventListener('DOMContentLoaded', () => {
    checkLogin();
    setupForms();
    setupForms();
    generateAvatarGrid();
    setupCoachInteraction();

    // 預設日期與時間
    const datePart = document.getElementById('input-date-part');
    const timePart = document.getElementById('input-time-part');
    if (datePart && timePart) {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');

        datePart.value = `${year}-${month}-${day}`;
        timePart.value = `${hours}:${minutes}`;
    }
});

// --- Auth ---
async function checkLogin() {
    try {
        const res = await fetch(`${API_URL}?action=get_user_info`, { credentials: 'same-origin' });
        const json = await res.json();

        if (json.success && json.data) {
            currentUser = json.data;
            showDashboard();
        } else {
            showLogin();
        }
    } catch (e) { showLogin(); }
}

function showLogin() {
    document.getElementById('auth-view').classList.remove('hidden');
    document.getElementById('dashboard-view').classList.add('hidden');
    document.getElementById('ai-coach-container').classList.add('hidden');
}

function showDashboard() {
    document.getElementById('auth-view').classList.add('hidden');
    document.getElementById('dashboard-view').classList.remove('hidden');
    document.getElementById('ai-coach-container').classList.remove('hidden');

    document.getElementById('user-display-name').textContent = currentUser.display_name;
    // document.getElementById('new-display-name').value = currentUser.display_name; // remove old Input

    updateProfileUI();

    // 載入頭像
    // 載入頭像
    const saved = localStorage.getItem(`avatar_${currentUser.id}`);
    const defaultAvatar = 'public/image/1.png';
    const avatarImg = document.getElementById('current-avatar');

    // Validate saved path, fallback to default
    if (saved && saved.includes('public/image/')) {
        avatarImg.src = saved;
    } else {
        avatarImg.src = defaultAvatar;
    }

    // 初始載入圖表
    setGlobalRange('1d');
}

function demoLogin() {
    isDemoMode = true;
    currentUser = { id: 999, display_name: 'Demo Hero', email: 'demo@fit.com' };
    showDashboard();
}

async function logout() {
    if (!isDemoMode) await fetchPost('logout', {});
    location.reload();
}

// --- 表單/互動 ---
function setupForms() {
    document.getElementById('login-form').onsubmit = handleLogin;
    document.getElementById('register-form').onsubmit = handleRegister;
    document.getElementById('add-workout-form').onsubmit = handleAddWorkout;
}

function switchTab(tab) {
    const loginFn = document.getElementById('login-form');
    const regFn = document.getElementById('register-form');
    if (tab === 'login') {
        loginFn.classList.remove('hidden');
        regFn.classList.add('hidden');
    } else {
        loginFn.classList.add('hidden');
        regFn.classList.remove('hidden');
    }
}

async function handleLogin(e) {
    e.preventDefault();

    const form = e.target;
    const email = form.querySelector('input[name="email"]').value.trim();
    const password = form.querySelector('input[name="password"]').value;

    if (!email || !password) {
        alert('請輸入 Email 與密碼');
        return;
    }

    try {
        const json = await fetchPost('login', { email, password });
        console.log('登入回應:', json);

        if (json.success) {
            // 讓後端 session cookie 生效，重新整理後 checkLogin 會帶你進主頁
            location.reload();
        } else {
            alert('登入失敗: ' + (json.message || '帳號或密碼錯誤'));
        }
    } catch (err) {
        console.error('登入錯誤:', err);
        alert('連線錯誤: ' + err.message);
    }
}

async function handleRegister(e) {
    e.preventDefault();

    const form = e.target;
    const display_name = form.querySelector('input[name="display_name"]').value.trim();
    const email = form.querySelector('input[name="email"]').value.trim();
    const password = form.querySelector('input[name="password"]').value;

    if (!display_name || !email || !password) {
        alert('請輸入暱稱、Email 與密碼');
        return;
    }

    try {
        const json = await fetchPost('register', { display_name, email, password });
        console.log('註冊回應:', json);

        if (json.success) {
            location.reload();
        } else {
            alert('註冊失敗: ' + (json.message || '未知錯誤'));
        }
    } catch (err) {
        console.error('註冊錯誤:', err);
        alert('連線錯誤: ' + err.message);
    }
}
async function handleAddWorkout(e) {
    e.preventDefault();

    // Check Profile
    if (!currentUser || !currentUser.height || !currentUser.weight) {
        alert('請先完善個人資料(身高、體重)才能新增紀錄！');
        // Trigger edit
        enableProfileEdit();
        return;
    }

    const datePart = document.getElementById('input-date-part').value;
    const timePart = document.getElementById('input-time-part').value;
    const fullDate = `${datePart} ${timePart}:00`; // YYYY-MM-DD HH:mm:ss

    const type = document.getElementById('input-type').value;
    const minutes = document.getElementById('input-minutes').value;
    const calories = document.getElementById('input-calories').value;

    const payload = {
        date: fullDate,
        type, minutes, calories
    };

    if (isDemoMode) {
        alert('Demo: 新增成功');
        // loadAllCharts(); 
        return;
    }

    const res = await fetch(`${API_URL}?action=add_workout`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
    });
    const json = await res.json();
    if (json.success) {
        alert('新增成功');
        location.reload();
    } else {
        alert('失敗: ' + json.message);
    }
}

function calculateCalories() {
    const type = document.getElementById('input-type').value;
    const mins = parseInt(document.getElementById('input-minutes').value) || 0;

    // MET values
    const metTable = {
        '跑步': 10,
        '重訓': 4,
        '腳踏車': 8,
        '游泳': 6,
        '瑜珈': 3,
        '其他': 2
    };
    const met = metTable[type] || 2;

    // Check if weight is set
    const weight = (currentUser && currentUser.weight) ? parseFloat(currentUser.weight) : null;

    if (!weight) {
        document.getElementById('calorie-display-area').classList.add('hidden');
        document.getElementById('input-calories').value = 0;
        return;
    }

    // Formula: kcal = ((MET * 3.5 * weight) / 200) * time
    const total = ((met * 3.5 * weight) / 200) * mins;

    const finalVal = Math.round(total);

    document.getElementById('calc-val').textContent = finalVal;
    document.getElementById('input-calories').value = finalVal;
    document.getElementById('calorie-display-area').classList.remove('hidden');
}

// --- 頭像與 Modal ---
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function saveName() {
    const val = document.getElementById('new-display-name').value;
    document.getElementById('user-display-name').textContent = val;
    closeModal('nameModal');
}

function generateAvatarGrid() {
    const grid = document.getElementById('avatar-grid');
    if (!grid) {
        console.warn('Avatar grid element not found; skipping grid generation.');
        return;
    }
    grid.innerHTML = '';

    // 使用 1.png 到 11.png
    const avatarCount = 11;
    for (let i = 1; i <= avatarCount; i++) {
        const imgPath = `public/image/${i}.png`;
        const img = document.createElement('img');
        img.src = imgPath;
        img.alt = `Avatar ${i}`;
        img.style.width = '60px';
        img.style.height = '60px';
        img.style.borderRadius = '50%';
        img.style.cursor = 'pointer';
        img.style.objectFit = 'cover';
        img.style.border = '2px solid #eee';

        img.onclick = () => {
            document.getElementById('current-avatar').src = imgPath;
            // 簡單起見，這裡不存 LocalStorage，實際專案應該要存
            closeModal('avatarModal');
        };
        grid.appendChild(img);
    }
}

function setupCoachInteraction() {
    const wrapper = document.querySelector('.coach-img-wrapper');
    const img = document.querySelector('.coach-full-img');

    if (!wrapper || !img) return;

    wrapper.addEventListener('mouseenter', () => {
        img.src = 'public/image/tinin2.png';
    });

    wrapper.addEventListener('mouseleave', () => {
        img.src = 'public/image/tinin.png';
    });
}

function toggleChat() {
    const win = document.getElementById('chat-window');
    if (win.style.opacity === '0' || win.style.opacity === '') {
        win.style.opacity = '1';
        win.style.pointerEvents = 'auto';
        win.style.transform = 'translateY(0)';
    } else {
        win.style.opacity = '0';
        win.style.pointerEvents = 'none';
        win.style.transform = 'translateY(20px)';
    }
}

// --- 全域圖表邏輯 (核心) ---

function setGlobalRange(range) {
    globalTimeRange = range;
    // Update Buttons
    document.querySelectorAll('.g-time-btn').forEach(b => {
        if (b.textContent.includes(range === '1d' ? '1天' : range === '1wk' ? '1周' : range === '1m' ? '1月' : '3月'))
            b.classList.add('active');
        else b.classList.remove('active');
    });

    loadAllCharts();
}

// --- 全域圖表邏輯 ---

let chartCache = {
    barLabels: [], barData: [],
    lineLabels: [], lineData: [],
    pieLabels: [], pieData: []
};

function setGlobalRange(range) {
    globalTimeRange = range;
    document.querySelectorAll('.g-time-btn').forEach(b => {
        if (b.textContent.includes(range === '1d' ? '1天' : range === '1wk' ? '1周' : range === '1m' ? '1月' : '3月'))
            b.classList.add('active');
        else b.classList.remove('active');
    });

    generateChartData();
    updateCharts();
}

function generateChartData() {
    let labels = [];
    let barData = [];
    let lineData = [];
    let pieData = [30, 20, 15, 10, 25];

    if (globalTimeRange === '1d') {
        for (let i = 0; i < 24; i += 3) labels.push(`${i}:00`);
        barData = getDataPoints(8, 30);
        lineData = getDataPoints(8, 200);
        pieData = [30, 20, 15, 10, 25];
    } else if (globalTimeRange === '1wk') {
        labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        barData = getDataPoints(7, 90);
        lineData = getDataPoints(7, 600);
        pieData = [100, 80, 60, 40, 90];
    } else if (globalTimeRange === '1m') {
        for (let i = 1; i <= 30; i += 5) labels.push(`${i}日`);
        barData = getDataPoints(6, 120);
        lineData = getDataPoints(6, 800);
        pieData = [200, 150, 100, 50, 120];
    } else {
        labels = ['一月', '二月', '三月'];
        barData = getDataPoints(3, 2000);
        lineData = getDataPoints(3, 15000);
        pieData = [500, 300, 400, 200, 600];
    }

    chartCache.barLabels = labels;
    chartCache.barData = barData;
    chartCache.lineLabels = labels;
    chartCache.lineData = lineData;
    chartCache.pieData = pieData;
    chartCache.pieLabels = ['跑步', '重訓', '腳踏車', '游泳', '瑜珈'];

    if (!barInstance) initCharts();
}

function getDataPoints(count, maxVal) {
    return Array.from({ length: count }, () => Math.floor(Math.random() * maxVal));
}

function loadAllCharts() {
    generateChartData();
    updateCharts();
    renderLeaderboard(); // 確保每次也更新排行榜 (模擬)
}

let barInstance = null;
let lineInstance = null;
let pieInstance = null;

function initCharts() {
    // Bar
    const ctxBar = document.getElementById('chart-bar-time');
    barInstance = new Chart(ctxBar, {
        type: 'bar',
        data: { labels: chartCache.barLabels, datasets: [{ label: '運動時間 (分鐘)', data: chartCache.barData, backgroundColor: '#3742fa', borderRadius: 5 }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });

    // Line
    const ctxLine = document.getElementById('chart-line-calories');
    lineInstance = new Chart(ctxLine, {
        type: 'line',
        data: { labels: chartCache.lineLabels, datasets: [{ label: '消耗熱量 (kcal)', data: chartCache.lineData, borderColor: '#ff4757', backgroundColor: 'rgba(255, 71, 87, 0.1)', fill: true, tension: 0.4 }] },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // Pie
    const ctxPie = document.getElementById('chart-pie-types');
    pieInstance = new Chart(ctxPie, {
        type: 'doughnut',
        data: {
            labels: chartCache.pieLabels,
            datasets: [{ data: chartCache.pieData, backgroundColor: ['#ff4757', '#3742fa', '#ffa502', '#2ed573', '#1e90ff'], borderWidth: 0, hoverOffset: 15 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '70%',
            plugins: { legend: { display: false } },
            onHover: (event, elements) => {
                const icon = document.getElementById('pie-center-icon');
                const text = document.getElementById('pie-center-text');
                if (elements.length > 0) {
                    const idx = elements[0].index;
                    icon.textContent = SPORT_ICONS[chartCache.pieLabels[idx]] || '🏅';
                    text.textContent = chartCache.pieLabels[idx];
                } else {
                    icon.textContent = '🏆';
                    text.textContent = '總覽';
                }
            }
        }
    });
}

function updateCharts() {
    if (!barInstance || !lineInstance || !pieInstance) return;
    barInstance.data.labels = chartCache.barLabels;
    lineInstance.data.labels = chartCache.lineLabels;
    barInstance.data.datasets[0].data = chartCache.barData;
    lineInstance.data.datasets[0].data = chartCache.lineData;
    pieInstance.data.datasets[0].data = chartCache.pieData;
    barInstance.update();
    lineInstance.update();
    pieInstance.update();
}

// --- Avatar Logic (Fade) ---
let currentAvatarId = 1;

function changeAvatar(dir) {
    const img = document.getElementById('current-avatar');
    if (!img) return;

    // Fade Out
    img.classList.add('fade-out');

    setTimeout(() => {
        currentAvatarId += dir;
        if (currentAvatarId < 1) currentAvatarId = 11;
        if (currentAvatarId > 11) currentAvatarId = 1;

        img.src = `public/image/${currentAvatarId}.png`;

        // Swap to Fade In
        img.classList.remove('fade-out');
        img.classList.add('fade-in');

        // Cleanup
        setTimeout(() => {
            img.classList.remove('fade-in');
        }, 300);
    }, 300);
}

// --- Inline Profile Edit ---
function enableProfileEdit() {
    const nameDisplay = document.getElementById('user-display-name');
    const statsDisplay = document.getElementById('profile-stats');
    const parent = nameDisplay.parentElement;

    if (document.querySelector('.edit-profile-container')) return;

    const currentName = currentUser.display_name || '';
    const currentHeight = currentUser.height || '';
    const currentWeight = currentUser.weight || '';

    // Hide display
    nameDisplay.style.display = 'none';
    statsDisplay.style.display = 'none';

    // Create Edit Container
    const container = document.createElement('div');
    container.className = 'edit-profile-container';
    container.innerHTML = `
        <div style="margin-bottom: 5px;"><input type="text" id="edit-name" class="form-control" value="${currentName}" placeholder="暱稱"></div>
        <div style="display: flex; gap: 10px; margin-bottom: 5px;">
            <input type="number" id="edit-height" class="form-control" value="${currentHeight}" placeholder="身高 (cm)">
            <input type="number" id="edit-weight" class="form-control" value="${currentWeight}" placeholder="體重 (kg)">
        </div>
        <button class="btn-primary" style="padding: 5px 15px; font-size: 0.9rem;" onclick="saveProfile()">儲存</button>
        <button class="btn-primary" style="padding: 5px 15px; font-size: 0.9rem; background: #999;" onclick="cancelProfileEdit()">取消</button>
    `;

    parent.appendChild(container);
}

function cancelProfileEdit() {
    const nameDisplay = document.getElementById('user-display-name');
    const statsDisplay = document.getElementById('profile-stats');
    nameDisplay.style.display = 'block';
    statsDisplay.style.display = 'block';
    const c = document.querySelector('.edit-profile-container');
    if (c) c.remove();
}

async function saveProfile() {
    const name = document.getElementById('edit-name').value;
    const height = document.getElementById('edit-height').value;
    const weight = document.getElementById('edit-weight').value;

    if (!name.trim()) { alert('請輸入暱稱'); return; }

    const payload = {
        display_name: name,
        height: height,
        weight: weight
    };

    if (isDemoMode) {
        currentUser.display_name = name;
        currentUser.height = height;
        currentUser.weight = weight;
        updateProfileUI();
        cancelProfileEdit();
        return;
    }

    try {
        const res = await fetch(`${API_URL}?action=update_profile`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            currentUser.display_name = name;
            currentUser.height = height;
            currentUser.weight = weight;
            updateProfileUI();
            cancelProfileEdit();
        } else {
            alert(json.message || '更新失敗');
        }
    } catch (e) {
        console.error(e);
        alert('連線錯誤');
    }
}

function updateProfileUI() {
    document.getElementById('user-display-name').textContent = currentUser.display_name;

    const h = currentUser.height ? `${currentUser.height}cm` : '';
    const w = currentUser.weight ? `${currentUser.weight}kg` : '';
    let text = '';
    if (h && w) text = `${h} / ${w}`;
    else if (h) text = h;
    else if (w) text = w;
    else text = '完善個人資料以計算熱量';

    document.getElementById('profile-stats').textContent = text;
}

// --- Leaderboard ---
async function renderLeaderboard() {
    const tbody = document.getElementById('leaderboard-body');
    if (!tbody) return;

    try {
        const res = await fetch(`${API_URL}?action=get_leaderboard`);
        const json = await res.json();

        if (!json.success || !json.data) {
            tbody.innerHTML = '<tr><td colspan="3">暫無資料</td></tr>';
            return;
        }

        const users = json.data;
        tbody.innerHTML = '';

        users.forEach((u, i) => {
            const tr = document.createElement('tr');
            // Adds crown for top 3
            const rank = i < 3 ? ['🥇', '🥈', '🥉'][i] : (i + 1);

            // display_name might be null, fallback
            const name = u.display_name || 'User';

            tr.innerHTML = `
                <td><span style="font-size: 1.2rem;">${rank}</span></td>
                <td><strong>${name}</strong></td>
                <td>${u.total}</td>
            `;
            // Highlight current user
            if (currentUser && name === currentUser.display_name) {
                tr.style.background = 'rgba(255, 71, 87, 0.1)';
            }
            tbody.appendChild(tr);
        });

    } catch (e) {
        console.error('Leaderboard error:', e);
        tbody.innerHTML = '<tr><td colspan="3">載入失敗</td></tr>';
    }
}

async function fetchPost(action, data = {}) {
    // Demo 模式就不打 API（但仍回 success，避免整站卡死）
    if (isDemoMode) {
        return { success: true, demo: true };
    }

    const res = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(data)
    });

    // 若後端噴 PHP warning 導致不是 JSON，這裡能更好定位
    const text = await res.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        console.error('❌ API 不是 JSON：', text);
        throw new Error('API 回傳不是 JSON（請檢查 submit.php 是否有 warning/notice 或輸出 HTML）');
    }
}