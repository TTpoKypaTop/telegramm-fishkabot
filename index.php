<?php
// Fishkabot by TTpoKypaTop
// Telegram - @omniconn

$config = [
    'bot_token' => 'ENTER-HERE-YOUR-BOT-TOKEN',
    'access_code' => 'ENTER-HERE-ANY-PSWD-YOU-WANT',
    'admin_username' => 'ENTER-HERE-YOUR-ACCOUNT-TG-ID',
    'db' => [
        'host' => 'localhost',
        'user' => 'ENTER-DB-USER',
        'pass' => 'ENTER-DB-PASS',
        'name' => 'ENTER-DB-NAME'
    ],
    'inspectors' => [
        '1' => 'Inspector 01',
        '2' => 'Inspector 02',
        '3' => 'Inspector 03',
        '4' => 'Inspector 04',
        '5' => 'Inspector 05',
        '6' => 'Inspector 06'
    ]
];

date_default_timezone_set('Europe/Moscow');

try {
    $db = new mysqli(
        $config['db']['host'],
        $config['db']['user'],
        $config['db']['pass'],
        $config['db']['name']
    );

    if ($db->connect_error) {
        throw new Exception("MySQL connection failed: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
} catch (Exception $e) {
    file_put_contents('error.log', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
    die("Database connection error");
}

// Main part
$input = json_decode(file_get_contents('php://input'), true);
if ($input) {
    try {
        // Logging requests
        file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Input: " . json_encode($input) . "\n", FILE_APPEND);
        
        if (isset($input['message']['text']) && strtolower(trim($input['message']['text'])) === '/start') {
            handleStartCommand($input['message']);
        }
        
        elseif (isset($input['message']['text']) && strtolower(trim($input['message']['text'])) === '/fishka') {
            handleFiskaCommand($input['message']);
        }
        elseif (isset($input['message']['text']) && strtolower(trim($input['message']['text'])) === '/cancel_fishka') {
            handleCancelFishkaCommand($input['message']);
        }
      
        elseif (isset($input['message']['text']) && !isset($input['message']['entities'])) {
            handleTextMessage($input['message']);
        }
        
        elseif (isset($input['callback_query'])) {
            handleCallbackQuery($input['callback_query']);
        }
    } catch (Exception $e) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n", FILE_APPEND);
        
        if (isset($input['message']['chat']['id'])) {
            sendMessage([
                'chat_id' => $input['message']['chat']['id'],
                'text' => "⚠️ Произошла ошибка при обработке вашего запроса. Пожалуйста, попробуйте позже."
            ]);
        }
    }
}

function handleStartCommand($message) {
    global $db, $config;
    
    $user = $message['from'];
    $userId = $user['id'];
    $username = $user['username'] ?? '';
    $firstName = $user['first_name'] ?? '';
    $lastName = $user['last_name'] ?? '';
    $chatId = $message['chat']['id'];
    
    try {
        $stmt = $db->prepare("SELECT user_id, access_granted FROM bot_users WHERE user_id = ?");
        if (!$stmt) throw new Exception("Prepare failed: " . $db->error);
        
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
        
        $stmt->store_result();
        $stmt->bind_result($dbUserId, $accessGranted);
        $stmt->fetch();
        
        if ($stmt->num_rows === 0 || !$accessGranted) {
            if ($stmt->num_rows === 0) {
                $stmt = $db->prepare("INSERT INTO bot_users (user_id, username, first_name, last_name, access_granted) VALUES (?, ?, ?, ?, 0)");
                if (!$stmt) throw new Exception("Prepare failed: " . $db->error);
                
                $stmt->bind_param("isss", $userId, $username, $firstName, $lastName);
                if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
            }
            
            sendMessage([
                'chat_id' => $chatId,
                'text' => "🔒 Для доступа к боту введите код доступа:"
            ]);
            return;
        }
        
        // Отправляем приветствие для подтвержденных пользователей
        sendMessage([
            'chat_id' => $chatId,
            'text' => "👋 Привет! Я бот для централизации объявлений.\n\n" .
                     "Когда кто-то кинет информационное уведомление через команду /fishka, " .
                     "ты получишь уведомление в этот чат. \n\n" . 
                     "Уважаемые друзья! Это действующий бот, относитесь ответственно!\n\n" .
                     "Доступные команды:\n" .
                     "/fishka - подать уведомление\n" .
                     "/cancel_fishka - отменить последнее уведомление\n\n" .
                     "Разработчик - Omnicon"
        ]);
    } catch (Exception $e) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - handleStartCommand error: " . $e->getMessage() . "\n", FILE_APPEND);
        sendMessage([
            'chat_id' => $chatId,
            'text' => "⚠️ Произошла ошибка. Пожалуйста, попробуйте позже."
        ]);
    }
}

function handleTextMessage($message) {
    global $db, $config;
    
    $user = $message['from'];
    $userId = $user['id'];
    $chatId = $message['chat']['id'];
    $text = trim($message['text']);
    $username = strtolower($user['username'] ?? '');
    
    try {
        if ($username === strtolower($config['admin_username'])) {
            $result = $db->query("SELECT user_id FROM bot_users WHERE access_granted = 1");
            if (!$result) throw new Exception("Query failed: " . $db->error);
            
            $recipients = [];
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row['user_id'];
            }
            
            $announcementText = "📢 Объявление от @" . htmlspecialchars($username) . ":\n\n" . htmlspecialchars($text);
            
            foreach ($recipients as $recipientId) {
                sendMessage([
                    'chat_id' => $recipientId,
                    'text' => $announcementText,
                    'parse_mode' => 'HTML'
                ]);
            }
            
            sendMessage([
                'chat_id' => $chatId,
                'text' => "✅ Объявление отправлено " . count($recipients) . " пользователям."
            ]);
            
            return;
        }
        
        $stmt = $db->prepare("SELECT access_granted FROM bot_users WHERE user_id = ?");
        if (!$stmt) throw new Exception("Prepare failed: " . $db->error);
        
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
        
        $stmt->store_result();
        $stmt->bind_result($accessGranted);
        $stmt->fetch();
        
        if ($stmt->num_rows > 0 && !$accessGranted) {
            if ($text === $config['access_code']) {
        
                $stmt = $db->prepare("UPDATE bot_users SET access_granted = 1 WHERE user_id = ?");
                if (!$stmt) throw new Exception("Prepare failed: " . $db->error);
                
                $stmt->bind_param("i", $userId);
                if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
                
                sendMessage([
                    'chat_id' => $chatId,
                    'text' => "✅ Доступ разрешен!\n\nТеперь вы можете использовать бота. Введите /start для просмотра доступных команд."
                ]);
            } else {
                sendMessage([
                    'chat_id' => $chatId,
                    'text' => "❌ Неверный код доступа. Попробуйте еще раз."
                ]);
            }
        }
    } catch (Exception $e) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - handleTextMessage error: " . $e->getMessage() . "\n", FILE_APPEND);
        sendMessage([
            'chat_id' => $chatId,
            'text' => "⚠️ Произошла ошибка. Пожалуйста, попробуйте позже."
        ]);
    }
}

function handleFiskaCommand($message) {
    global $config, $db;
    
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    
    try {
        $stmt = $db->prepare("SELECT access_granted FROM bot_users WHERE user_id = ?");
        if (!$stmt) throw new Exception("Prepare failed: " . $db->error);
        
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
        
        $stmt->store_result();
        $stmt->bind_result($accessGranted);
        $stmt->fetch();
        
        if ($stmt->num_rows === 0 || !$accessGranted) {
            sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ У вас нет доступа к этой команде. Введите код доступа."
            ]);
            return;
        }
        
        $keyboard = ['inline_keyboard' => []];
        
        foreach ($config['inspectors'] as $id => $name) {
            $keyboard['inline_keyboard'][] = [
                ['text' => $name, 'callback_data' => 'inspector_' . $id]
            ];
        }
        
        $keyboard['inline_keyboard'][] = [
            ['text' => '❌ Отмена', 'callback_data' => 'cancel_fishka']
        ];
        
        sendMessage([
            'chat_id' => $chatId,
            'text' => 'Выберите проверяющего, которого вы обнаружили:',
            'reply_markup' => json_encode($keyboard)
        ]);
    } catch (Exception $e) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - handleFiskaCommand error: " . $e->getMessage() . "\n", FILE_APPEND);
        sendMessage([
            'chat_id' => $chatId,
            'text' => "⚠️ Произошла ошибка. Пожалуйста, попробуйте позже."
        ]);
    }
}

function handleCancelFishkaCommand($message) {
    global $db, $config;
    
    $user = $message['from'];
    $userId = $user['id'];
    $chatId = $message['chat']['id'];
    $username = $user['username'] ?? '';
    $firstName = $user['first_name'] ?? '';
    
    try {

        $stmt = $db->prepare("SELECT access_granted FROM bot_users WHERE user_id = ?");
        if (!$stmt) throw new Exception("Prepare failed: " . $db->error);
        
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
        
        $stmt->store_result();
        $stmt->bind_result($accessGranted);
        $stmt->fetch();
        
        if ($stmt->num_rows === 0 || !$accessGranted) {
            sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ У вас нет доступа к этой команде. Введите код доступа."
            ]);
            return;
        }
        
        $result = $db->query("SELECT user_id FROM bot_users WHERE access_granted = 1");
        if (!$result) throw new Exception("Query failed: " . $db->error);
        
        $recipients = [];
        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row['user_id'];
        }
        
        $notificationText = "🔔 Внимание!\n\n" .
                           "Пользователь " . htmlspecialchars($firstName) . 
                           " (@" . htmlspecialchars($username) . ") " .
                           "отменил последнюю фишку!";
        
        foreach ($recipients as $recipientId) {
            sendMessage([
                'chat_id' => $recipientId,
                'text' => $notificationText,
                'parse_mode' => 'HTML'
            ]);
        }
        
        sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ Фишка отменена и уведомление разослано всем участникам."
        ]);
    } catch (Exception $e) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - handleCancelFishkaCommand error: " . $e->getMessage() . "\n", FILE_APPEND);
        sendMessage([
            'chat_id' => $chatId,
            'text' => "⚠️ Произошла ошибка при отмене фишки. Пожалуйста, попробуйте позже."
        ]);
    }
}

function handleCallbackQuery($callbackQuery) {
    global $config, $db;
    
    $callbackData = $callbackQuery['data'];
    $user = $callbackQuery['from'];
    $message = $callbackQuery['message'];
    $userId = $user['id'];
    $chatId = $message['chat']['id'];
    $messageId = $message['message_id'];
    
    try {
        $stmt = $db->prepare("SELECT access_granted FROM bot_users WHERE user_id = ?");
        if (!$stmt) throw new Exception("Prepare failed: " . $db->error);
        
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
        
        $stmt->store_result();
        $stmt->bind_result($accessGranted);
        $stmt->fetch();
        
        if ($stmt->num_rows === 0 || !$accessGranted) {
            answerCallbackQuery($callbackQuery['id'], "❌ У вас нет доступа к этой команде.");
            return;
        }
        
        if ($callbackData === 'cancel_fishka') {
            editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => "❌ Вы отменили выбор проверяющего."
            ]);
            answerCallbackQuery($callbackQuery['id'], "Выбор отменен");
            return;
        }
        
        if (strpos($callbackData, 'inspector_') === 0) {
            $inspectorId = str_replace('inspector_', '', $callbackData);
            $inspectorName = $config['inspectors'][$inspectorId] ?? 'Неизвестный';
            
            // Получаем всех пользователей для рассылки
            $result = $db->query("SELECT user_id FROM bot_users WHERE access_granted = 1");
            if (!$result) throw new Exception("Query failed: " . $db->error);
            
            $recipients = [];
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row['user_id'];
            }
            
            $notificationText = "🔔 Внимание!\n\n" .
                               "Пользователь " . htmlspecialchars($user['first_name']) . 
                               " (@" . htmlspecialchars($user['username'] ?? 'N/A') . ") " .
                               "кинул фишку! На курсе обнаружен: " . htmlspecialchars($inspectorName);
            
            foreach ($recipients as $recipientId) {
                sendMessage([
                    'chat_id' => $recipientId,
                    'text' => $notificationText,
                    'parse_mode' => 'HTML'
                ]);
            }
            
            answerCallbackQuery($callbackQuery['id'], "Фишка подана!");
            
            // Обновляем оригинальное сообщение
            editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => "✅ Вы выбрали: " . htmlspecialchars($inspectorName) . "\n\n" .
                          "Фишка разослана всем участникам.",
                'parse_mode' => 'HTML'
            ]);
            
            // Логируем отправку фишки
            $logMessage = date('Y-m-d H:i:s') . " - User ID: $userId, Username: @" . ($user['username'] ?? 'N/A') . 
                         ", First Name: " . ($user['first_name'] ?? 'N/A') . 
                         ", выбрал проверяющего: $inspectorName";
            file_put_contents('fishka.log', $logMessage . "\n", FILE_APPEND);
        }
    } catch (Exception $e) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - handleCallbackQuery error: " . $e->getMessage() . "\n", FILE_APPEND);
        answerCallbackQuery($callbackQuery['id'], "⚠️ Произошла ошибка");
    }
}

// Вспомогательные функции для API Telegram
function sendMessage($params) {
    global $config;
    
    // Добавляем parse_mode, если не указан
    if (!isset($params['parse_mode'])) {
        $params['parse_mode'] = 'HTML';
    }
    
    $url = "https://api.telegram.org/bot{$config['bot_token']}/sendMessage";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode !== 200 || curl_error($ch)) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Telegram API error: " . curl_error($ch) . 
                         ", HTTP Code: $httpCode, Response: " . $response . "\n", FILE_APPEND);
    }
    
    curl_close($ch);
}

function answerCallbackQuery($callbackId, $text = '') {
    global $config;
    
    $params = [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => (strpos($text, '❌') === 0) // Показывать alert для ошибок
    ];
    
    $url = "https://api.telegram.org/bot{$config['bot_token']}/answerCallbackQuery";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode !== 200 || curl_error($ch)) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Telegram API (answerCallback) error: " . 
                         curl_error($ch) . ", HTTP Code: $httpCode, Response: " . $response . "\n", FILE_APPEND);
    }
    
    curl_close($ch);
}

function editMessageText($params) {
    global $config;
    
    $url = "https://api.telegram.org/bot{$config['bot_token']}/editMessageText";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode !== 200 || curl_error($ch)) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Telegram API (editMessage) error: " . 
                         curl_error($ch) . ", HTTP Code: $httpCode, Response: " . $response . "\n", FILE_APPEND);
    }
    
    curl_close($ch);
}
?>
