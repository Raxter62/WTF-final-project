// public/js/main.js

const API_URL = 'submit.php';
let currentUser = null;
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

    // Default date/time
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
        const res = await fetch(`${API_URL}?action=get_user_info`);
        const json = await res.json();

        if (json.success && json.data) {
            currentUser = json.data;
            showDashboard();
        } else {
            showLogin();
        }
    } catch (e) {
        console.warn('checkLogin failed:', e);
        showLogin();
    }
}

function showLogin() {
    document.getElementById('auth-view').classList.remove('hidden');
    document.getElementById('dashboard-view').classList.add('hidden');
}

function showDashboard() {
    document.getElementById('auth-view').classList.add('hidden');
    document.getElementById('dashboard-view').classList.remove('hidden');

    updateProfileUI();
    loadAllCharts();
}

async function handleLogin(e) {
    e.preventDefault();
    if (isDemoMode) { demoLogin(); return; }

    const fd = new FormData(e.target);
    const email = (fd.get('email') || '').toString().trim();
    const password = (fd.get('password') || '').toString();

    if (!email || !password) { alert('請輸入 Email 和密碼'); return; }

    const res = await fetchPost('login', { email, password });
    if (res.success) {
        // 後端會回傳使用者資訊
        currentUser = res.data || currentUser;
        showDashboard();
    } else {
        alert(res.message || '登入失敗');
    }
}

async function handleRegister(e) {
    e.preventDefault();
    if (isDemoMode) { demoLogin(); return; }

    const fd = new FormData(e.target);
    const display_name = (fd.get('display_name') || '').toString().trim();
    const email = (fd.get('email') || '').toString().trim();
    const password = (fd.get('password') || '').toString();

    if (!display_name) { alert('請輸入暱稱'); return; }
    if (!email || !password) { alert('請輸入 Email 和密碼'); return; }

    const res = await fetchPost('register', { display_name, email, password });
    if (res.success) {
        currentUser = res.data || currentUser;
        showDashboard();
    } else {
        alert(res.message || '註冊失敗');
    }
}

async function logout() {
    if (isDemoMode) {
        currentUser = null;
        isDemoMode = false;
        showLogin();
        return;
    }

    const res = await fetchPost('logout', {});
    if (res.success) {
        currentUser = null;
        showLogin();
    } else {
        alert(res.message || '登出失敗');
    }
}

// Demo login (保留原本功能)
function demoLogin() {
    isDemoMode = true;
    currentUser = {
        id: 999,
        display_name: 'Demo User',
        email: 'demo@fitconnect.dev',
        height: 170,
        weight: 65
    };
    showDashboard();
}

// --- UI setup ---
function setupForms() {
    const loginForm = document.getElementById('login-form');
    const regForm = document.getElementById('register-form');
    const addForm = document.getElementById('add-workout-form');

    if (loginForm) loginForm.addEventListener('submit', handleLogin);
    if (regForm) regForm.addEventListener('submit', handleRegister);
    if (addForm) addForm.addEventListener('submit', handleAddWorkout);

    // input change for calories
    const typeEl = document.getElementById('input-type');
    const minEl = document.getElementById('input-minutes');
    if (typeEl) typeEl.addEventListener('change', calculateCalories);
    if (minEl) minEl.addEventListener('input', calculateCalories);

    // Global time buttons
    document.querySelectorAll('.g-time-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const text = btn.textContent;
            if (text.includes('1天')) setGlobalRange('1d');
            else if (text.includes('1周')) setGlobalRange('1wk');
            else if (text.includes('1月')) setGlobalRange('1m');
            else setGlobalRange('3m');
        });
    });
}

// --- Profile ---
function updateProfileUI() {
    const nameEl = document.getElementById('user-display-name');
    const statsEl = document.getElementById('profile-stats');

    if (nameEl) nameEl.textContent = currentUser?.display_name || 'User';

    // 顯示身高體重
    if (statsEl) {
        const h = currentUser?.height ?? '—';
        const w = currentUser?.weight ?? '—';
        statsEl.textContent = `身高：${h} cm｜體重：${w} kg`;
    }

    // 載入頭像
    const saved = localStorage.getItem(`avatar_${currentUser.id}`);
    const defaultAvatar = 'public/image/1.png';
    const avatarImg = document.getElementById('current-avatar');
    if (avatarImg) avatarImg.src = saved || defaultAvatar;
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
        alert('連線錯誤');
    }
}

// --- Add workout ---
async function handleAddWorkout(e) {
    e.preventDefault();

    const datePart = document.getElementById('input-date-part').value;
    const timePart = document.getElementById('input-time-part').value;
    const type = document.getElementById('input-type').value;
    const minutes = parseInt(document.getElementById('input-minutes').value || '0', 10);
    const calories = parseInt(document.getElementById('input-calories').value || '0', 10);

    if (!datePart || !timePart) { alert('請選擇日期/時間'); return; }
    if (!type) { alert('請選擇運動種類'); return; }
    if (!minutes || minutes <= 0) { alert('請輸入運動時長'); return; }

    const fullDate = `${datePart} ${timePart}:00`;

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
        body: JSON.stringify(payload)
    });
    const json = await res.json();
    if (json.success) {
        alert('新增成功');
        await loadAllCharts();
        await renderLeaderboard();
    } else {
        alert('失敗: ' + json.message);
    }
}

function calculateCalories() {
    const type = document.getElementById('input-type').value;
    const mins = parseInt(document.getElementById('input-minutes').value || '0', 10);

    // 簡易估算（保留原本 UI 邏輯）
    const metMap = {
        '跑步': 10,
        '重訓': 6,
        '腳踏車': 8,
        '游泳': 9,
        '瑜珈': 3,
        '其他': 5
    };

    const w = parseFloat(currentUser?.weight || 65);
    const met = metMap[type] || 5;

    // kcal/min ≈ MET * 3.5 * weight(kg) / 200
    const kcal = Math.round((met * 3.5 * w / 200) * mins);

    const out = document.getElementById('input-calories');
    if (out) out.value = isFinite(kcal) ? kcal : 0;
}

// --- Charts (Global) ---
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

    loadAllCharts();
}

function generateChartData() {
    let labels = [];
    let barData = [];
    let lineData = [];
    let pieData = [30, 20, 15, 10, 25];

    if (globalTimeRange === '1d') {
        labels = ['00:00', '03:00', '06:00', '09:00', '12:00', '15:00', '18:00', '21:00'];
        barData = [15, 10, 0, 20, 30, 25, 15, 5];
        lineData = [200, 150, 100, 250, 300, 280, 220, 180];
        pieData = [40, 10, 15, 20, 15];
    } else if (globalTimeRange === '1wk') {
        labels = ['一', '二', '三', '四', '五', '六', '日'];
        barData = getDataPoints(7, 120);
        lineData = getDataPoints(7, 1200);
        pieData = [20, 25, 10, 15, 30];
    } else if (globalTimeRange === '1m') {
        labels = ['第1週', '第2週', '第3週', '第4週'];
        barData = [500, 600, 450, 700];
        lineData = [6000, 7200, 5000, 8000];
        pieData = [30, 20, 15, 10, 25];
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

async function loadAllCharts() {
    // Demo 模式：維持原本的隨機資料行為
    if (isDemoMode) {
        generateChartData();
        if (!barInstance) initCharts();
        updateCharts();
        renderLeaderboard();
        return;
    }

    try {
        const res = await fetch(`${API_URL}?action=get_dashboard_data&range=${encodeURIComponent(globalTimeRange)}`, {
            method: 'GET',
            credentials: 'same-origin'
        });
        const json = await res.json();

        if (json.success && json.data) {
            const d = json.data;

            // Bar: minutes
            chartCache.barLabels = (d.bar && d.bar.labels) ? d.bar.labels : [];
            chartCache.barData = (d.bar && d.bar.data) ? d.bar.data : [];

            // Line: calories
            chartCache.lineLabels = (d.line && d.line.labels) ? d.line.labels : [];
            chartCache.lineData = (d.line && d.line.data) ? d.line.data : [];

            // Pie: calories by type
            chartCache.pieLabels = (d.pie && d.pie.labels) ? d.pie.labels : ['跑步', '重訓', '腳踏車', '游泳', '瑜珈', '其他'];
            chartCache.pieData = (d.pie && d.pie.data) ? d.pie.data : [0, 0, 0, 0, 0, 0];

            if (!barInstance) initCharts();
            updateCharts();
        } else {
            // 後端回傳失敗：保留原本行為（隨機資料）作為備援
            console.warn('get_dashboard_data failed:', json);
            generateChartData();
            if (!barInstance) initCharts();
            updateCharts();
        }
    } catch (e) {
        console.error('loadAllCharts error:', e);
        // 連線失敗：保留原本行為作為備援
        generateChartData();
        if (!barInstance) initCharts();
        updateCharts();
    }

    // 排行榜（仍走後端，若失敗會顯示「載入失敗」）
    renderLeaderboard();
}

let barInstance = null;
let lineInstance = null;
let pieInstance = null;

function initCharts() {
    // Bar
    const ctxBar = document.getElementById('chart-bar-time');
    barInstance = new Chart(ctxBar, {
        type: 'bar',
        data: { labels: chartCache.barLabels, datasets: [{ label: '總運動時間 (分鐘)', data: chartCache.barData, backgroundColor: '#3742fa', borderRadius: 5 }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });

    // Line
    const ctxLine = document.getElementById('chart-line-calories');
    lineInstance = new Chart(ctxLine, {
        type: 'line',
        data: { labels: chartCache.lineLabels, datasets: [{ label: '總消耗 (kcal)', data: chartCache.lineData, borderColor: '#ff4757', tension: 0.4, fill: false }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });

    // Pie
    const ctxPie = document.getElementById('chart-pie-types');
    pieInstance = new Chart(ctxPie, {
        type: 'doughnut',
        data: {
            labels: chartCache.pieLabels,
            datasets: [{
                data: chartCache.pieData, backgroundColor: [
                    '#ff4757', '#3742fa', '#ffa502', '#2ed573', '#1e90ff'
                ], borderWidth: 0, hoverOffset: 15
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '70%',
            plugins: { legend: { display: false } },
            onHover: (event, elements) => {
                const icon = document.getElementById('pie-center-icon');
                const text = document.getElementById('pie-center-text');
                if (!icon || !text) return;

                if (elements.length > 0) {
                    const idx = elements[0].index;
                    icon.textContent = SPORT_ICONS[chartCache.pieLabels[idx]] || '🏅';
                    text.textContent = chartCache.pieLabels[idx];
                } else {
                    icon.textContent = '🏅';
                    text.textContent = '運動分布';
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
    pieInstance.data.labels = chartCache.pieLabels;
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

    img.classList.add('fade-out');

    setTimeout(() => {
        currentAvatarId += dir;
        if (currentAvatarId < 1) currentAvatarId = 5;
        if (currentAvatarId > 5) currentAvatarId = 1;

        const newSrc = `public/image/${currentAvatarId}.png`;
        img.src = newSrc;
        img.classList.remove('fade-out');
        img.classList.add('fade-in');

        // Save
        if (currentUser?.id) localStorage.setItem(`avatar_${currentUser.id}`, newSrc);

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

    if (document.querySelector('.profile-edit')) return;

    const wrap = document.createElement('div');
    wrap.className = 'profile-edit';

    wrap.innerHTML = `
        <input id="edit-name" placeholder="暱稱" value="${currentUser?.display_name || ''}" />
        <input id="edit-height" placeholder="身高(cm)" type="number" value="${currentUser?.height || ''}" />
        <input id="edit-weight" placeholder="體重(kg)" type="number" value="${currentUser?.weight || ''}" />
        <div class="profile-edit-actions">
            <button class="btn btn-primary" onclick="saveProfile()">儲存</button>
            <button class="btn" onclick="cancelProfileEdit()">取消</button>
        </div>
    `;

    nameDisplay.style.display = 'none';
    statsDisplay.style.display = 'none';
    parent.appendChild(wrap);
}

function cancelProfileEdit() {
    const nameDisplay = document.getElementById('user-display-name');
    const statsDisplay = document.getElementById('profile-stats');
    const edit = document.querySelector('.profile-edit');
    if (edit) edit.remove();
    nameDisplay.style.display = 'block';
    statsDisplay.style.display = 'block';
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

async function fetchPost(action, data) {
    // Demo 模式：維持原本假資料流程
    if (typeof isDemoMode !== 'undefined' && isDemoMode) {
        return { success: true, data: null };
    }

    try {
        const res = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(data || {})
        });
        return await res.json();
    } catch (e) {
        console.error('fetchPost error:', e);
        return { success: false, message: '連線失敗' };
    }
}
