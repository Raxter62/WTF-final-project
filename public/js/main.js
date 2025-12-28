// public/js/main.js - COMPLETE FIXED VERSION

const API_URL = 'submit.php';
let currentUser = null;
let isDemoMode = false;
let globalTimeRange = '1d';

const SPORT_ICONS = {
    '跑步': '🏃', '重訓': '🏋️', '腳踏車': '🚴',
    '游泳': '🏊', '瑜珈': '🧘', '其他': '🤸'
};

// === 確保 DOM 完全載入後才執行 ===
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initApp);
} else {
    // DOM 已經載入完成
    initApp();
}

function initApp() {
    console.log('✅ FitConnect 初始化開始...');
    
    // 延遲執行確保所有元素都已渲染
    setTimeout(() => {
        console.log('🔧 開始設置應用程式...');
        
        checkLogin();
        setupForms();
        generateAvatarGrid();
        setupCoachInteraction();
        setupDateTimeDefaults();
        
        console.log('✅ 應用程式設置完成');
    }, 200);
}

function setupDateTimeDefaults() {
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
}

// === Auth ===
async function checkLogin() {
    console.log('🔍 檢查登入狀態...');
    try {
        const res = await fetch(`${API_URL}?action=get_user_info`, { credentials: 'same-origin' });
        const json = await res.json();

        if (json.success && json.data) {
            console.log('✅ 已登入:', json.data.display_name);
            currentUser = json.data;
            showDashboard();
        } else {
            console.log('ℹ️ 未登入，顯示登入頁面');
            showLogin();
        }
    } catch (e) {
        console.error('❌ 檢查登入失敗:', e);
        showLogin();
    }
}

function showLogin() {
    const authView = document.getElementById('auth-view');
    const dashboardView = document.getElementById('dashboard-view');
    const coachContainer = document.getElementById('ai-coach-container');
    
    if (authView) authView.classList.remove('hidden');
    if (dashboardView) dashboardView.classList.add('hidden');
    if (coachContainer) coachContainer.classList.add('hidden');
}

function showDashboard() {
    const authView = document.getElementById('auth-view');
    const dashboardView = document.getElementById('dashboard-view');
    const coachContainer = document.getElementById('ai-coach-container');
    
    if (authView) authView.classList.add('hidden');
    if (dashboardView) dashboardView.classList.remove('hidden');
    if (coachContainer) coachContainer.classList.remove('hidden');

    const displayNameEl = document.getElementById('user-display-name');
    if (displayNameEl) {
        displayNameEl.textContent = currentUser.display_name;
    }

    updateProfileUI();

    const saved = localStorage.getItem(`avatar_${currentUser.id}`);
    const defaultAvatar = 'public/image/1.png';
    const avatarImg = document.getElementById('current-avatar');

    if (avatarImg) {
        if (saved && saved.includes('public/image/')) {
            avatarImg.src = saved;
        } else {
            avatarImg.src = defaultAvatar;
        }
    }

    setGlobalRange('1d');
}

function demoLogin() {
    console.log('🎭 進入 Demo 模式');
    isDemoMode = true;
    currentUser = { id: 999, display_name: 'Demo Hero', email: 'demo@fit.com' };
    showDashboard();
}

async function logout() {
    console.log('👋 登出中...');
    if (!isDemoMode) await fetchPost('logout', {});
    location.reload();
}

// === 表單設置（加強版）===
function setupForms() {
    console.log('🔧 設置表單事件...');
    
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const workoutForm = document.getElementById('add-workout-form');
    
    if (loginForm) {
        // 移除舊的事件監聽器（如果有）
        loginForm.onsubmit = null;
        
        loginForm.addEventListener('submit', handleLogin);
        console.log('✅ 登入表單已綁定');
        
        // 備用：也綁定 onsubmit
        loginForm.onsubmit = handleLogin;
    } else {
        console.error('❌ 找不到 login-form 元素');
    }
    
    if (registerForm) {
        registerForm.onsubmit = null;
        registerForm.addEventListener('submit', handleRegister);
        registerForm.onsubmit = handleRegister;
        console.log('✅ 註冊表單已綁定');
    } else {
        console.error('❌ 找不到 register-form 元素');
    }
    
    if (workoutForm) {
        workoutForm.onsubmit = handleAddWorkout;
        console.log('✅ 運動記錄表單已綁定');
    } else {
        console.log('ℹ️ add-workout-form 不存在（在儀表板頁面才有）');
    }
}

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

async function handleLogin(e) {
    e.preventDefault();
    console.log('🔐 處理登入請求...');

    const form = e.target;
    const emailInput = form.querySelector('input[name="email"]');
    const passwordInput = form.querySelector('input[name="password"]');
    
    if (!emailInput || !passwordInput) {
        console.error('❌ 找不到 email 或 password 欄位');
        alert('表單錯誤，請重新整理頁面');
        return;
    }
    
    const email = emailInput.value.trim();
    const password = passwordInput.value;

    if (!email || !password) {
        alert('請輸入 Email 與密碼');
        return;
    }

    console.log('📤 發送登入請求:', email);

    try {
        const json = await fetchPost('login', { email, password });
        console.log('📥 登入回應:', json);

        if (json.success) {
            console.log('✅ 登入成功，重新載入頁面');
            location.reload();
        } else {
            console.error('❌ 登入失敗:', json.message);
            alert('登入失敗: ' + (json.message || '帳號或密碼錯誤'));
        }
    } catch (err) {
        console.error('❌ 登入錯誤:', err);
        alert('連線錯誤: ' + err.message);
    }
}

async function handleRegister(e) {
    e.preventDefault();
    console.log('📝 處理註冊請求...');

    const form = e.target;
    const nameInput = form.querySelector('input[name="display_name"]');
    const emailInput = form.querySelector('input[name="email"]');
    const passwordInput = form.querySelector('input[name="password"]');
    
    if (!nameInput || !emailInput || !passwordInput) {
        console.error('❌ 找不到必要欄位');
        alert('表單錯誤，請重新整理頁面');
        return;
    }
    
    const display_name = nameInput.value.trim();
    const email = emailInput.value.trim();
    const password = passwordInput.value;

    if (!display_name || !email || !password) {
        alert('請輸入暱稱、Email 與密碼');
        return;
    }

    console.log('📤 發送註冊請求:', email);

    try {
        const json = await fetchPost('register', { display_name, email, password });
        console.log('📥 註冊回應:', json);

        if (json.success) {
            console.log('✅ 註冊成功，重新載入頁面');
            location.reload();
        } else {
            console.error('❌ 註冊失敗:', json.message);
            alert('註冊失敗: ' + (json.message || '未知錯誤'));
        }
    } catch (err) {
        console.error('❌ 註冊錯誤:', err);
        alert('連線錯誤: ' + err.message);
    }
}

async function handleAddWorkout(e) {
    e.preventDefault();
    if (isDemoMode) {
        alert('Demo 模式無法新增運動記錄');
        return;
    }

    const form = e.target;
    const datePart = form.querySelector('#input-date-part').value;
    const timePart = form.querySelector('#input-time-part').value;
    const type = form.querySelector('#input-sport').value;
    const minutes = parseInt(form.querySelector('#input-minutes').value) || 0;
    const calories = parseInt(form.querySelector('#input-calories').value) || 0;

    if (!datePart || !timePart || !type || minutes <= 0) {
        alert('請輸入完整資料');
        return;
    }

    const datetime = `${datePart} ${timePart}:00`;

    try {
        const json = await fetchPost('add_workout', {
            date: datetime,
            type,
            minutes,
            calories
        });

        if (json.success) {
            alert('運動記錄已新增');
            form.reset();
            setupDateTimeDefaults();
            setGlobalRange(globalTimeRange);
        } else {
            alert('新增失敗: ' + (json.message || ''));
        }
    } catch (err) {
        console.error('新增運動錯誤:', err);
        alert('連線錯誤: ' + err.message);
    }
}

function setGlobalRange(range) {
    globalTimeRange = range;
    fetchStats(range);
    loadLeaderboard();
}

async function fetchStats(range) {
    try {
        const json = isDemoMode
            ? getDemoStats(range)
            : await (await fetch(`${API_URL}?action=get_stats&range=${range}`, { credentials: 'same-origin' })).json();

        if (!json.success) {
            console.error('Stats failed:', json.message);
            return;
        }

        renderChart(json.daily, range);
        renderTypeChart(json.types);
    } catch (e) {
        console.error('Stats error:', e);
    }
}

function getDemoStats(range) {
    const daily = range === '1d'
        ? [{ date: '2025-01-01', total: 30 }, { date: '2025-01-02', total: 45 }]
        : [{ date: 'Week 1', total: 120 }];
    const types = [{ type: '跑步', total: 60 }, { type: '瑜珈', total: 30 }];
    return { success: true, daily, types, range };
}

let dailyChart, typeChart;

function renderChart(data, range) {
    const ctx = document.getElementById('daily-chart');
    if (!ctx) return;

    const labels = data.map(d => d.date);
    const values = data.map(d => d.total);

    if (dailyChart) dailyChart.destroy();

    dailyChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: '運動時間（分鐘）',
                data: values,
                borderColor: '#FF4757',
                backgroundColor: 'rgba(255,71,87,0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        }
    });
}

function renderTypeChart(data) {
    const ctx = document.getElementById('type-chart');
    if (!ctx) return;

    const labels = data.map(d => d.type);
    const values = data.map(d => d.total);

    if (typeChart) typeChart.destroy();

    typeChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: ['#FF4757', '#5352ED', '#F79F1F', '#00D2D3', '#EE5A6F']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });
}

async function loadLeaderboard() {
    const tbody = document.querySelector('#leaderboard-table tbody');
    if (!tbody) return;

    try {
        const json = isDemoMode
            ? { success: true, data: [{ rank: 1, display_name: 'Demo Hero', total: 180 }] }
            : await (await fetch(`${API_URL}?action=get_leaderboard`, { credentials: 'same-origin' })).json();

        if (!json.success || !json.data || !json.data.length) {
            tbody.innerHTML = '<tr><td colspan="3">暫無資料</td></tr>';
            return;
        }

        const users = json.data;
        tbody.innerHTML = '';

        users.forEach((u, i) => {
            const tr = document.createElement('tr');
            const rank = i < 3 ? ['🥇', '🥈', '🥉'][i] : (i + 1);
            const name = u.display_name || 'User';

            tr.innerHTML = `
                <td><span style="font-size: 1.2rem;">${rank}</span></td>
                <td><strong>${name}</strong></td>
                <td>${u.total}</td>
            `;

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
    if (isDemoMode && action !== 'get_user_info') {
        return { success: true, demo: true };
    }

    console.log(`📤 API 請求: ${action}`);

    const res = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(data)
    });

    const text = await res.text();
    
    try {
        const json = JSON.parse(text);
        console.log(`📥 API 回應 (${action}):`, json);
        return json;
    } catch (e) {
        console.error('❌ API 回應不是 JSON:', text);
        throw new Error('API 回傳不是 JSON（請檢查 submit.php 是否有錯誤）');
    }
}

function updateProfileUI() {
    // 個人資料 UI 更新
}

function generateAvatarGrid() {
    // 頭像選擇
}

function setupCoachInteraction() {
    // AI 教練
}

console.log('✅ main.js 載入完成');