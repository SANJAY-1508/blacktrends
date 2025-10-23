<?php
include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: * ");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json    = file_get_contents('php://input');
$obj     = json_decode($json);
$output  = [];

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

$action = $obj->action ?? 'listStaff';

// -------- 1. LIST  --------------------- 
if ($action === 'listStaff') {
    $search_text = $obj->search_text ?? '';
    $stmt = $conn->prepare(
        "SELECT `id`, `staff_id`, `name`, `phone`, `address`,`total`,
                `create_at`, `delete_at`
         FROM `staff`
         WHERE `delete_at` = 0
           AND `name` LIKE ?
         ORDER BY `id` DESC"
    );
    $search_text = '%' . $search_text . '%';
    $stmt->bind_param("s", $search_text);
    $stmt->execute();
    $result = $stmt->get_result();

    $body = ["staff" => []];
    if ($result->num_rows > 0) {
        $body["staff"] = $result->fetch_all(MYSQLI_ASSOC);
    }
    $output = [
        "head" => ["code" => 200, "msg" => $body["staff"] ? "Success" : "Staff Details Not Found"],
        "body" => $body
    ];
}

//  -----------  2. ADD  -----------------
elseif ($action === 'addstaff' && isset($obj->name) && isset($obj->phone)) {
    $name    = trim($obj->name);
    $phone   = trim($obj->phone);
    $address = trim($obj->address ?? '');

    if (empty($name) || empty($phone)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Required fields missing"]]);
        exit;
    }

    if (!preg_match('/^\d{10}$/', $phone)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Phone must be 10 digits"]]);
        exit;
    }

    // uniqueness
    $stmt = $conn->prepare("SELECT id FROM staff WHERE phone = ? AND delete_at = 0");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    if ($stmt->get_result()->num_rows) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Phone already exists"]]);
        exit;
    }

    $stmtIns = $conn->prepare(
        "INSERT INTO staff (name, phone, address, create_at, delete_at)
         VALUES (?, ?, ?, NOW(), 0)"
    );
    $stmtIns->bind_param("sss", $name, $phone, $address);
    if ($stmtIns->execute()) {
        $insertId  = $stmtIns->insert_id;
        $staff_id  = uniqueID("staff", $insertId);
        $upd = $conn->prepare("UPDATE staff SET staff_id = ? WHERE id = ?");
        $upd->bind_param("si", $staff_id, $insertId);
        $upd->execute();

        $output = ["head" => ["code" => 200, "msg" => "Staff created successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Insert error: " . $stmtIns->error]];
    }
    echo json_encode($output);
    exit;
}

//  ----------- 3. UPDATE -----------------
elseif ($action === 'updatestaff' && isset($obj->edit_staff_id)) {
    $edit_staff_id = $obj->edit_staff_id;
    $name    = trim($obj->name);
    $phone   = trim($obj->phone);
    $address = trim($obj->address ?? '');

    if (empty($name) || empty($phone)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Required fields missing"]]);
        exit;
    }
    if (!preg_match('/^\d{10}$/', $phone)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid data"]]);
        exit;
    }

    // get numeric id
    $idStmt = $conn->prepare("SELECT id FROM staff WHERE staff_id = ? AND delete_at = 0");
    $idStmt->bind_param("s", $edit_staff_id);
    $idStmt->execute();
    $row = $idStmt->get_result()->fetch_assoc();
    $dbId = $row['id'] ?? 0;
    if (!$dbId) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Staff not found"]]);
        exit;
    }

    // uniqueness (ignore own record)
    $chk = $conn->prepare(
        "SELECT id FROM staff WHERE phone = ? AND id != ? AND delete_at = 0"
    );
    $chk->bind_param("si", $phone, $dbId);
    $chk->execute();
    if ($chk->get_result()->num_rows) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Phone already used"]]);
        exit;
    }

    $upd = $conn->prepare(
        "UPDATE staff SET name = ?, phone = ?, address = ?
         WHERE staff_id = ?"
    );
    $upd->bind_param("ssss", $name, $phone, $address, $edit_staff_id);
    if ($upd->execute()) {
        $output = ["head" => ["code" => 200, "msg" => "Staff updated successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Update error: " . $upd->error]];
    }
    echo json_encode($output);
    exit;
}

// ----------- 4. DELETE (soft)  -----------
elseif ($action === 'deleteStaff' && isset($obj->delete_staff_id)) {
    $delete_staff_id = $obj->delete_staff_id;
    $stmt = $conn->prepare("UPDATE staff SET delete_at = 1 WHERE id = ?");
    $stmt->bind_param("i", $delete_staff_id);
    if ($stmt->execute()) {
        $output = ["head" => ["code" => 200, "msg" => "Staff deleted successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Delete error: " . $stmt->error]];
    }
    echo json_encode($output);
    exit;
} else {
    $output = ["head" => ["code" => 400, "msg" => "Invalid action / parameters"]];
}

echo json_encode($output, JSON_NUMERIC_CHECK);
