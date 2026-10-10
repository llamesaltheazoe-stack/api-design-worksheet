<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, Idempotency-Key");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database Connection
$host = '127.0.0.1';
$db   = 'canteen_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(["message" => "Database connection failed: " . $e->getMessage()]);
    exit();
}

// Function to publish event to message queue log
function publishEvent($eventType, $payload) {
    $eventData = [
        "eventId" => "EVT-" . rand(100000, 999999),
        "eventType" => $eventType,
        "timestamp" => date('c'),
        "data" => $payload
    ];
    file_put_contents(__DIR__ . '/events.jsonlog', json_encode($eventData) . PHP_EOL, FILE_APPEND);
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true);

// 1. GET /students/{studentId}/account
if ($method === 'GET' && preg_match('#/students/([^/]+)/account#i', $uri, $matches)) {
    $studentId = $matches[1];
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if ($student) {
        http_response_code(200);
        echo json_encode([
            "studentId" => $student['student_id'],
            "accountStatus" => $student['account_status'],
            "currency" => $student['currency'],
            "availableBalance" => number_format((float)$student['available_balance'], 2, '.', '')
        ]);
    } else {
        http_response_code(404);
        echo json_encode(["message" => "Student not found"]);
    }
    exit();
}

// 2. POST /payments (Deducts balance & dispatches PAYMENT_COMPLETED event)
if ($method === 'POST' && preg_match('#/payments$#i', $uri)) {
    $studentId = $body['studentId'] ?? null;
    $orderId = $body['orderId'] ?? null;
    $amount = (float)($body['amount'] ?? 0);

    if (!$studentId || !$orderId || $amount <= 0) {
        http_response_code(400);
        echo json_encode(["message" => "Invalid payment request payload"]);
        exit();
    }

    $stmt = $pdo->prepare("SELECT available_balance FROM students WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if (!$student) {
        http_response_code(404);
        echo json_encode(["message" => "Student not found"]);
        exit();
    }

    if ((float)$student['available_balance'] < $amount) {
        http_response_code(400);
        echo json_encode(["message" => "Insufficient student balance"]);
        exit();
    }

    $paymentId = "PAY-" . rand(10000, 99999);
    $newBalance = (float)$student['available_balance'] - $amount;

    $pdo->beginTransaction();
    try {
        $updateStmt = $pdo->prepare("UPDATE students SET available_balance = ? WHERE student_id = ?");
        $updateStmt->execute([$newBalance, $studentId]);

        $insertStmt = $pdo->prepare("INSERT INTO payments (payment_id, order_id, student_id, amount, status) VALUES (?, ?, ?, ?, 'completed')");
        $insertStmt->execute([$paymentId, $orderId, $studentId, $amount]);

        $pdo->commit();

        $response = [
            "paymentId" => $paymentId,
            "orderId" => $orderId,
            "studentId" => $studentId,
            "amount" => number_format($amount, 2, '.', ''),
            "currency" => "USD",
            "status" => "completed",
            "remainingBalance" => number_format($newBalance, 2, '.', '')
        ];

        // Publish event message asynchronously
        publishEvent("PAYMENT_COMPLETED", $response);

        http_response_code(201);
        echo json_encode($response);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(["message" => "Transaction failed: " . $e->getMessage()]);
    }
    exit();
}

// 3. GET /payments/{paymentId}
if ($method === 'GET' && preg_match('#/payments/([^/]+)$#i', $uri, $matches)) {
    $paymentId = $matches[1];
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE payment_id = ?");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();

    if ($payment) {
        http_response_code(200);
        echo json_encode([
            "paymentId" => $payment['payment_id'],
            "orderId" => $payment['order_id'],
            "studentId" => $payment['student_id'],
            "amount" => number_format((float)$payment['amount'], 2, '.', ''),
            "currency" => $payment['currency'],
            "status" => $payment['status']
        ]);
    } else {
        http_response_code(404);
        echo json_encode(["message" => "Payment record not found"]);
    }
    exit();
}

// 4. GET /events (Reads published message queue)
if ($method === 'GET' && preg_match('#/events$#i', $uri)) {
    $logFile = __DIR__ . '/events.jsonlog';
    if (!file_exists($logFile)) {
        http_response_code(200);
        echo json_encode([]);
        exit();
    }
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $events = array_map('json_decode', $lines);
    http_response_code(200);
    echo json_encode(array_values($events));
    exit();
}

http_response_code(404);
echo json_encode(["message" => "Endpoint not found"]);