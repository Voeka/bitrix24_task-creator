<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

$bitrixWebhookUrl = "";
// Укажите ваш URL вебхука Bitrix24
$logFile = __DIR__ . '/upload_errors.log';

// Функция для безопасной очистки пользовательского ввода
function sanitize($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

// Функция для конвертации файла в base64
function convertToBase64($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }
    $fileData = file_get_contents($filePath);
    return base64_encode($fileData);
}

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "error" => "Неверный метод запроса"]);
    exit;
}

// Получение и фильтрация данных из POST
$fast = sanitize($_POST['fast'] ?? '');
$question = sanitize($_POST['question'] ?? '');
$site = sanitize($_POST['site'] ?? '');
$message = sanitize($_POST['message'] ?? '');
$whoQuestion = sanitize($_POST['whoQuestion'] ?? '');
$date = sanitize($_POST['date'] ?? '');
$deadline = $date ? $date . " 19:00:00" : null;

$fileIds = [];

// Обработка файлов (с логированием ошибок)
if (!empty($_FILES['files']) && is_array($_FILES['files']['tmp_name'])) {
    foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
        $errorCode = $_FILES['files']['error'][$key];
        $fileName = $_FILES['files']['name'][$key];

        if ($errorCode !== UPLOAD_ERR_OK) {
            error_log(date('Y-m-d H:i:s') . " | Ошибка загрузки файла '$fileName': код ошибки $errorCode\n", 3, $logFile);
            continue;
        }

        // Генерация уникального идентификатора
        $uniqueId = uniqid('', true);

        // Разделение имени файла и расширения
        $pathInfo = pathinfo($fileName);
        $newFileName = $uniqueId . '_' . $pathInfo['basename'];

        // Преобразование файла в base64
        $base64Content = convertToBase64($tmpName);
        if ($base64Content === null) {
            error_log(date('Y-m-d H:i:s') . " | Не удалось прочитать файл '$fileName'\n", 3, $logFile);
            continue;
        }

        // Подготовка данных для отправки
        $fileData = [
            "id" => 145389,
            "data" => ["NAME" => $newFileName],  // Используем новое имя файла
            "fileContent" => [$newFileName, $base64Content]
        ];

        // Отправка файла в Bitrix
        $ch = curl_init($bitrixWebhookUrl . "disk.folder.uploadfile");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fileData));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode === 200 && !empty($result['result']['ID'])) {
            $fileIds[] = 'n' . $result['result']['ID'];
        } else {
            $errorMsg = $result['error_description'] ?? 'Неизвестная ошибка';
            error_log(date('Y-m-d H:i:s') . " | Ошибка при отправке файла '$newFileName' в Bitrix24: $errorMsg\n", 3, $logFile);
        }
    }
}

// Создание задачи в Bitrix24
$taskData = [
    "fields" => [
        "TITLE" => trim("$fast $question на $site"),
        "DESCRIPTION" => "$message\n\n от $whoQuestion",
        "DEADLINE" => $deadline,
        //  807 - ID пользователя, который создает задачу
        "CREATED_BY" => 807,
        //  807 - ID ответственного пользователя
        "RESPONSIBLE_ID" => 807,
        "ACCOMPLICES" => [],
        //  569 - ID группы, в которую будет добавлена задача(Можно убрать, если не нужно)
        "GROUP_ID" => 569,
    ]
];

if (!empty($fileIds)) {
    $taskData["fields"]["UF_TASK_WEBDAV_FILES"] = $fileIds;
}

$ch = curl_init($bitrixWebhookUrl . "tasks.task.add.json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($taskData));

$taskResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (curl_errno($ch)) {
    echo json_encode(["success" => false, "error" => "cURL error: " . curl_error($ch)]);
    exit;
}
curl_close($ch);

$taskResult = json_decode($taskResponse, true);

if ($httpCode !== 200 || empty($taskResult['result'])) {
    echo json_encode(["success" => false, "error" => "Ошибка создания задачи в Bitrix24: " . ($taskResult['error_description'] ?? "Неизвестная ошибка")]);
    exit;
}

echo json_encode(["success" => true, "message" => "Задача успешно отправлена в Bitrix24!"]);
?>
