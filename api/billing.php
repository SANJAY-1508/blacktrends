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

// -----------  2. ADD  --------------------
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
    $created_by_id = $obj->created_by_id ?? null;
    $updated_by_id = null;
    $member_id = $obj->member_id ?? null;

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

    // Check if member_no exists and get member_id (use provided if available, else fetch)
    if (!$member_id) {
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
    } else {
        // Validate provided member_id matches member_no
        $memberCheck = $conn->prepare("SELECT id FROM member WHERE member_id = ? AND member_no = ? AND delete_at = 0");
        $memberCheck->bind_param("ss", $member_id, $member_no);
        $memberCheck->execute();
        $memberResult = $memberCheck->get_result();
        if ($memberResult->num_rows === 0) {
            echo json_encode(["head" => ["code" => 400, "msg" => "Invalid member_id or member_no mismatch"]]);
            exit;
        }
    }

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

    // Insert billing - Note: updated_by_id bound as NULL
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

        // Update staff totals after successful insert
        if (!updateStaffTotals($conn, $productandservice_details, 'add')) {
            // Optionally rollback, but for now, log error
            error_log("Failed to update staff totals for new billing: " . $billing_id);
        }

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
    $updated_by_id = $obj->updated_by_id ?? null;

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

    // Get existing billing to check if member_no changed and fetch old details
    $existingStmt = $conn->prepare("SELECT member_id, member_no, productandservice_details FROM billing WHERE billing_id = ? AND delete_at = 0");
    $existingStmt->bind_param("s", $edit_billing_id);
    $existingStmt->execute();
    $existingRow = $existingStmt->get_result()->fetch_assoc();
    if (!$existingRow) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Billing not found"]]);
        exit;
    }
    $existing_member_id = $existingRow['member_id'];
    $existing_member_no = $existingRow['member_no'];
    $old_details = $existingRow['productandservice_details'];

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

    // Subtract old staff totals
    if (!updateStaffTotals($conn, $old_details, 'subtract')) {
        error_log("Failed to subtract old staff totals for billing update: " . $edit_billing_id);
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
        // Add new staff totals
        if (!updateStaffTotals($conn, $productandservice_details, 'add')) {
            error_log("Failed to add new staff totals for billing update: " . $edit_billing_id);
        }

        $output = ["head" => ["code" => 200, "msg" => "Billing updated successfully"]];
    } else {
        // If update failed, re-add the old totals to rollback staff changes
        if (!updateStaffTotals($conn, $old_details, 'add')) {
            error_log("Failed to rollback staff totals after billing update failure: " . $edit_billing_id);
        }
        $output = ["head" => ["code" => 400, "msg" => "Update error: " . $upd->error]];
    }
    echo json_encode($output);
    exit;
}

// ------------ 4. DELETE (soft)  ---------------
elseif ($action === 'deleteBilling' && isset($obj->delete_billing_id)) {
    $delete_billing_id = $obj->delete_billing_id;
    $delete_by_id = $obj->delete_by_id ?? null;

    // Fetch old details before delete
    $fetchStmt = $conn->prepare("SELECT productandservice_details FROM billing WHERE id = ? AND delete_at = 0");
    $fetchStmt->bind_param("i", $delete_billing_id);
    $fetchStmt->execute();
    $fetchRow = $fetchStmt->get_result()->fetch_assoc();
    if (!$fetchRow) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Billing not found"]]);
        exit;
    }
    $old_details = $fetchRow['productandservice_details'];

    // Subtract staff totals
    if (!updateStaffTotals($conn, $old_details, 'subtract')) {
        error_log("Failed to subtract staff totals for deleted billing: " . $delete_billing_id);
    }

    $stmt = $conn->prepare("UPDATE billing SET delete_at = 1, delete_by_id = ? WHERE id = ?");
    $stmt->bind_param("si", $delete_by_id, $delete_billing_id);
    if ($stmt->execute()) {
        $output = ["head" => ["code" => 200, "msg" => "Billing deleted successfully"]];
    } else {
        // Rollback staff totals if delete failed
        if (!updateStaffTotals($conn, $old_details, 'add')) {
            error_log("Failed to rollback staff totals after delete failure: " . $delete_billing_id);
        }
        $output = ["head" => ["code" => 400, "msg" => "Delete error: " . $stmt->error]];
    }
    echo json_encode($output);
    exit;
}

// ------------ 5. STAFF REPORT (date-wise grouped by staff) ---------------
elseif ($action === 'staffReport') {
    $from_date = $obj->from_date ?? '';
    $to_date = $obj->to_date ?? '';
    $search_text = trim($obj->search_text ?? '');

    if (empty($from_date) || empty($to_date)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "from_date and to_date are required"]]);
        exit;
    }

    $sql = "SELECT billing_date, productandservice_details FROM billing WHERE delete_at = 0 AND billing_date BETWEEN ? AND ? ORDER BY billing_date DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $billings = $result->fetch_all(MYSQLI_ASSOC);

    // Collect unique staff_ids from all billings
    $staff_ids = [];
    foreach ($billings as $b) {
        $details = json_decode($b['productandservice_details'], true);
        if (is_array($details)) {
            foreach ($details as $item) {
                if (isset($item['staff_id'])) {
                    $staff_ids[$item['staff_id']] = true;
                }
            }
        }
    }

    // Fetch staff details
    $staff_map = [];
    if (!empty($staff_ids)) {
        $id_list = array_keys($staff_ids);
        $placeholders = str_repeat('?,', count($id_list) - 1) . '?';
        $staff_sql = "SELECT staff_id, name, phone, address FROM staff WHERE staff_id IN ($placeholders) AND delete_at = 0";
        $staff_stmt = $conn->prepare($staff_sql);
        $staff_types = str_repeat('s', count($id_list));
        $staff_stmt->bind_param($staff_types, ...$id_list);
        $staff_stmt->execute();
        $s_result = $staff_stmt->get_result();
        while ($s_row = $s_result->fetch_assoc()) {
            $staff_map[$s_row['staff_id']] = $s_row;
        }
    }

    // Group and aggregate with search filter on staff_name
    $grouped = [];
    foreach ($billings as $b) {
        $report_date = date('Y-m-d', strtotime($b['billing_date']));
        $details = json_decode($b['productandservice_details'], true);
        if (is_array($details)) {
            foreach ($details as $item) {
                $staff_name = $item['staff_name'] ?? '';
                if (!empty($search_text) && stripos($staff_name, $search_text) === false) {
                    continue;
                }
                $sid = $item['staff_id'] ?? '';
                if (empty($sid) || !isset($staff_map[$sid])) {
                    continue;
                }
                $key = $report_date . '_' . $sid;
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'report_date' => $report_date,
                        'name' => $staff_map[$sid]['name'],
                        'phone' => $staff_map[$sid]['phone'],
                        'address' => $staff_map[$sid]['address'],
                        'total' => 0
                    ];
                }
                $grouped[$key]['total'] += floatval($item['total'] ?? 0);
            }
        }
    }

    $staff_data = array_values($grouped);

    // Sort: date DESC, name ASC
    usort($staff_data, function ($a, $b) {
        if ($a['report_date'] !== $b['report_date']) {
            return strtotime($b['report_date']) <=> strtotime($a['report_date']);
        }
        return strcmp($a['name'], $b['name']);
    });

    $body = ["staff" => $staff_data];
    $output = [
        "head" => ["code" => 200, "msg" => !empty($staff_data) ? "Success" : "No data found"],
        "body" => $body
    ];
    echo json_encode($output);
    exit;
}

// ------------ 6. MEMBER REPORT (date-wise grouped by member) ---------------
elseif ($action === 'memberReport') {
    $from_date = $obj->from_date ?? '';
    $to_date = $obj->to_date ?? '';
    $search_text = trim($obj->search_text ?? '');

    if (empty($from_date) || empty($to_date)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "from_date and to_date are required"]]);
        exit;
    }

    $search_cond = '';
    $types = 'ss';
    $params = [$from_date, $to_date];
    if (!empty($search_text)) {
        $like = '%' . $search_text . '%';
        $search_cond = "AND (m.name LIKE ? OR m.phone LIKE ?)";
        $types .= 'ss';
        $params = array_merge($params, [$like, $like]);
    }

    $sql = "SELECT b.id, DATE(b.billing_date) as report_date, b.member_id, b.member_no, m.name, m.phone, b.membership, b.last_visit_date, b.total_visit_count, b.total_spending, b.total 
            FROM billing b 
            JOIN member m ON b.member_id = m.id 
            WHERE b.delete_at = 0 AND m.delete_at = 0 
            AND b.billing_date BETWEEN ? AND ? $search_cond 
            ORDER BY b.billing_date DESC, b.member_id ASC, b.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    // Group and aggregate (latest stats from last billing of the day)
    $grouped = [];
    foreach ($rows as $row) {
        $key = $row['report_date'] . '_' . $row['member_id'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = $row;
            $grouped[$key]['daily_total'] = 0;
        }
        $grouped[$key]['daily_total'] += floatval($row['total']);
    }

    $member_data = array_values($grouped);

    // Sort: date DESC, name ASC
    usort($member_data, function ($a, $b) {
        if ($a['report_date'] !== $b['report_date']) {
            return strtotime($b['report_date']) <=> strtotime($a['report_date']);
        }
        return strcmp($a['name'], $b['name']);
    });

    $body = ["member" => $member_data];
    $output = [
        "head" => ["code" => 200, "msg" => !empty($member_data) ? "Success" : "No data found"],
        "body" => $body
    ];
    echo json_encode($output);
    exit;
} else {
    $output = ["head" => ["code" => 400, "msg" => "Invalid action / parameters"]];
}

echo json_encode($output, JSON_NUMERIC_CHECK);
