<?php
session_start();

// --- Configuration & Helpers ---
define('DATA_FILE', __DIR__ . '/data.json');

function load_app_data(): array {
    if (!file_exists(DATA_FILE)) {
        $default_data = [
            'user1' => ['name' => "用户1", 'password' => null, 'given' => [], 'received' => []], // Changed default names to Chinese
            'user2' => ['name' => "用户2", 'password' => null, 'given' => [], 'received' => []], // Changed default names to Chinese
            'lastActiveUserKey' => "user1",
            'calendarDate' => (new DateTime())->format('Y-m-d')
        ];
        if (file_put_contents(DATA_FILE, json_encode($default_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            error_log("CRITICAL: Cannot write initial data file to: " . DATA_FILE);
        }
        return $default_data;
    }

    $jsonData = file_get_contents(DATA_FILE);
    if ($jsonData === false) {
        error_log("CRITICAL: Cannot read data file from: " . DATA_FILE);
        return [
            'user1' => ['name' => "用户1", 'password' => null, 'given' => [], 'received' => []],
            'user2' => ['name' => "用户2", 'password' => null, 'given' => [], 'received' => []],
            'lastActiveUserKey' => "user1",
            'calendarDate' => (new DateTime())->format('Y-m-d')
        ];
    }

    $data = json_decode($jsonData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg() . " for file " . DATA_FILE);
        $corrupt_file_path = DATA_FILE . '.corrupted.' . time();
        rename(DATA_FILE, $corrupt_file_path);
        error_log("Corrupted data file renamed to: " . $corrupt_file_path);
        return load_app_data();
    }

    $data['user1'] = $data['user1'] ?? ['name' => "用户1", 'password' => null, 'given' => [], 'received' => []];
    $data['user2'] = $data['user2'] ?? ['name' => "用户2", 'password' => null, 'given' => [], 'received' => []];
    $data['lastActiveUserKey'] = $data['lastActiveUserKey'] ?? "user1";
    $data['calendarDate'] = $data['calendarDate'] ?? (new DateTime())->format('Y-m-d');
    return $data;
}

function save_app_data(array $data): void {
    if (file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        error_log("CRITICAL: Cannot write data to file: " . DATA_FILE);
    }
}

function get_active_user_key(): ?string {
    return $_SESSION['activeUserSessionKey'] ?? null;
}

function get_partner_key(string $userKey): string {
    return ($userKey === 'user1') ? 'user2' : 'user1';
}

// --- API Request Handling (AJAX) ---
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $appData = load_app_data();
    $response = ['success' => false, 'message' => '无效的操作。'];
    $activeUserKey = get_active_user_key();

    switch ($_POST['action']) {
        case 'initialize_app':
            $response = [
                'success' => true,
                'activeUserSessionKey' => $activeUserKey,
                'appData' => $appData
            ];
            if (!$activeUserKey && isset($_SESSION['loginAttemptUserKey']) && isset($appData[$_SESSION['loginAttemptUserKey']])) {
                $response['pendingLoginAttemptUserKey'] = $_SESSION['loginAttemptUserKey'];
                 unset($_SESSION['loginAttemptUserKey']); 
            }
            // Ensure calendarDate is always part of appData sent to client
            if (!isset($appData['calendarDate'])) { 
                $appData['calendarDate'] = (new DateTime())->format('Y-m-d');
                 // $response['appData']['calendarDate'] should already be set via $appData above
            }
            // Ensure active user specific data is present if logged in
            if ($activeUserKey && isset($appData[$activeUserKey])) {
                 $response['appData']['currentUserKey'] = $activeUserKey; // For client-side convenience
            }
            break;

        case 'login':
            $userKeyToLogin = $_POST['userKey'] ?? null;
            $password = $_POST['password'] ?? '';
            $targetUserData = $appData[$userKeyToLogin] ?? null;

            if ($targetUserData && ($targetUserData['password'] === null || $targetUserData['password'] === $password)) {
                $_SESSION['activeUserSessionKey'] = $userKeyToLogin;
                unset($_SESSION['loginAttemptUserKey']);
                $appData['lastActiveUserKey'] = $userKeyToLogin;
                save_app_data($appData);
                $response = ['success' => true, 'message' => '登录成功。', 'activeUserSessionKey' => $userKeyToLogin, 'userName' => $targetUserData['name']];
            } else {
                $response = ['success' => false, 'message' => '密码错误或用户不存在。'];
            }
            break;

        case 'switch_user':
            if ($activeUserKey) {
                $partnerKey = get_partner_key($activeUserKey);
                $_SESSION['loginAttemptUserKey'] = $partnerKey; 
                unset($_SESSION['activeUserSessionKey']); 
                $response = ['success' => true, 'message' => '正在切换用户...'];
            } else {
                $_SESSION['loginAttemptUserKey'] = ($appData['lastActiveUserKey'] ?? 'user1') === 'user1' ? 'user2' : 'user1';
                $response = ['success' => true, 'message' => '准备切换用户...'];
            }
            break;

        case 'set_password':
            if ($activeUserKey && isset($appData[$activeUserKey])) {
                $newPassword = $_POST['newPassword'] ?? null;
                if ($newPassword !== null) { 
                    $appData[$activeUserKey]['password'] = ($newPassword === "") ? null : $newPassword;
                    save_app_data($appData);
                    $response = ['success' => true, 'message' => '密码已更新。'];
                } else {
                     $response = ['success' => false, 'message' => '无效的密码提交。'];
                }
            } else {
                $response = ['success' => false, 'message' => '用户未登录或用户数据错误。'];
            }
            break;

        case 'submit_evaluation':
            if ($activeUserKey && isset($appData[$activeUserKey])) {
                $score = filter_input(INPUT_POST, 'score', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
                $text = trim($_POST['evaluationText'] ?? '');
                $today = (new DateTime())->format('Y-m-d');
                $timestamp = (new DateTime())->format(DateTime::ATOM);

                if ($score && !empty($text)) {
                    $partnerKey = get_partner_key($activeUserKey);
                    if (!isset($appData[$partnerKey])) {
                        $response = ['success' => false, 'message' => '伙伴用户数据不存在。'];
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    $appData[$activeUserKey]['given'][$today] = ['score' => $score, 'text' => $text, 'timestamp' => $timestamp];
                    $appData[$partnerKey]['received'][$today] = ['score' => $score, 'text' => $text, 'submitTimestamp' => $timestamp, 'viewedTimestamp' => null];
                    
                    save_app_data($appData);
                    $response = ['success' => true, 'message' => '评价已提交。', 'appData' => $appData];
                } else {
                    $response = ['success' => false, 'message' => '分数和评价内容不能为空。'];
                }
            } else {
                $response = ['success' => false, 'message' => '用户未登录或用户数据错误。'];
            }
            break;

        case 'mark_evaluation_viewed':
            if ($activeUserKey && isset($appData[$activeUserKey]['received'])) {
                $today = (new DateTime())->format('Y-m-d');
                if (isset($appData[$activeUserKey]['received'][$today])) {
                    $appData[$activeUserKey]['received'][$today]['viewedTimestamp'] = (new DateTime())->format(DateTime::ATOM);
                    save_app_data($appData);
                    $response = ['success' => true, 'message' => '评价已标记为已查看。', 'appData' => $appData];
                } else {
                    $response = ['success' => false, 'message' => '今天没有收到评价。'];
                }
            } else {
                $response = ['success' => false, 'message' => '用户未登录或无评价数据。'];
            }
            break;
        
        case 'change_calendar_month':
             if (isset($appData['calendarDate'])) { 
                $direction = $_POST['direction'] ?? 'next';
                $currentCalendarDateStr = $appData['calendarDate'];
                try {
                    $calendarDate = new DateTime($currentCalendarDateStr);
                    if ($direction === 'prev') {
                        $calendarDate->modify('-1 month');
                    } else {
                        $calendarDate->modify('+1 month');
                    }
                    $appData['calendarDate'] = $calendarDate->format('Y-m-d');
                    save_app_data($appData);
                    $response = ['success' => true, 'newCalendarDate' => $appData['calendarDate'], 'appData' => $appData];
                } catch (Exception $e) {
                    $response = ['success' => false, 'message' => '更改日历月份出错。'];
                }
            } else {
                $response = ['success' => false, 'message' => '无法确定当前日历月份。'];
            }
            break;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>每日寄语</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@300;400;500;700&amp;display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans SC', sans-serif; scroll-behavior: smooth; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #c4b5fd; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #a78bfa; }
        .score-button.selected { background-color: #6366f1; color: white; transform: scale(1.05); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
        .calendar-day { 
            transition: all 0.3s ease; 
            aspect-ratio: 1 / 1; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: space-around; /* Adjusted for better content spacing */
            padding: 0.25rem; /* p-1 */
            font-size: 0.75rem; /* text-xs */
        }
        .calendar-day:hover { transform: translateY(-2px); box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        .animated-section { opacity: 0; transform: translateY(20px); transition: opacity 0.5s ease-out, transform 0.5s ease-out; }
        .animated-section.visible { opacity: 1; transform: translateY(0); }
        .modal { transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s ease; }
        .modal.open { opacity: 1; transform: scale(1); pointer-events: auto; visibility: visible; }
        .modal.closed { opacity: 0; transform: scale(0.95); pointer-events: none; visibility: hidden; }
        .toast { transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s ease; visibility: hidden; }
        .toast.show { opacity: 1; transform: translateY(0); visibility: visible; }
        .toast.hide { opacity: 0; transform: translateY(20px); visibility: hidden; }
        .content-hidden { display: none !important; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800">
    <div id="app-container" class="max-w-4xl mx-auto p-4 md:p-8 min-h-screen">
        <header class="mb-10 text-center">
            <h1 class="text-4xl md:text-5xl font-bold text-indigo-600 py-4">每日寄语</h1>
            <div id="userSwitchContainer" class="mt-4 flex justify-center items-center space-x-3 sm:space-x-4 content-hidden">
                <span class="text-slate-600 text-sm sm:text-base">当前用户: <span id="currentUserDisplay" class="font-semibold text-indigo-700"></span></span>
                <button id="switchUserBtn" class="px-3 py-1.5 sm:px-4 sm:py-2 bg-indigo-500 text-white text-sm sm:text-base rounded-lg shadow-md hover:bg-indigo-600 transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-opacity-75">
                    切换用户
                </button>
            </div>
        </header>

        <div id="mainContentContainer" class="content-hidden">
            <main class="space-y-8 md:space-y-10">
                <section id="userSettingsSection" class="bg-white p-6 md:p-8 rounded-xl shadow-xl animated-section">
                    <h2 class="text-2xl font-semibold text-slate-700 mb-6">用户设置: <span id="settingsForUserDisplay" class="text-indigo-600"></span></h2>
                    <button id="setPasswordBtn" class="w-full sm:w-auto px-6 py-3 bg-sky-500 text-white font-semibold rounded-lg shadow-md hover:bg-sky-600 transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-opacity-75">
                        设置/更改密码
                    </button>
                    <p class="mt-3 text-xs text-red-500">注意：如果设置密码，请勿使用重要密码。</p>
                </section>

                <section id="submitEvaluationSection" class="bg-white p-6 md:p-8 rounded-xl shadow-xl animated-section">
                    <h2 class="text-2xl font-semibold text-slate-700 mb-6">给 <span id="partnerNameDisplaySubmit" class="text-indigo-600"></span> 的今日寄语</h2>
                    <form id="evaluationForm">
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-slate-600 mb-2">为TA今天的表现打分 (1-10):</label>
                            <div id="scoreButtons" class="flex flex-wrap gap-2"></div>
                            <input type="hidden" id="scoreInput" name="score">
                        </div>
                        <div class="mb-6">
                            <label for="evaluationText" class="block text-sm font-medium text-slate-600 mb-2">评价内容:</label>
                            <textarea id="evaluationText" name="evaluationText" rows="5" class="w-full p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-colors duration-200" placeholder="写下你的评价..."></textarea>
                        </div>
                        <button type="submit" id="submitEvaluationBtn" class="w-full px-6 py-3 bg-indigo-500 text-white font-semibold rounded-lg shadow-md hover:bg-indigo-600 transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-opacity-75 disabled:opacity-50 disabled:cursor-not-allowed">
                            提交今日评价
                        </button>
                    </form>
                    <p id="submitStatus" class="mt-4 text-sm"></p>
                </section>

                <section id="viewEvaluationSection" class="bg-white p-6 md:p-8 rounded-xl shadow-xl animated-section">
                    <h2 class="text-2xl font-semibold text-slate-700 mb-6">查看来自 <span id="partnerNameDisplayView" class="text-indigo-600"></span> 的今日寄语</h2>
                    <button id="viewEvaluationBtn" class="w-full px-6 py-3 bg-emerald-500 text-white font-semibold rounded-lg shadow-md hover:bg-emerald-600 transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-opacity-75 disabled:opacity-50 disabled:cursor-not-allowed">
                        <span id="viewButtonText">查看今日寄语</span>
                    </button>
                    <div id="evaluationDisplayArea" class="mt-6 hidden p-4 bg-slate-50 rounded-lg border border-slate-200">
                        <p class="text-slate-800 text-lg"><strong>评分:</strong> <span id="receivedScore" class="text-xl font-bold text-indigo-600"></span></p>
                        <p class="text-slate-800 mt-3"><strong>评价内容:</strong></p>
                        <blockquote id="receivedText" class="mt-1 p-3 bg-slate-100 border-l-4 border-indigo-400 italic text-slate-700 rounded-r-md"></blockquote>
                        <p class="text-xs text-slate-500 mt-3"><strong>评价提交时间:</strong> <span id="receivedTimestamp"></span></p>
                    </div>
                    <p id="viewStatus" class="mt-4 text-sm"></p>
                </section>

                <section id="calendarSection" class="bg-white p-6 md:p-8 rounded-xl shadow-xl animated-section">
                    <h2 class="text-2xl font-semibold text-slate-700 mb-6">
                        互评日历 ( <span id="user1NameCalendar" class="text-indigo-500">用户1</span> &harr; <span id="user2NameCalendar" class="text-purple-500">用户2</span> )
                    </h2>
                    <div class="flex justify-between items-center mb-6">
                        <button id="prevMonthBtn" class="px-3 py-1.5 sm:px-4 sm:py-2 bg-slate-200 text-slate-700 rounded-lg shadow-sm hover:bg-slate-300 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-slate-400">&lt; 上个月</button>
                        <h3 id="calendarMonthYear" class="text-xl font-semibold text-indigo-600"></h3>
                        <button id="nextMonthBtn" class="px-3 py-1.5 sm:px-4 sm:py-2 bg-slate-200 text-slate-700 rounded-lg shadow-sm hover:bg-slate-300 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-slate-400">下个月 &gt;</button>
                    </div>
                    <div class="grid grid-cols-7 gap-1 text-center mb-2">
                        <div class="font-medium text-xs sm:text-sm text-slate-500">日</div> <div class="font-medium text-xs sm:text-sm text-slate-500">一</div> <div class="font-medium text-xs sm:text-sm text-slate-500">二</div> <div class="font-medium text-xs sm:text-sm text-slate-500">三</div> <div class="font-medium text-xs sm:text-sm text-slate-500">四</div> <div class="font-medium text-xs sm:text-sm text-slate-500">五</div> <div class="font-medium text-xs sm:text-sm text-slate-500">六</div>
                    </div>
                    <div id="calendarGrid" class="grid grid-cols-7 gap-1 sm:gap-2 text-center"></div>
                </section>
            </main>
        </div>

        <footer class="text-center py-8 mt-8">
            <p class="text-sm text-slate-500">&copy; <span id="currentYear"></span> 每日寄语.Produced by ZhuChenyu.</p>
        </footer>
    </div>

    <div id="userSelectionModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center p-4 z-50 modal closed">
        <div class="bg-white p-6 md:p-8 rounded-lg shadow-xl max-w-sm w-full">
            <h3 class="text-xl font-semibold text-slate-800 mb-6 text-center">请选择您的用户身份</h3>
            <div class="space-y-4">
                <button id="selectUser1Btn" data-userkey="user1" class="user-select-button w-full px-4 py-3 bg-indigo-500 text-white rounded-lg hover:bg-indigo-600 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    加载中...
                </button>
                <button id="selectUser2Btn" data-userkey="user2" class="user-select-button w-full px-4 py-3 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-purple-500">
                    加载中...
                </button>
            </div>
        </div>
    </div>

    <div id="loginModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center p-4 z-50 modal closed">
        <div class="bg-white p-6 md:p-8 rounded-lg shadow-xl max-w-sm w-full">
            <h3 id="loginUserDisplay" class="text-xl font-semibold text-slate-800 mb-4 text-center">用户登录</h3>
            <form id="loginForm">
                <div class="mb-4">
                    <label for="loginPasswordInput" class="block text-sm font-medium text-slate-600 mb-1">密码:</label>
                    <input type="password" id="loginPasswordInput" class="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required>
                </div>
                <button type="submit" id="loginSubmitBtn" class="w-full px-4 py-2 bg-indigo-500 text-white rounded-lg hover:bg-indigo-600 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    登录
                </button>
            </form>
            <p id="loginStatusMessage" class="mt-3 text-sm text-red-600 text-center"></p>
        </div>
    </div>

    <div id="setPasswordModal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center p-4 z-50 modal closed">
        <div class="bg-white p-6 md:p-8 rounded-lg shadow-xl max-w-sm w-full">
            <h3 id="setPasswordUserDisplay" class="text-xl font-semibold text-slate-800 mb-6 text-center">设置密码</h3>
            <form id="setPasswordForm">
                <div class="mb-4">
                    <label for="newPasswordInput" class="block text-sm font-medium text-slate-600 mb-1">新密码 (留空以移除密码):</label>
                    <input type="password" id="newPasswordInput" class="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="mb-6">
                    <label for="confirmPasswordInput" class="block text-sm font-medium text-slate-600 mb-1">确认新密码:</label>
                    <input type="password" id="confirmPasswordInput" class="w-full p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="flex flex-col sm:flex-row gap-3">
                    <button type="submit" id="savePasswordBtn" class="w-full px-4 py-2 bg-sky-500 text-white rounded-lg hover:bg-sky-600 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-sky-500">
                        保存密码
                    </button>
                    <button type="button" id="cancelSetPasswordBtn" class="w-full px-4 py-2 bg-slate-200 text-slate-700 rounded-lg hover:bg-slate-300 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-slate-400">
                        取消
                    </button>
                </div>
            </form>
            <p id="setPasswordStatusMessage" class="mt-3 text-sm text-center"></p>
        </div>
    </div>

    <div id="confirmationModal" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 z-50 modal closed">
        <div class="bg-white p-6 rounded-lg shadow-xl max-w-sm w-full">
            <h3 id="modalTitle" class="text-xl font-semibold text-slate-800 mb-4"></h3>
            <p id="modalMessage" class="text-slate-600 mb-6"></p>
            <div class="flex justify-end space-x-3">
                <button id="modalCancelBtn" class="px-4 py-2 bg-slate-200 text-slate-700 rounded-lg hover:bg-slate-300 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-slate-400">取消</button>
                <button id="modalConfirmBtn" class="px-4 py-2 bg-indigo-500 text-white rounded-lg hover:bg-indigo-600 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500">确认</button>
            </div>
        </div>
    </div>

    <div id="messageBox" class="fixed bottom-5 right-5 bg-slate-800 text-white px-6 py-3 rounded-lg shadow-xl z-50 toast hide">
        <p id="messageText"></p>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    console.log("DOM fully loaded and parsed (PHP Version with Shared Calendar).");

    // --- DOM Elements ---
    const currentUserDisplay = document.getElementById('currentUserDisplay');
    const switchUserBtn = document.getElementById('switchUserBtn');
    const partnerNameDisplaySubmit = document.getElementById('partnerNameDisplaySubmit');
    const partnerNameDisplayView = document.getElementById('partnerNameDisplayView');
    // partnerNameDisplayCalendar is removed as calendar title is now static with dynamic name spans
    const settingsForUserDisplay = document.getElementById('settingsForUserDisplay');
    const userSwitchContainer = document.getElementById('userSwitchContainer');
    const evaluationForm = document.getElementById('evaluationForm');
    const scoreButtonsContainer = document.getElementById('scoreButtons');
    const scoreInput = document.getElementById('scoreInput');
    const evaluationText = document.getElementById('evaluationText');
    const submitEvaluationBtn = document.getElementById('submitEvaluationBtn');
    const submitStatus = document.getElementById('submitStatus');
    const viewEvaluationBtn = document.getElementById('viewEvaluationBtn');
    const viewButtonText = document.getElementById('viewButtonText');
    const evaluationDisplayArea = document.getElementById('evaluationDisplayArea');
    const receivedScore = document.getElementById('receivedScore');
    const receivedText = document.getElementById('receivedText');
    const receivedTimestamp = document.getElementById('receivedTimestamp');
    const viewStatus = document.getElementById('viewStatus');
    const calendarMonthYear = document.getElementById('calendarMonthYear');
    const prevMonthBtn = document.getElementById('prevMonthBtn');
    const nextMonthBtn = document.getElementById('nextMonthBtn');
    const calendarGrid = document.getElementById('calendarGrid');
    const confirmationModal = document.getElementById('confirmationModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalMessage = document.getElementById('modalMessage');
    const modalConfirmBtn = document.getElementById('modalConfirmBtn');
    const modalCancelBtn = document.getElementById('modalCancelBtn');
    const loginModal = document.getElementById('loginModal');
    const loginUserDisplay = document.getElementById('loginUserDisplay');
    const loginForm = document.getElementById('loginForm');
    const loginPasswordInput = document.getElementById('loginPasswordInput');
    const loginStatusMessage = document.getElementById('loginStatusMessage');
    const setPasswordModal = document.getElementById('setPasswordModal');
    const setPasswordUserDisplay = document.getElementById('setPasswordUserDisplay');
    const setPasswordForm = document.getElementById('setPasswordForm');
    const newPasswordInput = document.getElementById('newPasswordInput');
    const confirmPasswordInput = document.getElementById('confirmPasswordInput');
    const cancelSetPasswordBtn = document.getElementById('cancelSetPasswordBtn');
    const setPasswordStatusMessage = document.getElementById('setPasswordStatusMessage');
    const setPasswordBtn = document.getElementById('setPasswordBtn');
    const messageBox = document.getElementById('messageBox');
    const messageText = document.getElementById('messageText');
    const mainContentContainer = document.getElementById('mainContentContainer');
    const currentYearEl = document.getElementById('currentYear');
    if (currentYearEl) currentYearEl.textContent = new Date().getFullYear();

    const userSelectionModal = document.getElementById('userSelectionModal');
    const selectUser1Btn = document.getElementById('selectUser1Btn');
    const selectUser2Btn = document.getElementById('selectUser2Btn');

    // --- State Variables ---
    let appData = {}; 
    let activeUserSessionKey = null; 
    let loginAttemptUserKey = null; 
    let generalModalConfirmCallback = null;
    
    // --- Utility Functions ---
    const getTodayDateString = () => new Date().toISOString().split('T')[0];
    const formatTimestamp = (isoString) => {
        if (!isoString) return 'N/A';
        try {
            const date = new Date(isoString);
            if (isNaN(date.getTime())) return '无效日期';
            return date.toLocaleString('zh-CN', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        } catch (e) { console.error("Error formatting timestamp:", e, "Input:", isoString); return '日期错误'; }
    };

    async function apiCall(action, data = {}) { /* ... same as before ... */ 
        const formData = new FormData();
        formData.append('action', action);
        for (const key in data) {
            formData.append(key, data[key]);
        }
        try {
            const response = await fetch('index.php', { method: 'POST', body: formData });
            if (!response.ok) { throw new Error(`HTTP 错误! 状态: ${response.status}`); }
            return await response.json();
        } catch (error) {
            console.error('API调用错误:', action, error);
            showToast(`网络或服务器错误: ${error.message}`, 'error');
            return { success: false, message: `API 错误: ${error.message}` };
        }
    }

    const showMainContent = (show) => { /* ... same as before ... */ 
        if (show) {
            mainContentContainer.classList.remove('content-hidden');
            userSwitchContainer.classList.remove('content-hidden');
            animateSections();
        } else {
            mainContentContainer.classList.add('content-hidden');
            userSwitchContainer.classList.add('content-hidden');
        }
    };
    const openModal = (modalElement) => { /* ... same as before ... */ 
        if(modalElement) { modalElement.classList.remove('closed'); modalElement.classList.add('open'); } 
        else { console.error("Attempted to open a null modal element."); }
    };
    const closeModal = (modalElement) => { /* ... same as before ... */ 
        if(modalElement) { modalElement.classList.remove('open'); modalElement.classList.add('closed'); } 
        else { console.error("Attempted to close a null modal element."); }
    };

    const showLoginModal = (userKeyToLogin) => { /* ... same as before ... */ 
        loginAttemptUserKey = userKeyToLogin;
        if (!appData[userKeyToLogin]) {
            showToast("无法加载用户信息，请刷新", "error");
            if(userSelectionModal) openModal(userSelectionModal);
            return;
        }
        loginUserDisplay.textContent = `${appData[userKeyToLogin].name} 登录`;
        loginPasswordInput.value = '';
        loginStatusMessage.textContent = '';
        openModal(loginModal);
        loginPasswordInput.focus();
    };

    loginForm.addEventListener('submit', async (e) => { /* ... same as before ... */ 
        e.preventDefault();
        const enteredPassword = loginPasswordInput.value;
        const result = await apiCall('login', { userKey: loginAttemptUserKey, password: enteredPassword });
        if (result.success && result.activeUserSessionKey) {
            await initializeAppState({ type: 'successfulLogin', userName: result.userName });
            closeModal(loginModal);
        } else {
            loginStatusMessage.textContent = result.message || '密码错误，请重试。';
            showToast(result.message || '密码错误！', 'error');
        }
    });

    setPasswordBtn.addEventListener('click', () => { /* ... same as before ... */ 
        if (!activeUserSessionKey || !appData[activeUserSessionKey]) { showToast('请先登录以设置密码。', 'error'); return; }
        setPasswordUserDisplay.textContent = `为 ${appData[activeUserSessionKey].name} 设置密码`;
        newPasswordInput.value = ''; confirmPasswordInput.value = ''; setPasswordStatusMessage.textContent = '';
        openModal(setPasswordModal); newPasswordInput.focus();
    });
    setPasswordForm.addEventListener('submit', async (e) => { /* ... same as before ... */ 
        e.preventDefault();
        const newPass = newPasswordInput.value; const confirmPass = confirmPasswordInput.value;
        if (newPass !== confirmPass) {
            setPasswordStatusMessage.textContent = '两次输入的密码不匹配。';
            setPasswordStatusMessage.className = 'mt-3 text-sm text-center text-red-600'; return;
        }
        const result = await apiCall('set_password', { newPassword: newPass });
        if (result.success) {
            setPasswordStatusMessage.textContent = '密码已成功更新！';
            setPasswordStatusMessage.className = 'mt-3 text-sm text-center text-green-600';
            showToast('密码已更新！', 'success');
            if(appData[activeUserSessionKey]) appData[activeUserSessionKey].password = newPass === "" ? null : newPass;
            setTimeout(() => closeModal(setPasswordModal), 1500);
        } else {
            setPasswordStatusMessage.textContent = result.message || '密码更新失败。';
            setPasswordStatusMessage.className = 'mt-3 text-sm text-center text-red-600';
            showToast(result.message || '密码更新失败！', 'error');
        }
    });
    cancelSetPasswordBtn.addEventListener('click', () => closeModal(setPasswordModal));

    // --- UI Update Functions ---
    const updateCurrentUserUI = () => {
        if (!activeUserSessionKey || !appData[activeUserSessionKey]) {
            console.log("updateCurrentUserUI: No active user session or data. Hiding main content.");
            showMainContent(false);
            if (userSelectionModal && !userSelectionModal.classList.contains('open') && loginModal && !loginModal.classList.contains('open')) {
                 console.log("Fallback to user selection from updateCurrentUserUI");
                 openModal(userSelectionModal);
            }
            return;
        }
        
        console.log("Updating current user UI for (active session):", activeUserSessionKey);
        
        const currentUserData = appData[activeUserSessionKey];
        const partnerKey = activeUserSessionKey === 'user1' ? 'user2' : 'user1';
        const partnerData = appData[partnerKey];

        if (!currentUserData || !partnerData) {
            console.error("CRITICAL: Current user or partner data is undefined.", activeUserSessionKey, appData);
            showToast("用户数据错误，请刷新页面。", "error");
            showMainContent(false);
            return;
        }
        
        showMainContent(true); 

        currentUserDisplay.textContent = currentUserData.name;
        settingsForUserDisplay.textContent = currentUserData.name;
        partnerNameDisplaySubmit.textContent = partnerData.name;
        partnerNameDisplayView.textContent = partnerData.name;
        // partnerNameDisplayCalendar is no longer used here; renderCalendar handles its own title elements

        evaluationForm.reset();
        scoreInput.value = '';
        document.querySelectorAll('.score-button.selected').forEach(btn => btn.classList.remove('selected'));
        submitStatus.textContent = '';
        viewStatus.textContent = '';
        evaluationDisplayArea.classList.add('hidden');

        checkTodayEvaluationStatus();
        renderCalendar(); // This will also update the calendar title with specific user names
        console.log("Current user UI update complete for active session:", activeUserSessionKey);
    };

    let scoreButtonsCreated = false;
    const createScoreButtonsOnce = () => { /* ... same as before (ensure it has the full logic) ... */ 
        if (scoreButtonsCreated) return;
        if (!scoreButtonsContainer) { console.error("DOM_MISSING: scoreButtonsContainer."); return; }
        scoreButtonsContainer.innerHTML = '';
        for (let i = 1; i <= 10; i++) {
            const button = document.createElement('button');
            button.type = 'button'; button.textContent = i; button.dataset.score = i;
            button.className = 'score-button w-10 h-10 sm:w-12 sm:h-12 flex items-center justify-center border border-slate-300 rounded-lg text-slate-700 hover:bg-indigo-100 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-300';
            button.addEventListener('click', () => {
                if (!submitEvaluationBtn || submitEvaluationBtn.disabled) return;
                document.querySelectorAll('.score-button.selected').forEach(btn => btn.classList.remove('selected'));
                button.classList.add('selected');
                if(scoreInput) scoreInput.value = i;
            });
            scoreButtonsContainer.appendChild(button);
        }
        scoreButtonsCreated = true;
        console.log("Score buttons created ONCE.");
    };

    const checkTodayEvaluationStatus = () => { /* ... same as before ... */ 
        if (!activeUserSessionKey || !appData[activeUserSessionKey]) return;
        const today = getTodayDateString();
        const currentUserData = appData[activeUserSessionKey];
        const partnerKey = activeUserSessionKey === 'user1' ? 'user2' : 'user1';
        const partnerData = appData[partnerKey];
        if (!currentUserData || !partnerData) { console.error("User or partner data missing for eval status check."); return; }
        currentUserData.given = currentUserData.given || {};
        currentUserData.received = currentUserData.received || {};
        const currentUserGivenToday = currentUserData.given[today];
        const partnerEvaluationForCurrentUser = currentUserData.received[today];
        if (currentUserGivenToday) {
            submitEvaluationBtn.disabled = true; submitEvaluationBtn.textContent = '今日已评价';
            evaluationText.value = currentUserGivenToday.text; evaluationText.disabled = true;
            if (currentUserGivenToday.score) { const scoreBtn = scoreButtonsContainer.querySelector(`[data-score="${currentUserGivenToday.score}"]`); if(scoreBtn) scoreBtn.classList.add('selected'); }
            scoreButtonsContainer.querySelectorAll('button').forEach(btn => btn.disabled = true);
            submitStatus.textContent = `你已于 ${formatTimestamp(currentUserGivenToday.timestamp)} 提交评价。`;
            submitStatus.className = 'mt-4 text-sm text-green-600';
        } else {
            submitEvaluationBtn.disabled = false; submitEvaluationBtn.textContent = '提交今日评价';
            evaluationText.disabled = false; evaluationText.value = '';
            document.querySelectorAll('.score-button.selected').forEach(btn => btn.classList.remove('selected'));
            scoreButtonsContainer.querySelectorAll('button').forEach(btn => btn.disabled = false);
            if(scoreInput) scoreInput.value = ''; submitStatus.textContent = '';
        }
        if (!partnerEvaluationForCurrentUser) {
            viewEvaluationBtn.disabled = true; viewButtonText.textContent = `等待 ${partnerData.name} 提交今日评价`;
            viewStatus.textContent = `${partnerData.name} 尚未提交今天的评价。`; viewStatus.className = 'mt-4 text-sm text-slate-500';
            evaluationDisplayArea.classList.add('hidden');
        } else {
            if (partnerEvaluationForCurrentUser.viewedTimestamp) {
                viewEvaluationBtn.disabled = true; viewButtonText.textContent = '今日评价已查看';
                displayReceivedEvaluation(partnerEvaluationForCurrentUser);
                viewStatus.textContent = `你已于 ${formatTimestamp(partnerEvaluationForCurrentUser.viewedTimestamp)} 查看了此评价。`; viewStatus.className = 'mt-4 text-sm text-green-600';
            } else {
                viewEvaluationBtn.disabled = false; viewButtonText.textContent = `查看来自 ${partnerData.name} 的今日寄语`;
                viewStatus.textContent = `你收到了来自 ${partnerData.name} 的今日评价！`; viewStatus.className = 'mt-4 text-sm text-emerald-600';
                evaluationDisplayArea.classList.add('hidden');
            }
        }
    };
    const displayReceivedEvaluation = (evaluation) => { /* ... same as before ... */ 
        if (!receivedScore || !receivedText || !receivedTimestamp || !evaluationDisplayArea) { return; }
        receivedScore.textContent = evaluation.score; receivedText.textContent = evaluation.text;
        receivedTimestamp.textContent = formatTimestamp(evaluation.submitTimestamp);
        evaluationDisplayArea.classList.remove('hidden');
    };

    // --- Event Handlers ---
    switchUserBtn.addEventListener('click', async () => { /* ... same as before ... */ 
        const result = await apiCall('switch_user');
        if (result.success) {
            activeUserSessionKey = null; showMainContent(false); showToast(`准备切换用户...`, 'info');
            await initializeAppState(); 
        } else { showToast(result.message || '切换用户失败。', 'error'); }
    });
    evaluationForm.addEventListener('submit', async (e) => { /* ... same as before ... */ 
        e.preventDefault();
        if (!activeUserSessionKey) { showToast('错误：无活动用户会话。', 'error'); return; }
        const score = parseInt(scoreInput.value); const text = evaluationText.value.trim();
        if (!score || score < 1 || score > 10) { showToast('请选择1-10之间的分数。', 'error'); return; }
        if (!text) { showToast('评价内容不能为空。', 'error'); return; }
        const result = await apiCall('submit_evaluation', { score, evaluationText: text });
        if (result.success) {
            showToast('评价已成功提交！', 'success');
            if(result.appData) appData = result.appData; updateCurrentUserUI();
        } else { showToast(result.message || '评价提交失败。', 'error'); }
    });
    viewEvaluationBtn.addEventListener('click', () => { /* ... same as before ... */ 
        if (!activeUserSessionKey || !appData[activeUserSessionKey]) { showToast('错误：无活动用户会话。', 'error'); return; }
        const today = getTodayDateString(); const partnerKey = activeUserSessionKey === 'user1' ? 'user2' : 'user1';
        const partnerData = appData[partnerKey]; if (!partnerData) { return; }
        const evaluationToView = appData[activeUserSessionKey].received ? appData[activeUserSessionKey].received[today] : null;
        if (evaluationToView && !evaluationToView.viewedTimestamp) {
            generalModalConfirmCallback = async () => {
                const result = await apiCall('mark_evaluation_viewed');
                if (result.success) {
                    if(result.appData) appData = result.appData; 
                    const updatedEvaluation = appData[activeUserSessionKey].received[today];
                    displayReceivedEvaluation(updatedEvaluation); checkTodayEvaluationStatus(); 
                    showToast('评价已显示。', 'info');
                } else { showToast(result.message || '标记查看失败。', 'error'); }
            };
            modalTitle.textContent = '确认查看评价';
            modalMessage.textContent = `你确定要查看来自 ${partnerData.name} 的今日评价吗？查看后将无法再次通过此按钮查看。`;
            openModal(confirmationModal);
        } else if (evaluationToView && evaluationToView.viewedTimestamp) { showToast('你今天已经查看过此评价了。', 'info');
        } else { showToast(`${partnerData.name} 今天还没有给你评价哦。`, 'info'); }
    });

    // --- Calendar Functions ---
    const getScoreColor = (score) => { /* ... same as before ... */ 
        if (score === null || score === undefined) return 'bg-slate-100 text-slate-400';
        if (score >= 9) return 'bg-emerald-500 text-white'; if (score >= 7) return 'bg-lime-500 text-white';
        if (score >= 5) return 'bg-yellow-400 text-slate-800'; if (score >= 3) return 'bg-orange-400 text-slate-800';
        return 'bg-red-500 text-white';
    };

    const renderCalendar = () => {
        if (!appData.calendarDate) {
             console.warn("Calendar date not available in appData for rendering calendar. Using today.");
             appData.calendarDate = new Date().toISOString().split('T')[0];
        }
        if (!calendarGrid || !calendarMonthYear) { console.error("DOM_MISSING: calendarGrid/MonthYear."); return; }
        
        calendarGrid.innerHTML = '';
        const calendarDateObj = new Date(appData.calendarDate); 

        const year = calendarDateObj.getFullYear();
        const month = calendarDateObj.getMonth(); 
        calendarMonthYear.textContent = `${year}年 ${month + 1}月`;
        
        const firstDayOfMonth = new Date(year, month, 1).getDay(); 
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        const user1Data = appData.user1;
        const user2Data = appData.user2;
        if (!user1Data || !user2Data) {
            console.error("User data for calendar is missing.");
            calendarGrid.innerHTML = '<td>用户数据未找到</td>';
            return;
        }
        const user1GivenData = user1Data.given || {};
        const user2GivenData = user2Data.given || {};

        const user1NameCalendarEl = document.getElementById('user1NameCalendar');
        const user2NameCalendarEl = document.getElementById('user2NameCalendar');
        if (user1NameCalendarEl) user1NameCalendarEl.textContent = user1Data.name;
        if (user2NameCalendarEl) user2NameCalendarEl.textContent = user2Data.name;
        
        for (let i = 0; i < firstDayOfMonth; i++) {
            const emptyCell = document.createElement('div');
            emptyCell.className = 'calendar-day bg-slate-50 rounded-md'; // Tailwind class for styling
            calendarGrid.appendChild(emptyCell);
        }
        
        const todayDateString = getTodayDateString();
        
        for (let day = 1; day <= daysInMonth; day++) {
            const dayCell = document.createElement('div');
            const dateString = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

            const evalUser1Gave = user1GivenData[dateString];
            const evalUser2Gave = user2GivenData[dateString];

            let scoreForColoring = null;
            if (activeUserSessionKey === 'user1' && evalUser1Gave) {
                scoreForColoring = evalUser1Gave.score;
            } else if (activeUserSessionKey === 'user2' && evalUser2Gave) {
                scoreForColoring = evalUser2Gave.score;
            }
            // Default cell style, specific color applied by getScoreColor
            dayCell.className = `calendar-day rounded-md shadow-sm cursor-default ${getScoreColor(scoreForColoring)}`; 

            const dayNumberSpan = document.createElement('span');
            dayNumberSpan.textContent = day;
            dayNumberSpan.className = 'font-semibold text-sm'; // Make day number a bit larger
            dayCell.appendChild(dayNumberSpan);

            let tooltipText = `${dateString}:\n`;
            let hasScores = false;

            const user1ShortName = user1Data.name.substring(0, 1) + "1";
            const user2ShortName = user2Data.name.substring(0, 1) + "2";

            if (evalUser1Gave) {
                const score1Span = document.createElement('span');
                score1Span.textContent = `${user1ShortName}: ★${evalUser1Gave.score}`;
                score1Span.className = 'block text-[0.65rem] leading-tight'; // Smaller text for scores
                dayCell.appendChild(score1Span);
                tooltipText += `${user1Data.name} → ${user2Data.name}: ${evalUser1Gave.score} (${evalUser1Gave.text.substring(0,20)}...)\n`;
                hasScores = true;
            } else { // Add a placeholder to maintain layout if one user scored and other didn't
                 const placeholderSpan = document.createElement('span');
                 placeholderSpan.innerHTML = '&nbsp;'; // Non-breaking space
                 placeholderSpan.className = 'block text-[0.65rem] leading-tight';
                 dayCell.appendChild(placeholderSpan);
            }

            if (evalUser2Gave) {
                const score2Span = document.createElement('span');
                score2Span.textContent = `${user2ShortName}: ★${evalUser2Gave.score}`;
                score2Span.className = 'block text-[0.65rem] leading-tight';
                dayCell.appendChild(score2Span);
                tooltipText += `${user2Data.name} → ${user1Data.name}: ${evalUser2Gave.score} (${evalUser2Gave.text.substring(0,20)}...)\n`;
                hasScores = true;
            } else {
                 const placeholderSpan = document.createElement('span');
                 placeholderSpan.innerHTML = '&nbsp;';
                 placeholderSpan.className = 'block text-[0.65rem] leading-tight';
                 dayCell.appendChild(placeholderSpan);
            }

            if (!hasScores) {
                tooltipText += '当日无评分';
            }
            dayCell.title = tooltipText.trim();

            if (dateString === todayDateString) {
                dayCell.classList.add('ring-2', 'ring-indigo-500');
                dayNumberSpan.classList.add('text-indigo-700');
            }
            calendarGrid.appendChild(dayCell);
        }
    };

    prevMonthBtn.addEventListener('click', async () => { /* ... same as before ... */ 
        const result = await apiCall('change_calendar_month', { direction: 'prev' });
        if (result.success && result.appData) { appData = result.appData; renderCalendar(); } 
        else { showToast(result.message || "无法更改月份", "error"); }
    });
    nextMonthBtn.addEventListener('click', async () => { /* ... same as before ... */ 
        const result = await apiCall('change_calendar_month', { direction: 'next' });
        if (result.success && result.appData) { appData = result.appData; renderCalendar(); } 
        else { showToast(result.message || "无法更改月份", "error"); }
    });

    modalConfirmBtn.addEventListener('click', () => { /* ... same as before ... */ 
        if (generalModalConfirmCallback) { generalModalConfirmCallback(); }
        closeModal(confirmationModal); generalModalConfirmCallback = null;
    });
    modalCancelBtn.addEventListener('click', () => { /* ... same as before ... */ 
        closeModal(confirmationModal); generalModalConfirmCallback = null;
    });
    confirmationModal.addEventListener('click', (e) => { /* ... same as before ... */ 
        if (e.target === confirmationModal) { if (confirmationModal.classList.contains('open')) { closeModal(confirmationModal); generalModalConfirmCallback = null; }}
    });
    
    document.querySelectorAll('.user-select-button').forEach(button => { /* ... same as before ... */ 
        button.addEventListener('click', async () => {
            const selectedUserKey = button.dataset.userkey; loginAttemptUserKey = selectedUserKey;
            if(userSelectionModal) closeModal(userSelectionModal);
            if (appData[loginAttemptUserKey] && appData[loginAttemptUserKey].password === null) {
                showToast(`以 ${appData[loginAttemptUserKey].name} 身份登录中...`, 'info');
                const loginResult = await apiCall('login', { userKey: loginAttemptUserKey, password: '' });
                if (loginResult.success && loginResult.activeUserSessionKey) {
                    await initializeAppState({ type: 'userSelectedDirectLogin' });
                } else {
                    showToast(loginResult.message || `作为 ${appData[loginAttemptUserKey].name} 登录失败`, 'error');
                    if(userSelectionModal) openModal(userSelectionModal); 
                }
            } else if (appData[loginAttemptUserKey]) { showLoginModal(loginAttemptUserKey);
            } else {
                showToast(`用户 ${loginAttemptUserKey} 数据未找到!`, 'error');
                if(userSelectionModal) openModal(userSelectionModal);
            }
        });
    });

    let toastTimeout;
    const showToast = (message, type = 'info') => { /* ... same as before ... */ 
        if (!messageBox || !messageText) { console.error("DOM_MISSING: Toast elements."); return; }
        clearTimeout(toastTimeout); messageText.textContent = message;
        messageBox.className = 'fixed bottom-5 right-5 text-white px-6 py-3 rounded-lg shadow-xl z-50 toast hide';
        switch (type) {
            case 'success': messageBox.classList.add('bg-green-600'); break;
            case 'error': messageBox.classList.add('bg-red-600'); break;
            case 'info': default: messageBox.classList.add('bg-blue-600'); break;
        }
        messageBox.classList.remove('hide'); messageBox.classList.add('show');
        toastTimeout = setTimeout(() => { messageBox.classList.remove('show'); messageBox.classList.add('hide'); }, 3000);
    };

    const animateSections = () => { /* ... same as before ... */ 
        const sections = document.querySelectorAll('.animated-section');
        sections.forEach((section, index) => { setTimeout(() => { section.classList.add('visible'); }, index * 150); });
    };
    
    // --- Initialization ---
    async function initializeAppState(loginContext = { type: 'initialLoad' }) {
        console.log("App initializing... Context:", loginContext.type);
        const result = await apiCall('initialize_app');

        if (result.success && result.appData) {
            appData = result.appData;
            activeUserSessionKey = result.activeUserSessionKey; 

            if (appData.user1 && selectUser1Btn) selectUser1Btn.textContent = `我是 ${appData.user1.name}`;
            if (appData.user2 && selectUser2Btn) selectUser2Btn.textContent = `我是 ${appData.user2.name}`;

            createScoreButtonsOnce();

            if (activeUserSessionKey && appData[activeUserSessionKey]) {
                const currentUserName = appData[activeUserSessionKey].name;
                if (loginContext.type === 'successfulLogin') { 
                    showToast(`欢迎回来, ${loginContext.userName}!`, 'success');
                } else if (loginContext.type === 'userSelectedDirectLogin') { 
                     showToast(`欢迎, ${currentUserName}! (自动登录)`, 'success');
                } else if (loginContext.type === 'switchedUserDirectLogin') { 
                     showToast(`已切换到 ${currentUserName} 并自动登录!`, 'success');
                } else if (loginContext.type === 'initialLoad' || loginContext.type === 'sessionResume') { 
                     showToast(`欢迎, ${currentUserName}! (会话已恢复)`, 'info');
                }
                updateCurrentUserUI();
            } else {
                showMainContent(false); 
                const pendingUserKey = result.pendingLoginAttemptUserKey;

                if (pendingUserKey && appData[pendingUserKey]) {
                    console.log("Pending login attempt for (after switch):", pendingUserKey);
                    loginAttemptUserKey = pendingUserKey;
                    if (appData[loginAttemptUserKey].password === null) {
                        showToast(`正为 ${appData[loginAttemptUserKey].name} 自动登录...`, 'info');
                        const loginResult = await apiCall('login', { userKey: loginAttemptUserKey, password: '' });
                        if (loginResult.success && loginResult.activeUserSessionKey) {
                            await initializeAppState({ type: 'switchedUserDirectLogin' });
                        } else {
                            showToast(loginResult.message || '自动登录失败', 'error');
                            if(userSelectionModal) openModal(userSelectionModal); 
                        }
                    } else {
                        showLoginModal(loginAttemptUserKey);
                    }
                } else {
                    console.log("No active session, opening user selection modal.");
                    if(userSelectionModal) openModal(userSelectionModal); else console.error("User selection modal not found!");
                }
            }
        } else {
            console.error("Failed to initialize app state from server:", result ? result.message : "Unknown error during init");
            showToast(result ? result.message : "无法从服务器加载数据。", "error");
            showMainContent(false);
        }
        console.log("App initialization sequence complete.");
    }

    initializeAppState();
});
</script>
</body>
</html>