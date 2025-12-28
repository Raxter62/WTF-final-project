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

    // 清空登入表單
    const loginForm = document.getElementById('login-form');
    if (loginForm) {
        const emailInput = loginForm.querySelector('input[name="email"]');
        const passwordInput = loginForm.querySelector('input[name="password"]');
        if (emailInput) emailInput.value = '';
        if (passwordInput) passwordInput.value = '';
    }

    // 清空註冊表單
    const registerForm = document.getElementById('register-form');
    if (registerForm) {
        const nameInput = registerForm.querySelector('input[name="display_name"]');
        const emailInput = registerForm.querySelector('input[name="email"]');
        const passwordInput = registerForm.querySelector('input[name="password"]');
        if (nameInput) nameInput.value = '';
        if (emailInput) emailInput.value = '';
        if (passwordInput) passwordInput.value = '';
    }

    // 切換回登入 tab
    const loginTab = document.getElementById('tab-login');
    const registerTab = document.getElementById('tab-register');

    if (loginForm && registerForm) {
        loginForm.classList.remove('hidden');
        registerForm.classList.add('hidden');
    }

    // 設定按鈕樣式：登入按鈕為橘色
    if (loginTab) {
        loginTab.classList.add('active');
        loginTab.style.backgroundColor = '#FF6B35';
        loginTab.style.color = 'white';
    }
    if (registerTab) {
        registerTab.classList.remove('active');
        registerTab.style.backgroundColor = 'transparent';
        registerTab.style.color = '#666';
    }

    console.log('✅ 已清空表單欄位');
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

    // 載入用戶頭像
    currentAvatarIndex = currentUser.avatar_id || 1;
    const avatarImg = document.getElementById('current-avatar');
    if (avatarImg) {
        avatarImg.src = `public/image/${currentAvatarIndex}.png`;
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

    if (!isDemoMode) {
        // 呼叫 API 清除 Session
        await fetchPost('logout', {});
    }

    // 清除前端狀態
    isDemoMode = false;
    currentUser = null;

    // 直接切換回登入頁面（不重新載入）
    showLogin();
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
    const loginBtn = document.getElementById('tab-login');
    const registerBtn = document.getElementById('tab-register');

    if (!loginFn || !regFn) return;

    if (tab === 'login') {
        // 顯示登入表單
        loginFn.classList.remove('hidden');
        regFn.classList.add('hidden');

        // 切換按鈕樣式
        if (loginBtn) {
            loginBtn.classList.add('active');
            loginBtn.style.backgroundColor = '#FF6B35';
            loginBtn.style.color = 'white';
        }
        if (registerBtn) {
            registerBtn.classList.remove('active');
            registerBtn.style.backgroundColor = 'transparent';
            registerBtn.style.color = '#666';
        }
    } else {
        // 顯示註冊表單
        loginFn.classList.add('hidden');
        regFn.classList.remove('hidden');

        // 切換按鈕樣式
        if (loginBtn) {
            loginBtn.classList.remove('active');
            loginBtn.style.backgroundColor = 'transparent';
            loginBtn.style.color = '#666';
        }
        if (registerBtn) {
            registerBtn.classList.add('active');
            registerBtn.style.backgroundColor = '#FF6B35';
            registerBtn.style.color = 'white';
        }
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
            console.log('✅ 登入成功，載入使用者資訊...');

            // 取得使用者資訊
            const userRes = await fetch(`${API_URL}?action=get_user_info`, {
                credentials: 'same-origin'
            });
            const userData = await userRes.json();

            if (userData.success && userData.data) {
                console.log('✅ 使用者資訊:', userData.data);
                currentUser = userData.data;
                showDashboard();
            } else {
                alert('無法取得使用者資訊');
            }
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
            console.log('✅ 註冊成功，載入使用者資訊...');

            // 取得使用者資訊
            const userRes = await fetch(`${API_URL}?action=get_user_info`, {
                credentials: 'same-origin'
            });
            const userData = await userRes.json();

            if (userData.success && userData.data) {
                console.log('✅ 使用者資訊:', userData.data);
                currentUser = userData.data;
                showDashboard();
            } else {
                alert('無法取得使用者資訊');
            }
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

    if (!currentUser || !currentUser.height || !currentUser.weight) {
        alert('請先完善個人資料');
        showEditProfileModal();
        return;
    }

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
    // 更新身高體重顯示
    if (!currentUser) return;

    const heightEl = document.getElementById('user-height');
    const weightEl = document.getElementById('user-weight');

    if (heightEl && currentUser.height) {
        heightEl.textContent = currentUser.height + ' cm';
    } else if (heightEl) {
        heightEl.textContent = '未設定';
    }

    if (weightEl && currentUser.weight) {
        weightEl.textContent = currentUser.weight + ' kg';
    } else if (weightEl) {
        weightEl.textContent = '未設定';
    }
}

async function setupCoachInteraction() {
    // 確保 AI 教練視窗隱藏
    const chatWindow = document.getElementById('chat-window');
    const coachContainer = document.getElementById('ai-coach-container');

    if (chatWindow) chatWindow.style.display = 'none';
    if (coachContainer) coachContainer.classList.remove('hidden');
}

// 卡路里計算
window.calculateCalories = function () {
    const typeSelect = document.getElementById('input-type');
    const minutesInput = document.getElementById('input-minutes');
    const caloriesInput = document.getElementById('input-calories');
    const calcValDisplay = document.getElementById('calc-val');
    const displayArea = document.getElementById('calorie-display-area');

    if (!typeSelect || !minutesInput || !caloriesInput) return;

    const type = typeSelect.value;
    const minutes = parseInt(minutesInput.value) || 0;

    // MET values
    const MET_VALUES = {
        '跑步': 10,
        '重訓': 4,
        '腳踏車': 8,
        '游泳': 6,
        '瑜珈': 3,
        '其他': 2
    };

    if (!currentUser || !currentUser.weight) {
        // 如果沒有體重，隱藏顯示區並不計算
        displayArea.classList.add('hidden');
        caloriesInput.value = 0;
        return;
    }

    const met = MET_VALUES[type] || 2;
    const weight = parseFloat(currentUser.weight);
    const kcal = Math.round(((met * 3.5 * weight) / 200) * minutes);

    calcValDisplay.textContent = kcal;
    caloriesInput.value = kcal;
    displayArea.classList.remove('hidden');
};

// 顯示編輯個人資料彈窗 (名字、身高、體重)
function showEditProfileModal() {
    console.log('📝 開啟編輯個人資料彈窗');

    const modal = document.createElement('div');
    modal.id = 'edit-profile-modal';
    modal.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.5); display: flex;
        justify-content: center; align-items: center; z-index: 9999;
        animation: fadeIn 0.3s ease;
    `;

    const currentName = currentUser.display_name || '';
    const currentHeight = currentUser.height || '';
    const currentWeight = currentUser.weight || '';

    modal.innerHTML = `
        <div style="background: white; padding: 2rem; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); max-width: 400px; width: 90%; animation: slideUp 0.3s ease;">
            <h2 style="margin: 0 0 1.5rem 0; color: #333; font-size: 1.5rem;">✏️ 編輯個人資料</h2>
            
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: #666; font-weight: 600;">暱稱</label>
                <input type="text" id="modal-name" value="${currentName}" style="width: 100%; padding: 0.75rem; border: 2px solid #ddd; border-radius: 8px; font-size: 1rem;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: #666; font-weight: 600;">身高 (cm)</label>
                <input type="number" id="modal-height" value="${currentHeight}" placeholder="例如：170" style="width: 100%; padding: 0.75rem; border: 2px solid #ddd; border-radius: 8px; font-size: 1rem;">
            </div>
            
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: #666; font-weight: 600;">體重 (kg)</label>
                <input type="number" id="modal-weight" value="${currentWeight}" placeholder="例如：65" style="width: 100%; padding: 0.75rem; border: 2px solid #ddd; border-radius: 8px; font-size: 1rem;">
            </div>
            
            <div style="display: flex; gap: 1rem;">
                <button onclick="closeEditProfileModal()" style="flex: 1; padding: 0.75rem; border: 2px solid #ddd; border-radius: 8px; background: white; color: #666; font-weight: 600; cursor: pointer;">取消</button>
                <button onclick="saveProfile()" style="flex: 1; padding: 0.75rem; border: none; border-radius: 8px; background: #FF6B35; color: white; font-weight: 600; cursor: pointer;">儲存</button>
            </div>
        </div>
    `;

    // 加入 CSS 動畫
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    `;
    document.head.appendChild(style);

    document.body.appendChild(modal);

    // 點擊背景關閉
    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            closeEditProfileModal();
        }
    });
}

// 關閉彈窗
function closeEditProfileModal() {
    const modal = document.getElementById('edit-profile-modal');
    if (modal) {
        modal.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => modal.remove(), 300);
    }
}

// 儲存個人資料
async function saveProfile() {
    const nameInput = document.getElementById('modal-name');
    const heightInput = document.getElementById('modal-height');
    const weightInput = document.getElementById('modal-weight');

    const display_name = nameInput.value.trim();
    const height = parseFloat(heightInput.value);
    const weight = parseFloat(weightInput.value);

    if (!display_name) {
        alert('請輸入暱稱');
        return;
    }

    // 驗證
    if (!height || height <= 0 || height > 300) {
        alert('請輸入有效的身高（1-300 cm）');
        return;
    }

    if (!weight || weight <= 0 || weight > 500) {
        alert('請輸入有效的體重（1-500 kg）');
        return;
    }

    console.log('💾 儲存資料:', display_name, height, weight);

    try {
        const res = await fetch(`${API_URL}?action=update_profile`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ display_name, height, weight })
        });

        const json = await res.json();

        if (json.success) {
            currentUser.display_name = display_name;
            currentUser.height = height;
            currentUser.weight = weight;

            // 更新 UI
            const nameEl = document.getElementById('user-display-name');
            if (nameEl) nameEl.textContent = display_name;
            updateProfileUI();

            closeEditProfileModal();
            alert('✅ 個人資料已更新！');
        } else {
            alert('儲存失敗: ' + (json.message || '未知錯誤'));
        }
    } catch (err) {
        console.error('❌ 儲存錯誤:', err);
    }
}

function generateAvatarGrid() {
    // 頭像選擇
}

function setupCoachInteraction() {
    // AI 教練
}

// ========== 頭像功能 ==========

// 全域變數
let currentAvatarIndex = 1;  // 預設頭像編號
const TOTAL_AVATARS = 11;    // 總共有 11 個頭像

window.changeAvatar = function (direction) {
    console.log('切換頭像:', direction);

    const avatarImg = document.getElementById('current-avatar');
    if (!avatarImg) {
        console.error('找不到頭像元素');
        return;
    }

    // 添加淡出動畫
    avatarImg.style.opacity = '0';
    avatarImg.style.transform = 'scale(0.8)';

    setTimeout(() => {
        // 更新頭像索引
        currentAvatarIndex += direction;

        // 循環處理
        if (currentAvatarIndex > TOTAL_AVATARS) {
            currentAvatarIndex = 1;
        } else if (currentAvatarIndex < 1) {
            currentAvatarIndex = TOTAL_AVATARS;
        }

        // 更新圖片
        avatarImg.src = `public/image/${currentAvatarIndex}.png`;

        // 添加淡入動畫
        setTimeout(() => {
            avatarImg.style.opacity = '1';
            avatarImg.style.transform = 'scale(1)';
        }, 50);

        // 如果已登入，更新到伺服器
        if (currentUser && !isDemoMode) {
            updateAvatarOnServer(currentAvatarIndex);
        }
    }, 200);
};

async function updateAvatarOnServer(avatarId) {
    try {
        const res = await fetch(`${API_URL}?action=update_avatar`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ avatar_id: avatarId })
        });

        const json = await res.json();

        if (json.success) {
            console.log('✅ 頭像已更新');
            if (currentUser) {
                currentUser.avatar_id = avatarId;
            }
        } else {
            console.error('❌ 頭像更新失敗:', json.message);
        }
    } catch (err) {
        console.error('❌ 頭像更新錯誤:', err);
    }
}

console.log('✅ main.js 載入完成');