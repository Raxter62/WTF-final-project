// public/js/main.js - 完整版本

// ========== 設定 ==========

const API_URL = 'submit.php';

// LINE Bot 設定
const LINE_BOT_ID = '@063jezzz';  // FitConnect LINE Bot
const LINE_BOT_URL = `https://line.me/R/ti/p/${LINE_BOT_ID}`;

// ========== 全域變數 ==========

let currentUser = null;
let isDemoMode = false;
let globalTimeRange = '1d';
let currentAvatarIndex = 1;  // 預設頭像編號
const TOTAL_AVATARS = 11;    // 總共有 11 個頭像

// 運動圖示
const SPORT_ICONS = {
    '跑步': '🏃', '重訓': '🏋️', '腳踏車': '🚴',
    '游泳': '🏊', '瑜珈': '🧘', '其他': '🤸'
};

// 初始化
document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 頁面載入完成');
    checkLogin();
    setupForms();
    generateAvatarGrid();
    
    // 設定預設日期時間
    const datePart = document.getElementById('input-date-part');
    const timePart = document.getElementById('input-time-part');
    if (datePart && timePart) {
        const now = new Date();
        datePart.value = now.toISOString().split('T')[0];
        timePart.value = now.toTimeString().slice(0, 5);
    }
});

// ========== 認證相關 ==========

async function checkLogin() {
    try {
        const res = await fetch(`${API_URL}?action=get_user_info`);
        const json = await res.json();
        
        if (json.success && json.data) {
            console.log('✅ 已登入:', json.data);
            currentUser = json.data;
            showDashboard();
        } else {
            console.log('❌ 未登入');
            showLogin();
        }
    } catch (e) {
        console.error('檢查登入失敗:', e);
        showLogin();
    }
}

function showLogin() {
    console.log('顯示登入頁面');
    document.getElementById('auth-view').classList.remove('hidden');
    document.getElementById('dashboard-view').classList.add('hidden');
    const coachContainer = document.getElementById('ai-coach-container');
    if (coachContainer) coachContainer.classList.add('hidden');
    
    // 重置表單狀態
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const loginTab = document.getElementById('tab-login');
    const registerTab = document.getElementById('tab-register');
    
    // 切換回登入 tab
    if (loginForm) loginForm.classList.remove('hidden');
    if (registerForm) registerForm.classList.add('hidden');
    if (loginTab) loginTab.classList.add('active');
    if (registerTab) registerTab.classList.remove('active');
    
    // 清空表單欄位
    if (loginForm) loginForm.reset();
    if (registerForm) registerForm.reset();
    
    // 清除錯誤訊息
    const authMsg = document.getElementById('auth-msg');
    if (authMsg) authMsg.textContent = '';
    
    // 重置頭像為預設值
    currentAvatarIndex = 1;
    const avatarImg = document.getElementById('current-avatar');
    if (avatarImg) {
        avatarImg.src = 'public/image/1.png';
        avatarImg.style.opacity = '1';
        avatarImg.style.transform = 'scale(1)';
    }
    
    // 重置用戶名稱顯示
    const nameDisplay = document.getElementById('user-display-name');
    if (nameDisplay) {
        nameDisplay.textContent = 'User';
    }
}

function showDashboard() {
    console.log('顯示主控台');
    document.getElementById('auth-view').classList.add('hidden');
    document.getElementById('dashboard-view').classList.remove('hidden');
    const coachContainer = document.getElementById('ai-coach-container');
    if (coachContainer) coachContainer.classList.remove('hidden');
    
    // 更新用戶資訊
    const nameEl = document.getElementById('user-display-name');
    if (nameEl) nameEl.textContent = currentUser.display_name || 'User';
    
    // 載入用戶頭像
    currentAvatarIndex = currentUser.avatar_id || 1;
    const avatarImg = document.getElementById('current-avatar');
    if (avatarImg) {
        avatarImg.src = `public/image/${currentAvatarIndex}.png`;
    }
    
    // Demo 模式：禁用表單
    if (isDemoMode) {
        disableWorkoutForm();
    }
    
    // 檢查 LINE 綁定狀態
    checkLineBindStatus();
    
    // 載入圖表
    setGlobalRange('1d');
}

function disableWorkoutForm() {
    const form = document.getElementById('add-workout-form');
    if (!form) return;
    
    // 禁用所有輸入欄位
    const inputs = form.querySelectorAll('input, select, button');
    inputs.forEach(input => {
        input.disabled = true;
        input.style.opacity = '0.5';
        input.style.cursor = 'not-allowed';
    });
    
    // 在表單上方加入提示
    const formContainer = form.parentElement;
    if (formContainer) {
        const existingNotice = formContainer.querySelector('.demo-notice');
        if (!existingNotice) {
            const notice = document.createElement('div');
            notice.className = 'demo-notice';
            notice.style.cssText = `
                background: rgba(255, 165, 2, 0.1);
                border: 2px dashed #ffa502;
                border-radius: 12px;
                padding: 1rem;
                margin-bottom: 1rem;
                text-align: center;
            `;
            notice.innerHTML = `
                <p style="color: #f57c00; font-weight: bold; margin: 0 0 0.3rem 0; font-size: 1rem;">
                    🎮 Demo 模式
                </p>
                <p style="color: #666; font-size: 0.9rem; margin: 0;">
                    此功能僅在正式登入後可用，請先註冊或登入帳號
                </p>
            `;
            formContainer.insertBefore(notice, form);
        }
    }
}

function demoLogin() {
    console.log('Demo 模式登入');
    isDemoMode = true;
    currentUser = { 
        id: 999, 
        display_name: 'Demo User', 
        email: 'demo@fit.com',
        avatar_id: 1 
    };
    showDashboard();
}

async function logout() {
    console.log('登出');
    
    if (!isDemoMode) {
        // 正常模式：先呼叫 API 清除伺服器 Session
        await fetch(`${API_URL}?action=logout`, { method: 'POST' });
    }
    
    // 清除前端狀態
    isDemoMode = false;
    currentUser = null;
    
    // 直接切換回登入頁面（不重新整理）
    showLogin();
}

// ========== 頭像功能 ==========

window.changeAvatar = function(direction) {
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

// ========== 表單處理 ==========

function setupForms() {
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const workoutForm = document.getElementById('add-workout-form');
    
    if (loginForm) {
        loginForm.onsubmit = handleLogin;
        console.log('✅ 登入表單已綁定');
    }
    if (registerForm) {
        registerForm.onsubmit = handleRegister;
        console.log('✅ 註冊表單已綁定');
    }
    if (workoutForm) {
        workoutForm.onsubmit = handleAddWorkout;
        console.log('✅ 新增運動表單已綁定');
    }
}

function switchTab(tab) {
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const loginBtn = document.getElementById('tab-login');
    const registerBtn = document.getElementById('tab-register');
    
    if (tab === 'login') {
        // 切換到登入
        loginForm.classList.remove('hidden');
        registerForm.classList.add('hidden');
        loginBtn.classList.add('active');
        registerBtn.classList.remove('active');
    } else {
        // 切換到註冊
        loginForm.classList.add('hidden');
        registerForm.classList.remove('hidden');
        loginBtn.classList.remove('active');
        registerBtn.classList.add('active');
    }
}

async function handleLogin(e) {
    e.preventDefault();
    console.log('🔐 處理登入...');
    
    const form = e.target;
    const email = form.querySelector('input[name="email"]').value;
    const password = form.querySelector('input[name="password"]').value;
    
    console.log('Email:', email);
    
    try {
        const res = await fetch(`${API_URL}?action=login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password })
        });
        
        const json = await res.json();
        console.log('登入回應:', json);
        
        if (json.success) {
            console.log('✅ 登入成功');
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
    console.log('📝 處理註冊...');
    
    const form = e.target;
    const displayName = form.querySelector('input[name="display_name"]').value;
    const email = form.querySelector('input[name="email"]').value;
    const password = form.querySelector('input[name="password"]').value;
    
    try {
        const res = await fetch(`${API_URL}?action=register`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                email, 
                password, 
                display_name: displayName 
            })
        });
        
        const json = await res.json();
        console.log('註冊回應:', json);
        
        if (json.success) {
            console.log('✅ 註冊成功');
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
    
    // Demo 模式禁用
    if (isDemoMode) {
        alert('🎮 Demo 模式無法新增運動記錄\n請先註冊或登入帳號');
        return;
    }
    
    console.log('➕ 新增運動紀錄...');
    
    const datePart = document.getElementById('input-date-part').value;
    const timePart = document.getElementById('input-time-part').value;
    const type = document.getElementById('input-type').value;
    const minutes = document.getElementById('input-minutes').value;
    const calories = document.getElementById('input-calories').value;
    
    const fullDate = `${datePart} ${timePart}:00`;
    
    try {
        const res = await fetch(`${API_URL}?action=add_workout`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ date: fullDate, type, minutes, calories })
        });
        
        const json = await res.json();
        console.log('新增回應:', json);
        
        if (json.success) {
            alert('新增成功！');
            location.reload();
        } else {
            alert('失敗: ' + json.message);
        }
    } catch (err) {
        console.error('新增錯誤:', err);
        alert('連線錯誤: ' + err.message);
    }
}

// ========== 圖表相關 ==========

let barChart = null;
let lineChart = null;
let pieChart = null;
let realData = null;

async function setGlobalRange(range) {
    console.log('📊 切換時間範圍:', range);
    globalTimeRange = range;
    
    // 更新按鈕狀態
    document.querySelectorAll('.g-time-btn').forEach(btn => {
        btn.classList.remove('active');
        // 根據按鈕文字判斷是否為當前範圍
        const btnText = btn.textContent;
        if ((range === '1d' && btnText.includes('1天')) ||
            (range === '1wk' && btnText.includes('1周')) ||
            (range === '1m' && btnText.includes('1月')) ||
            (range === '3m' && btnText.includes('3月'))) {
            btn.classList.add('active');
        }
    });
    
    // 載入資料
    await loadStatsData();
    updateCharts();
}

async function loadStatsData() {
    if (isDemoMode) {
        realData = generateDemoData();
        return;
    }
    
    try {
        // 傳遞時間範圍參數
        const res = await fetch(`${API_URL}?action=get_stats&range=${globalTimeRange}`);
        const json = await res.json();
        
        if (json.success) {
            console.log(`✅ 載入統計資料 (${json.range}):`, json);
            realData = {
                daily: json.daily || [],
                types: json.types || []
            };
        } else {
            console.error('載入失敗:', json.message);
            realData = { daily: [], types: [] };
        }
    } catch (err) {
        console.error('載入錯誤:', err);
        realData = { daily: [], types: [] };
    }
}

function generateDemoData() {
    const daily = [];
    const dataCount = globalTimeRange === '1d' ? 7 : 
                      globalTimeRange === '1wk' ? 4 : 
                      globalTimeRange === '1m' ? 3 : 2;
    
    for (let i = dataCount - 1; i >= 0; i--) {
        const date = new Date();
        date.setDate(date.getDate() - i);
        daily.push({
            date: date.toISOString().split('T')[0],
            total: Math.floor(Math.random() * 60) + 20
        });
    }
    
    return {
        daily: daily,
        types: [
            { type: '跑步', total: Math.floor(Math.random() * 100) + 50 },
            { type: '重訓', total: Math.floor(Math.random() * 80) + 40 },
            { type: '游泳', total: Math.floor(Math.random() * 60) + 30 }
        ]
    };
}

function updateCharts() {
    if (!realData || !realData.daily) {
        console.log('⚠️  沒有資料可顯示');
        return;
    }
    
    console.log('🎨 更新圖表');
    
    // 處理資料 - 根據範圍格式化標籤
    let labels = [];
    
    if (globalTimeRange === '1d') {
        // 1天：顯示日期 (2025-12-20, 2025-12-21...)
        labels = realData.daily.map(d => {
            const date = new Date(d.date);
            return `${date.getMonth() + 1}/${date.getDate()}`;
        });
    } else if (globalTimeRange === '1wk') {
        // 1周：顯示週起始日期 (12/20週, 12/27週...)
        labels = realData.daily.map(d => {
            const date = new Date(d.date);
            return `${date.getMonth() + 1}/${date.getDate()}`;
        });
    } else if (globalTimeRange === '1m') {
        // 1月：顯示月份 (2024-11, 2024-12...)
        labels = realData.daily.map(d => {
            const parts = d.date.split('-');
            return `${parts[0]}年${parseInt(parts[1])}月`;
        });
    } else if (globalTimeRange === '3m') {
        // 3月：顯示季度 (2024Q3, 2024Q4...)
        labels = realData.daily.map(d => d.date);
    }
    
    const dailyMinutes = realData.daily.map(d => parseInt(d.total) || 0);
    
    const typeLabels = realData.types.map(t => t.type);
    const typeData = realData.types.map(t => parseInt(t.total) || 0);
    
    // 初始化或更新圖表
    if (!barChart) {
        initCharts(labels, dailyMinutes, typeLabels, typeData);
    } else {
        updateExistingCharts(labels, dailyMinutes, typeLabels, typeData);
    }
    
    // 更新排行榜
    loadLeaderboard();
}

function initCharts(labels, dailyMinutes, typeLabels, typeData) {
    console.log('🎨 初始化圖表');
    
    // 長條圖
    const ctxBar = document.getElementById('chart-bar-time');
    if (ctxBar) {
        barChart = new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: '運動時間 (分鐘)',
                    data: dailyMinutes,
                    backgroundColor: '#667eea',
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 1000,
                    easing: 'easeInOutQuart',
                    delay: (context) => {
                        return context.dataIndex * 50; // 逐個柱子動畫
                    }
                },
                scales: { 
                    y: { 
                        beginAtZero: true,
                        ticks: {
                            font: { size: 12 }
                        }
                    },
                    x: {
                        ticks: {
                            font: { size: 11 },
                            maxRotation: 45,
                            minRotation: 0
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        labels: { font: { size: 13 } }
                    }
                }
            }
        });
    }
    
    // 折線圖
    const ctxLine = document.getElementById('chart-line-calories');
    if (ctxLine) {
        lineChart = new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: '消耗熱量 (kcal)',
                    data: dailyMinutes.map(m => m * 10),
                    borderColor: '#ff6b6b',
                    backgroundColor: 'rgba(255,107,107,0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#ff6b6b',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 1200,
                    easing: 'easeInOutQuart'
                },
                scales: { 
                    y: { 
                        beginAtZero: true,
                        ticks: {
                            font: { size: 12 }
                        }
                    },
                    x: {
                        ticks: {
                            font: { size: 11 },
                            maxRotation: 45,
                            minRotation: 0
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        labels: { font: { size: 13 } }
                    }
                }
            }
        });
    }
    
    // 圓餅圖
    const ctxPie = document.getElementById('chart-pie-types');
    if (ctxPie) {
        pieChart = new Chart(ctxPie, {
            type: 'pie',
            data: {
                labels: typeLabels,
                datasets: [{
                    data: typeData,
                    backgroundColor: [
                        '#667eea',
                        '#ff6b6b',
                        '#feca57',
                        '#48dbfb',
                        '#ff9ff3',
                        '#54a0ff',
                        '#00d2d3'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 1000,
                    easing: 'easeInOutQuart',
                    animateRotate: true,
                    animateScale: true
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: { 
                            font: { size: 12 },
                            padding: 10
                        }
                    }
                }
            }
        });
    }
}

function updateExistingCharts(labels, dailyMinutes, typeLabels, typeData) {
    // 取得所有圖表的 canvas
    const charts = [
        { chart: barChart, canvas: document.getElementById('chart-bar-time')?.parentElement },
        { chart: lineChart, canvas: document.getElementById('chart-line-calories')?.parentElement },
        { chart: pieChart, canvas: document.getElementById('chart-pie-types')?.parentElement }
    ];
    
    // 1. 淡出所有圖表
    charts.forEach(({ canvas }) => {
        if (canvas) {
            canvas.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            canvas.style.opacity = '0';
            canvas.style.transform = 'scale(0.95)';
        }
    });
    
    // 2. 等待淡出完成後更新資料
    setTimeout(() => {
        // 更新長條圖
        if (barChart) {
            barChart.data.labels = labels;
            barChart.data.datasets[0].data = dailyMinutes;
            barChart.update('none'); // 無動畫更新
        }
        
        // 更新折線圖
        if (lineChart) {
            lineChart.data.labels = labels;
            lineChart.data.datasets[0].data = dailyMinutes.map(m => m * 10);
            lineChart.update('none');
        }
        
        // 更新圓餅圖
        if (pieChart) {
            pieChart.data.labels = typeLabels;
            pieChart.data.datasets[0].data = typeData;
            pieChart.update('none');
        }
        
        // 3. 淡入所有圖表
        setTimeout(() => {
            charts.forEach(({ canvas }) => {
                if (canvas) {
                    canvas.style.opacity = '1';
                    canvas.style.transform = 'scale(1)';
                }
            });
        }, 50);
        
    }, 300); // 等待淡出完成
}

// ========== 排行榜 ==========

async function loadLeaderboard() {
    try {
        const res = await fetch(`${API_URL}?action=get_leaderboard`);
        const json = await res.json();
        
        if (json.success) {
            displayLeaderboard(json.data || []);
        }
    } catch (err) {
        console.error('載入排行榜失敗:', err);
    }
}

function displayLeaderboard(data) {
    const tbody = document.querySelector('#leaderboard-body');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    data.forEach(row => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${row.rank}</td>
            <td>${row.display_name}</td>
            <td>${row.total}</td>
        `;
        tbody.appendChild(tr);
    });
}

// ========== 頭像 ==========

function generateAvatarGrid() {
    const grid = document.getElementById('avatar-grid');
    if (!grid) return;
    
    for (let i = 1; i <= 25; i++) {
        const div = document.createElement('div');
        div.className = 'avatar-item';
        div.innerHTML = `<img src="public/image/${i}.png" alt="Avatar ${i}">`;
        div.onclick = () => selectAvatar(i);
        grid.appendChild(div);
    }
}

function selectAvatar(n) {
    const path = `public/image/${n}.png`;
    const img = document.getElementById('current-avatar');
    if (img) img.src = path;
    if (currentUser) {
        localStorage.setItem(`avatar_${currentUser.id}`, path);
    }
}

// ========== 工具函數 ==========

function calculateCalories() {
    const type = document.getElementById('input-type')?.value;
    const minutes = parseInt(document.getElementById('input-minutes')?.value) || 0;
    
    const rates = {
        '跑步': 10, '重訓': 8, '腳踏車': 7,
        '游泳': 11, '瑜珈': 4, '其他': 5
    };
    
    const calories = minutes * (rates[type] || 5);
    
    const calorieInput = document.getElementById('input-calories');
    const calorieDisplay = document.getElementById('calc-val');
    const displayArea = document.getElementById('calorie-display-area');
    
    if (calorieInput) calorieInput.value = calories;
    if (calorieDisplay) calorieDisplay.textContent = calories;
    if (displayArea && minutes > 0) {
        displayArea.classList.remove('hidden');
    } else if (displayArea) {
        displayArea.classList.add('hidden');
    }
}

function toggleChat() {
    const win = document.getElementById('chat-window');
    if (!win) return;
    
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

// ========== LINE 綁定功能 ==========

function checkLineBindStatus() {
    if (!currentUser) return;
    
    const notBound = document.getElementById('not-bound');
    const alreadyBound = document.getElementById('already-bound');
    
    // Demo 模式：顯示提示訊息
    if (isDemoMode) {
        if (notBound) {
            notBound.innerHTML = `
                <div style="text-align: center; padding: 2rem; background: rgba(255, 165, 2, 0.1); border-radius: 12px; border: 2px dashed #ffa502;">
                    <p style="color: #f57c00; font-size: 1.1rem; font-weight: bold; margin: 0 0 0.5rem 0;">
                        🎮 Demo 模式
                    </p>
                    <p style="color: #666; font-size: 0.95rem; margin: 0;">
                        LINE 綁定功能僅在正式登入後可用<br>
                        請先註冊或登入帳號
                    </p>
                </div>
            `;
        }
        if (alreadyBound) alreadyBound.style.display = 'none';
        return;
    }
    
    // 正常模式：檢查綁定狀態
    if (currentUser.line_user_id) {
        // 已綁定
        if (notBound) notBound.style.display = 'none';
        if (alreadyBound) alreadyBound.style.display = 'block';
    } else {
        // 未綁定
        if (notBound) notBound.style.display = 'block';
        if (alreadyBound) alreadyBound.style.display = 'none';
    }
}

async function generateBindCode() {
    console.log('🔗 產生綁定碼');
    
    // Demo 模式禁用
    if (isDemoMode) {
        alert('Demo 模式無法使用 LINE 綁定功能\n請先註冊或登入帳號');
        return;
    }
    
    // 取得按鈕，加上載入效果
    const button = event.target;
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = '產生中...';
    button.style.opacity = '0.7';
    
    try {
        const res = await fetch(`${API_URL}?action=generate_bind_code`, {
            method: 'POST'
        });
        const json = await res.json();
        
        if (json.success) {
            const code = json.code;
            console.log('✅ 綁定碼:', code);
            
            // 恢復按鈕
            button.textContent = '✓ 已產生';
            button.style.opacity = '1';
            
            // 取得元素
            const codeText = document.getElementById('bind-code-text');
            const codeDisplay = document.getElementById('bind-code-display');
            
            if (codeDisplay && codeText) {
                // 先設定為隱藏狀態
                codeDisplay.style.display = 'block';
                codeDisplay.style.opacity = '0';
                codeDisplay.style.transform = 'translateY(-20px)';
                codeDisplay.style.transition = 'all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1)';
                
                // 產生 QR Code
                const qrcodeDiv = document.getElementById('qrcode');
                if (qrcodeDiv) {
                    qrcodeDiv.innerHTML = ''; // 清空舊的
                    
                    new QRCode(qrcodeDiv, {
                        text: LINE_BOT_URL,
                        width: 200,
                        height: 200,
                        colorDark: '#000000',
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.H
                    });
                }
                
                // 延遲一點點，讓 transition 生效
                setTimeout(() => {
                    codeDisplay.style.opacity = '1';
                    codeDisplay.style.transform = 'translateY(0)';
                }, 50);
                
                // 綁定碼數字動畫
                setTimeout(() => {
                    let displayCode = '------';
                    codeText.textContent = displayCode;
                    
                    // 逐字顯示綁定碼
                    let index = 0;
                    const interval = setInterval(() => {
                        if (index < code.length) {
                            displayCode = code.substring(0, index + 1) + '------'.substring(0, 6 - index - 1);
                            codeText.textContent = displayCode;
                            index++;
                        } else {
                            clearInterval(interval);
                            // 最後閃爍一下
                            codeText.style.animation = 'pulse 0.5s ease';
                        }
                    }, 100);
                }, 300);
            }
            
            // 10分鐘後自動隱藏
            setTimeout(() => {
                if (codeDisplay) {
                    codeDisplay.style.opacity = '0';
                    codeDisplay.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        codeDisplay.style.display = 'none';
                        button.textContent = originalText;
                        button.disabled = false;
                    }, 500);
                }
            }, 600000);
            
        } else {
            alert('產生綁定碼失敗: ' + (json.message || '未知錯誤'));
            button.textContent = originalText;
            button.disabled = false;
            button.style.opacity = '1';
        }
    } catch (err) {
        console.error('❌ 產生綁定碼錯誤:', err);
        alert('連線錯誤: ' + err.message);
        button.textContent = originalText;
        button.disabled = false;
        button.style.opacity = '1';
    }
}

async function unbindLine() {
    // Demo 模式禁用
    if (isDemoMode) {
        alert('Demo 模式無法使用 LINE 綁定功能');
        return;
    }
    
    if (!confirm('確定要解除 LINE 綁定嗎？')) return;
    
    console.log('🔓 解除綁定');
    
    try {
        const res = await fetch(`${API_URL}?action=line_unbind`, {
            method: 'POST'
        });
        const json = await res.json();
        
        if (json.success) {
            alert('已解除綁定');
            location.reload();
        } else {
            alert('解除失敗: ' + json.message);
        }
    } catch (err) {
        console.error('解除綁定錯誤:', err);
        alert('連線錯誤: ' + err.message);
    }
}

console.log('✅ main.js 載入完成');