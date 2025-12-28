// public/js/main.js

const API_URL = 'submit.php';
let currentUser = null;
let globalTimeRange = '1d'; // 1d, 1wk, 1m, 3m

// 運動圖示對照
const SPORT_ICONS = {
    '跑步': '🏃', '重訓': '🏋️', '腳踏車': '🚴',
    '游泳': '🏊', '瑜珈': '🧘', '其他': '🤸'
};

/**
 * === UI helpers needed by index.html ===
 * - switchTab('login'|'register') : 切換登入/註冊表單
 * - demoLogin() : Demo 模式登入（不打 API）
 * - toggleChat() : 顯示/隱藏 AI 聊天窗（配合 style.css 的 #chat-window 預設 opacity:0）
 */
function switchTab(tab) {
    const loginFn = document.getElementById('login-form');
    const regFn = document.getElementById('register-form');
    if (!loginFn || !regFn) return;

    if (tab === 'login') {
        loginFn.classList.remove('hidden');
        regFn.classList.add('hidden');
    } else {
        loginFn.classList.add('hidden');
        regFn.classList.remove('hidden');
    }
}

// Demo Mode State
let demoData = {
    barLabels: ['週一', '週二', '週三', '週四', '週五', '週六', '週日'],
    barData: [30, 45, 60, 20, 90, 45, 60],
    lineLabels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    lineData: [150, 230, 320, 180, 450, 300, 350],
    pieLabels: ['跑步', '重訓', '游泳', '腳踏車', '瑜珈', '其他'],
    pieData: [10, 20, 15, 25, 10, 20],
    totalCalories: 1980,
    leaderboard: [
        { display_name: 'Demo Hero', total_calories: 2200 },
        { display_name: 'Fast Runner', total_calories: 1800 },
        { display_name: 'Gym Bro', total_calories: 1500 },
        { display_name: 'Yoga Master', total_calories: 1200 },
        { display_name: 'Cyclist', total_calories: 1000 }
    ]
};

function demoLogin() {
    isDemoMode = true;
    currentUser = {
        id: 999,
        display_name: 'Demo Hero',
        email: 'demo@fit.com',
        height: 170,
        weight: 65
    };

    // Reset or ensure default demo data
    demoData.totalCalories = 2200; // sync with leaderboard

    showDashboard();

    // Slight delay to ensure charts render then update
    setTimeout(() => {
        loadAllCharts();
    }, 100);
}

function toggleChat() {
    const win = document.getElementById('chat-window');
    if (!win) return;

    // 依照 style.css：預設 opacity:0、pointer-events:none、transform: translateY(20px)
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

/**
 * 保留 main.js 的 coach hover 行為（即使 index.html 已有 onmouseover/out 也不衝突）
 * 有 wrapper 才會綁定，沒有就跳過。
 */
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

document.addEventListener('DOMContentLoaded', () => {
    checkLogin();
    setupForms();
    setupCoachInteraction();

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
    if (isDemoMode && currentUser) {
        showDashboard();
        return;
    }

    try {
        const res = await fetch(`${API_URL}?action=get_user_info`, { credentials: 'same-origin' });
        const json = await res.json();

        if (json.success && json.data) {
            currentUser = json.data;
            showDashboard();
        } else {
            showLogin();
        }
    } catch (e) {
        console.error(e);
        showLogin();
    }
}

async function handleLogin(e) {
    e.preventDefault();

    const form = e.target;
    const email = form.email.value.trim();
    const password = form.password.value;

    const res = await fetchPost('login', { email, password });
    if (res.success) {
        currentUser = res.data;
        showDashboard();
    } else {
        alert(res.message || '登入失敗');
    }
}

async function handleRegister(e) {
    e.preventDefault();

    const form = e.target;
    const email = form.email.value.trim();
    const password = form.password.value;
    const display_name = form.display_name.value.trim();

    const res = await fetchPost('register', { email, password, display_name });
    if (res.success) {
        currentUser = res.data;
        showDashboard();
    } else {
        alert(res.message || '註冊失敗');
    }
}

async function logout() {
    if (isDemoMode) {
        isDemoMode = false;
        currentUser = null;
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

// --- API Helpers ---
async function fetchPost(action, data) {
    // Demo 模式：不打後端（ai_chat.js 也會用到）
    if (isDemoMode) return { success: true, data: null };

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

// --- UI ---
function showLogin() {
    document.getElementById('auth-view').classList.remove('hidden');
    document.getElementById('dashboard-view').classList.add('hidden');
}

function showDashboard() {
    document.getElementById('auth-view').classList.add('hidden');
    document.getElementById('dashboard-view').classList.remove('hidden');

    // 顯示 AI 教練
    const coachContainer = document.getElementById('ai-coach-container');
    if (coachContainer) coachContainer.classList.remove('hidden');

    updateProfileUI();
    loadAllCharts();
}

function setupForms() {
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const workoutForm = document.getElementById('workout-form');

    if (loginForm) loginForm.addEventListener('submit', handleLogin);
    if (registerForm) registerForm.addEventListener('submit', handleRegister);
    if (workoutForm) workoutForm.addEventListener('submit', handleAddWorkout);

    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) logoutBtn.addEventListener('click', logout);

    // Global range buttons
    document.querySelectorAll('[data-range]').forEach(btn => {
        btn.addEventListener('click', () => {
            setGlobalRange(btn.dataset.range);
        });
    });
}

// --- Profile ---
let currentAvatarId = 1;

function updateProfileUI() {
    const nameEl = document.getElementById('profile-name');
    const emailEl = document.getElementById('profile-email');
    const heightEl = document.getElementById('profile-height');
    const weightEl = document.getElementById('profile-weight');

    if (nameEl) nameEl.textContent = currentUser?.display_name || '(未命名)';
    if (emailEl) emailEl.textContent = currentUser?.email || '';
    if (heightEl) heightEl.textContent = currentUser?.height ?? '--';
    if (weightEl) weightEl.textContent = currentUser?.weight ?? '--';

    // Restore avatar
    try {
        const saved = localStorage.getItem(`avatar_${currentUser?.id || 'guest'}`);
        if (saved) {
            const img = document.getElementById('profile-avatar');
            if (img) img.src = saved;
        }
    } catch (_) { }
}

function enableProfileEdit() {
    document.getElementById('profile-view').classList.add('hidden');
    document.getElementById('profile-edit').classList.remove('hidden');

    // fill inputs
    const nameInput = document.getElementById('edit-display-name');
    const heightInput = document.getElementById('edit-height');
    const weightInput = document.getElementById('edit-weight');
    if (nameInput) nameInput.value = currentUser?.display_name || '';
    if (heightInput) heightInput.value = currentUser?.height ?? '';
    if (weightInput) weightInput.value = currentUser?.weight ?? '';
}

function cancelProfileEdit() {
    document.getElementById('profile-view').classList.remove('hidden');
    document.getElementById('profile-edit').classList.add('hidden');
}

async function saveProfile() {
    const nameInput = document.getElementById('edit-display-name');
    const heightInput = document.getElementById('edit-height');
    const weightInput = document.getElementById('edit-weight');

    const payload = {
        display_name: nameInput ? nameInput.value.trim() : '',
        height: heightInput ? Number(heightInput.value) : null,
        weight: weightInput ? Number(weightInput.value) : null
    };

    if (isDemoMode) {
        if (currentUser) {
            currentUser.display_name = payload.display_name || currentUser.display_name;
            currentUser.height = payload.height;
            currentUser.weight = payload.weight;
        }
        updateProfileUI();
        cancelProfileEdit();
        // Update leaderboard name if it matches
        const hero = demoData.leaderboard.find(u => u.display_name === 'Demo Hero' || u.total_calories === demoData.totalCalories);
        if (hero && currentUser.display_name) hero.display_name = currentUser.display_name;
        renderLeaderboard();

        alert('Demo 模式：個人資料已更新！');
        return;
    }

    const res = await fetchPost('update_profile', payload);
    if (res.success) {
        currentUser = res.data;
        updateProfileUI();
        cancelProfileEdit();
        alert('已更新個人資料');
    } else {
        alert(res.message || '更新失敗');
    }
}

function changeAvatar(delta) {
    currentAvatarId += delta;
    if (currentAvatarId < 1) currentAvatarId = 6;
    if (currentAvatarId > 6) currentAvatarId = 1;

    const img = document.getElementById('profile-avatar');
    if (img) img.src = `public/image/avatar${currentAvatarId}.png`;

    try {
        localStorage.setItem(`avatar_${currentUser?.id || 'guest'}`, img.src);
    } catch (_) { }
}

// --- Workout ---
async function handleAddWorkout(e) {
    e.preventDefault();

    const form = e.target;
    const type = form.type.value;
    const minutes = Number(form.minutes.value);
    const datePart = (document.getElementById('input-date-part')?.value || '').trim();
    const timePart = (document.getElementById('input-time-part')?.value || '').trim();

    let date = null;
    if (datePart) {
        date = datePart + (timePart ? ` ${timePart}:00` : ' 00:00:00');
    }

    const calories = calculateCalories(type, minutes, currentUser?.height, currentUser?.weight);

    if (isDemoMode) {
        // 1. Update Total
        demoData.totalCalories += calories;

        // 2. Update Pie
        // ['跑步', '重訓', '游泳', '腳踏車', '瑜珈', '其他']
        const typeIndex = demoData.pieLabels.indexOf(type);
        if (typeIndex >= 0) {
            demoData.pieData[typeIndex] += 1; // Count or Calories? Original pie chart seems to be count-based or time-based? 
            // Checking logic: Chart label says "運動分布", let's assume it tracks frequency or just modify it loosely.
            // Actually let's assume it tracks occurrences for simplicity in this demo logic, 
            // OR if you want it to match existing logic, it depends on what the backend does. 
            // The plan says "Update pie chart". Let's just increment the count for that type.
        }

        // 3. Update Bar (Add to today's bar for simplicity, or just last one)
        // Assume last bar is "Today"
        demoData.barData[6] += minutes;

        // 4. Update Line (Add to last point)
        demoData.lineData[6] += calories;

        // 5. Update Leaderboard (User is top one usually)
        if (demoData.leaderboard.length > 0) {
            // Find the user or just update the first one
            demoData.leaderboard[0].total_calories = demoData.totalCalories;
        }

        form.reset();
        loadAllCharts(); // Will use new demoData
        alert(`Demo 模式：已新增紀錄！ (消耗 ${calories} kcal)`);
        return;
    }

    const res = await fetchPost('add_workout', { type, minutes, date, calories });
    if (res.success) {
        alert('已新增運動紀錄');
        form.reset();
        loadAllCharts();
    } else {
        alert(res.message || '新增失敗');
    }
}

// 用 MET 粗估（保留 main1.js 的計算方式）
function calculateCalories(type, minutes, height, weight) {
    const MET = {
        '跑步': 9.8,
        '重訓': 6.0,
        '游泳': 8.0,
        '腳踏車': 7.5,
        '瑜珈': 3.0,
        '其他': 5.0
    };

    const w = Number(weight) || 60;
    const met = MET[type] || 5.0;
    const hours = (Number(minutes) || 0) / 60;

    // calories = MET * weight(kg) * hours
    return Math.max(0, Math.round(met * w * hours));
}

// --- Range ---
function setGlobalRange(range) {
    globalTimeRange = range;

    document.querySelectorAll('[data-range]').forEach(btn => {
        if (btn.dataset.range === range) btn.classList.add('active');
        else btn.classList.remove('active');
    });

    loadAllCharts();
}

// --- Charts (main1.js logic ONLY) ---
let barInstance = null;
let lineInstance = null;
let pieInstance = null;

let chartCache = buildEmptyChartData();

function buildEmptyChartData() {
    return {
        barLabels: ['週一', '週二', '週三', '週四', '週五', '週六', '週日'],
        barData: [0, 0, 0, 0, 0, 0, 0],
        lineLabels: [],
        lineData: [],
        pieLabels: ['跑步', '重訓', '游泳', '腳踏車', '瑜珈', '其他'],
        pieData: [0, 0, 0, 0, 0, 0],
        totalCalories: 0
    };
}

async function loadAllCharts() {
    // Demo 模式：維持 main1 的圖表渲染流程，但使用動態 demoData
    if (isDemoMode) {
        // Deep copy or re-assign to refresh
        let demoCharts = { ...demoData }; // Shallow copy base

        // Simple simulation of different ranges logic
        if (globalTimeRange === '1d') {
            demoCharts.barLabels = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'];
            demoCharts.barData = [0, 45, 0, 0, 30, 60, 0];
            demoCharts.lineLabels = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00'];
            demoCharts.lineData = [0, 300, 0, 0, 200, 450, 0];
        } else if (globalTimeRange === '1wk') {
            // Default demoData is 1wk
        } else if (globalTimeRange === '1m') {
            demoCharts.barLabels = ['Week 1', 'Week 2', 'Week 3', 'Week 4'];
            demoCharts.barData = [150, 200, 180, 250];
            demoCharts.lineLabels = ['Week 1', 'Week 2', 'Week 3', 'Week 4'];
            demoCharts.lineData = [1200, 1500, 1400, 1800];
        } else if (globalTimeRange === '3m') {
            demoCharts.barLabels = ['Oct', 'Nov', 'Dec'];
            demoCharts.barData = [600, 750, 800];
            demoCharts.lineLabels = ['Oct', 'Nov', 'Dec'];
            demoCharts.lineData = [4500, 5200, 6000];
        }

        chartCache = {
            barLabels: demoCharts.barLabels,
            barData: demoCharts.barData,
            lineLabels: demoCharts.lineLabels,
            lineData: demoCharts.lineData,
            pieLabels: demoCharts.pieLabels,
            pieData: demoCharts.pieData,
            totalCalories: demoCharts.totalCalories
        };

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
            applyDashboardData(json.data);
        } else {
            console.warn('get_dashboard_data failed:', json);
            chartCache = buildEmptyChartData();
            if (!barInstance) initCharts();
            updateCharts();
        }
    } catch (e) {
        console.error(e);
        chartCache = buildEmptyChartData();
        if (!barInstance) initCharts();
        updateCharts();
    }

    // 排行榜（仍走後端，若失敗會顯示「載入失敗」）
    renderLeaderboard();
}

function applyDashboardData(dashboard) {
    // Bar: minutes
    chartCache.barLabels = dashboard?.bar?.labels || [];
    chartCache.barData = dashboard?.bar?.data || [];

    // Line: calories
    chartCache.lineLabels = dashboard?.line?.labels || [];
    chartCache.lineData = dashboard?.line?.data || [];

    // Pie: calories by type
    chartCache.pieLabels = dashboard?.pie?.labels || [
        '跑步', '重訓', '游泳', '腳踏車', '瑜珈', '其他'
    ];
    chartCache.pieData = dashboard?.pie?.data || [0, 0, 0, 0, 0, 0];

    // Total calories
    chartCache.totalCalories = dashboard?.total_calories ?? 0;

    if (!barInstance) initCharts();
    updateCharts();

    // UI: total calories
    const totalEl = document.getElementById('total-calories');
    if (totalEl) totalEl.textContent = chartCache.totalCalories;
}

function initCharts() {
    // Bar
    const barCtx = document.getElementById('barChart')?.getContext('2d');
    if (barCtx) {
        barInstance = new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: chartCache.barLabels,
                datasets: [{
                    label: '運動時長 (分鐘)',
                    data: chartCache.barData
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }

    // Line
    const lineCtx = document.getElementById('lineChart')?.getContext('2d');
    if (lineCtx) {
        lineInstance = new Chart(lineCtx, {
            type: 'line',
            data: {
                labels: chartCache.lineLabels,
                datasets: [{
                    label: '消耗卡路里',
                    data: chartCache.lineData,
                    tension: 0.2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }

    // Pie
    const pieCtx = document.getElementById('pieChart')?.getContext('2d');
    if (pieCtx) {
        pieInstance = new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: chartCache.pieLabels,
                datasets: [{
                    label: '運動分布',
                    data: chartCache.pieData
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }
}

function updateCharts() {
    if (barInstance) {
        barInstance.data.labels = chartCache.barLabels;
        barInstance.data.datasets[0].data = chartCache.barData;
        barInstance.update();
    }
    if (lineInstance) {
        lineInstance.data.labels = chartCache.lineLabels;
        lineInstance.data.datasets[0].data = chartCache.lineData;
        lineInstance.update();
    }
    if (pieInstance) {
        pieInstance.data.labels = chartCache.pieLabels;
        pieInstance.data.datasets[0].data = chartCache.pieData;
        pieInstance.update();
    }
}

// --- Leaderboard ---
async function renderLeaderboard(prefetched) {
    const tbody = document.getElementById('leaderboard-body');
    if (!tbody) return;

    if (isDemoMode) {
        // Use demoData leaderboard
        prefetched = demoData.leaderboard;
    }

    const renderRows = (users) => {
        if (!users || users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#999;">目前沒有資料</td></tr>';
            return;
        }
        tbody.innerHTML = '';
        users.forEach((u, i) => {
            const tr = document.createElement('tr');
            const rank = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : String(i + 1);
            const name = u.display_name || u.name || '(未命名)';
            const total = u.total_calories ?? u.total ?? 0;

            tr.innerHTML = `
                <td style="width:70px;text-align:center;">${rank}</td>
                <td>${name}</td>
                <td style="text-align:right;">${total}</td>
            `;
            tbody.appendChild(tr);
        });
    };

    try {
        if (prefetched) {
            renderRows(prefetched);
            return;
        }

        const res = await fetch(`${API_URL}?action=get_leaderboard`, { credentials: 'same-origin' });
        const json = await res.json();
        if (json.success) {
            renderRows(json.data || []);
        } else {
            tbody.innerHTML = `<tr><td colspan="3" style="text-align:center;color:#e66;">載入失敗</td></tr>`;
        }
    } catch (e) {
        console.error(e);
        tbody.innerHTML = `<tr><td colspan="3" style="text-align:center;color:#e66;">載入失敗</td></tr>`;
    }
}

/**
 * === Optional (from main.js) ===
 * index.html 目前未使用，但保留可用性；沒有對應 DOM 也不會報錯。
 */
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('hidden');
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('hidden');
}

function saveName() {
    const nameInput = document.getElementById('edit-name');
    if (!nameInput) return;
    const name = nameInput.value.trim();
    if (!name) return;

    if (currentUser) currentUser.display_name = name;
    updateProfileUI();
    cancelProfileEdit();
}

// Avatar grid（需要頁面上有 #avatar-grid 才會動）
function generateAvatarGrid() {
    const grid = document.getElementById('avatar-grid');
    if (!grid) return;

    grid.innerHTML = '';
    for (let i = 1; i <= 5; i++) {
        const img = document.createElement('img');
        img.src = `public/image/${i}.png`;
        img.className = 'avatar-option';
        img.addEventListener('click', () => {
            const avatarImg = document.getElementById('current-avatar');
            if (avatarImg) avatarImg.src = img.src;
            if (currentUser?.id) localStorage.setItem(`avatar_${currentUser.id}`, img.src);
            closeModal('avatar-modal');
        });
        grid.appendChild(img);
    }
}
