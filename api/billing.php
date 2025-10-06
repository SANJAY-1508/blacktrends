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

$action = $obj->action ?? 'listBilling';

// -------- 1. LIST  --------------------- 
if ($action === 'listBilling') {
    $search_text = $obj->search_text ?? '';
    $stmt = $conn->prepare(
        "SELECT `id`, `billing_id`, `billing_date`, `member_id`, `member_no`, `name`, `phone`, 
                `productandservice_details`, `subtotal`, `discount`, `discount_type`, `total`, 
                `last_visit_date`, `total_visit_count`, `total_spending`, `membership`,
                `create_at`, `delete_at`, `created_by_id`, `updated_by_id`, `delete_by_id`
         FROM `billing`
         WHERE `delete_at` = 0
           AND `name` LIKE ?
         ORDER BY `id` DESC"
    );
    $search_text = '%' . $search_text . '%';
    $stmt->bind_param("s", $search_text);
    $stmt->execute();
    $result = $stmt->get_result();

    $body = ["billing" => []];
    if ($result->num_rows > 0) {
        $body["billing"] = $result->fetch_all(MYSQLI_ASSOC);
    }
    $output = [
        "head" => ["code" => 200, "msg" => $body["billing"] ? "Success" : "Billing Details Not Found"],
        "body" => $body
    ];
}

//  -----------  2. ADD  --------------------
elseif ($action === 'addBilling' && isset($obj->member_no) && isset($obj->name) && isset($obj->phone)) {
    $billing_date = $obj->billing_date ?? $timestamp;
    $member_no = trim($obj->member_no);
    $name = trim($obj->name);
    $phone = trim($obj->phone);
    $productandservice_details = $obj->productandservice_details ?? '';
    $subtotal = floatval($obj->subtotal ?? 0);
    $discount = floatval($obj->discount ?? 0);
    $discount_type = in_array($obj->discount_type ?? 'INR', ['INR', 'PER']) ? $obj->discount_type : 'INR';
    $total = floatval($obj->total ?? 0);
    $last_visit_date = $obj->last_visit_date ?? $billing_date;
    $total_visit_count = intval($obj->total_visit_count ?? 0);
    $total_spending = floatval($obj->total_spending ?? 0);
    $membership = in_array($obj->membership, ['Yes', 'No']) ? $obj->membership : 'No';
    $created_by_id = $obj->created_by_id ?? 1; // Assume from auth
    $updated_by_id = $created_by_id;

    if (empty($name) || empty($phone) || empty($member_no)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Required fields missing"]]);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9\s.,]+$/', $name)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid name"]]);
        exit;
    }
    if (!preg_match('/^\d{10}$/', $phone)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Phone must be 10 digits"]]);
        exit;
    }
    if ($subtotal < 0 || $discount < 0 || $total < 0 || $total_spending < 0) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Amounts cannot be negative"]]);
        exit;
    }

    // Check if member_no exists and get member_id
    $memberCheck = $conn->prepare("SELECT id FROM member WHERE member_no = ? AND delete_at = 0");
    $memberCheck->bind_param("s", $member_no);
    $memberCheck->execute();
    $memberResult = $memberCheck->get_result();
    if ($memberResult->num_rows === 0) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid member number"]]);
        exit;
    }
    $memberRow = $memberResult->fetch_assoc();
    $member_id = $memberRow['id'];

    // Update member with received stats (frontend already updated)
    $updateMember = $conn->prepare(
        "UPDATE member SET last_visit_date = ?, total_visit_count = ?, 
         total_spending = ?, membership = ? 
         WHERE id = ? AND delete_at = 0"
    );
    $updateMember->bind_param(
        "sidss",
        $last_visit_date,
        $total_visit_count,
        $total_spending,
        $membership,
        $member_id
    );
    if (!$updateMember->execute()) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Member update error: " . $updateMember->error]]);
        exit;
    }

    // Insert billing with received values including stats
    $stmtIns = $conn->prepare(
        "INSERT INTO billing (billing_date, member_id, member_no, name, phone, productandservice_details, 
         subtotal, discount, discount_type, total, last_visit_date, total_visit_count, total_spending, 
         membership, create_at, delete_at, created_by_id, updated_by_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0, ?, ?)"
    );
    $stmtIns->bind_param(
        "ssssssdssdsidsss",
        $billing_date,
        $member_id,
        $member_no,
        $name,
        $phone,
        $productandservice_details,
        $subtotal,
        $discount,
        $discount_type,
        $total,
        $last_visit_date,
        $total_visit_count,
        $total_spending,
        $membership,
        $created_by_id,
        $updated_by_id
    );
    if ($stmtIns->execute()) {
        $insertId = $stmtIns->insert_id;
        $billing_id = uniqueID("billing", $insertId);
        $upd = $conn->prepare("UPDATE billing SET billing_id = ? WHERE id = ?");
        $upd->bind_param("si", $billing_id, $insertId);
        $upd->execute();

        $output = ["head" => ["code" => 200, "msg" => "Billing created successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Insert error: " . $stmtIns->error]];
    }
    echo json_encode($output);
    exit;
}

//  ----------- 3. UPDATE --------------------
elseif ($action === 'updateBilling' && isset($obj->edit_billing_id)) {
    $edit_billing_id = $obj->edit_billing_id;
    $billing_date = $obj->billing_date ?? $timestamp;
    $member_no = trim($obj->member_no);
    $name = trim($obj->name);
    $phone = trim($obj->phone);
    $productandservice_details = $obj->productandservice_details ?? '';
    $subtotal = floatval($obj->subtotal ?? 0);
    $discount = floatval($obj->discount ?? 0);
    $discount_type = in_array($obj->discount_type ?? 'INR', ['INR', 'PER']) ? $obj->discount_type : 'INR';
    $total = floatval($obj->total ?? 0);
    $last_visit_date = $obj->last_visit_date ?? $billing_date;
    $total_visit_count = intval($obj->total_visit_count ?? 0);
    $total_spending = floatval($obj->total_spending ?? 0);
    $membership = in_array($obj->membership, ['Yes', 'No']) ? $obj->membership : 'No';
    $updated_by_id = $obj->updated_by_id ?? 1;

    if (empty($name) || empty($phone) || empty($member_no)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Required fields missing"]]);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9\s.,]+$/', $name) || !preg_match('/^\d{10}$/', $phone)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid data"]]);
        exit;
    }
    if ($subtotal < 0 || $discount < 0 || $total < 0 || $total_spending < 0) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Amounts cannot be negative"]]);
        exit;
    }

    // Get existing billing to check if member_no changed
    $existingStmt = $conn->prepare("SELECT member_id, member_no FROM billing WHERE billing_id = ? AND delete_at = 0");
    $existingStmt->bind_param("s", $edit_billing_id);
    $existingStmt->execute();
    $existingRow = $existingStmt->get_result()->fetch_assoc();
    if (!$existingRow) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Billing not found"]]);
        exit;
    }
    $existing_member_id = $existingRow['member_id'];
    $existing_member_no = $existingRow['member_no'];

    $member_id = $existing_member_id;
    if ($member_no !== $existing_member_no) {
        // Member changed, get new member_id
        $memberCheck = $conn->prepare("SELECT id FROM member WHERE member_no = ? AND delete_at = 0");
        $memberCheck->bind_param("s", $member_no);
        $memberCheck->execute();
        $newMemberResult = $memberCheck->get_result();
        if ($newMemberResult->num_rows === 0) {
            echo json_encode(["head" => ["code" => 400, "msg" => "Invalid new member number"]]);
            exit;
        }
        $newMemberRow = $newMemberResult->fetch_assoc();
        $member_id = $newMemberRow['id'];
    }

    $upd = $conn->prepare(
        "UPDATE billing SET billing_date = ?, member_id = ?, member_no = ?, name = ?, phone = ?, 
         productandservice_details = ?, subtotal = ?, discount = ?, discount_type = ?, total = ?, 
         last_visit_date = ?, total_visit_count = ?, total_spending = ?, membership = ?, 
         updated_by_id = ?
         WHERE billing_id = ?"
    );
    $upd->bind_param(
        "ssssssdssdsidsss",
        $billing_date,
        $member_id,
        $member_no,
        $name,
        $phone,
        $productandservice_details,
        $subtotal,
        $discount,
        $discount_type,
        $total,
        $last_visit_date,
        $total_visit_count,
        $total_spending,
        $membership,
        $updated_by_id,
        $edit_billing_id
    );
    if ($upd->execute()) {
        $output = ["head" => ["code" => 200, "msg" => "Billing updated successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Update error: " . $upd->error]];
    }
    echo json_encode($output);
    exit;
}

// ------------ 4. DELETE (soft)  ---------------
elseif ($action === 'deleteBilling' && isset($obj->delete_billing_id)) {
    $delete_billing_id = $obj->delete_billing_id;   // numeric id
    $delete_by_id = $obj->delete_by_id ?? 1;
    $stmt = $conn->prepare("UPDATE billing SET delete_at = 1, delete_by_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $delete_by_id, $delete_billing_id);
    if ($stmt->execute()) {
        $output = ["head" => ["code" => 200, "msg" => "Billing deleted successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Delete error: " . $stmt->error]];
    }
    echo json_encode($output);
    exit;
} else {
    $output = ["head" => ["code" => 400, "msg" => "Invalid action / parameters"]];
}

echo json_encode($output, JSON_NUMERIC_CHECK);
