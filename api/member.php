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

$action = $obj->action ?? 'listMember';

// -------- 1. LIST  --------------------- 
if ($action === 'listMember') {
    $search_text = $obj->search_text ?? '';
    $stmt = $conn->prepare(
        "SELECT `id`, `member_id`, `member_no`, `name`, `phone`, `membership`, `last_visit_date`, `total_visit_count`, `total_spending`, `create_at`, `membership_activated_at`, `milestone_level`, `bonus_expiry`, `delete_at`,
         CASE WHEN `membership` = 'Yes' AND `membership_activated_at` IS NOT NULL AND DATE_ADD(`membership_activated_at`, INTERVAL 1 YEAR) < NOW() THEN 'expired' ELSE '' END as is_expired
         FROM `member`
         WHERE `delete_at` = 0
           AND `name` LIKE ?
         ORDER BY `id` DESC"
    );
    $search_text = '%' . $search_text . '%';
    $stmt->bind_param("s", $search_text);
    $stmt->execute();
    $result = $stmt->get_result();

    $body = ["member" => []];
    if ($result->num_rows > 0) {
        $body["member"] = $result->fetch_all(MYSQLI_ASSOC);
    }
    $output = [
        "head" => ["code" => 200, "msg" => $body["member"] ? "Success" : "Member Details Not Found"],
        "body" => $body
    ];
}
//  -----------  2. ADD  --------------------
elseif ($action === 'addmember' && isset($obj->name) && isset($obj->phone)) {
    $name   = trim($obj->name);
    $phone  = trim($obj->phone);
    $gold   = $obj->membership ?? 'No';

    if (empty($name) || empty($phone)) {
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
    if (!in_array($gold, ['Yes', 'No'])) {
        $gold = 'No';
    }

    // uniqueness
    $stmt = $conn->prepare("SELECT id FROM member WHERE phone = ? AND delete_at = 0");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    if ($stmt->get_result()->num_rows) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Phone already exists"]]);
        exit;
    }

    $activated_at = ($gold === 'Yes') ? $timestamp : NULL;

    $stmtIns = $conn->prepare(
        "INSERT INTO member (name, phone, membership, membership_activated_at, create_at, delete_at)
         VALUES (?, ?, ?, ?, NOW(), 0)"
    );
    $stmtIns->bind_param("ssss", $name, $phone, $gold, $activated_at);
    if ($stmtIns->execute()) {
        $insertId   = $stmtIns->insert_id;
        $member_id  = uniqueID("member", $insertId);
        $member_no = generateMemberNo($insertId);

        $upd = $conn->prepare("UPDATE member SET member_id = ?, member_no = ? WHERE id = ?");
        $upd->bind_param("ssi", $member_id, $member_no, $insertId);
        $upd->execute();

        $output = ["head" => ["code" => 200, "msg" => "Member created successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Insert error: " . $stmtIns->error]];
    }
    echo json_encode($output);
    exit;
}

//  ----------- 3. UPDATE --------------------
elseif ($action === 'updatemember' && isset($obj->edit_member_id)) {
    $edit_member_id = $obj->edit_member_id;
    $name   = trim($obj->name);
    $phone  = trim($obj->phone);
    $new_gold   = $obj->membership ?? 'No';

    if (empty($name) || empty($phone)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Required fields missing"]]);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9\s.,]+$/', $name) || !preg_match('/^\d{10}$/', $phone)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid data"]]);
        exit;
    }
    if (!in_array($new_gold, ['Yes', 'No'])) $new_gold = 'No';

    // get current details
    $idStmt = $conn->prepare("SELECT id, membership, membership_activated_at FROM member WHERE member_id = ? AND delete_at = 0");
    $idStmt->bind_param("s", $edit_member_id);
    $idStmt->execute();
    $row = $idStmt->get_result()->fetch_assoc();
    $dbId = $row['id'] ?? 0;
    $current_gold = $row['membership'] ?? 'No';
    $current_activated_at = $row['membership_activated_at'];
    if (!$dbId) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Member not found"]]);
        exit;
    }

    // Check downgrade: If current Yes, new No, and not expired
    if ($current_gold === 'Yes' && $new_gold === 'No' && $current_activated_at && strtotime($current_activated_at . ' +1 year') > strtotime($timestamp)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Cannot downgrade Gold membership before 1 year expiry"]]);
        exit;
    }

    // uniqueness (ignore own record)
    $chk = $conn->prepare(
        "SELECT id FROM member WHERE phone = ? AND id != ? AND delete_at = 0"
    );
    $chk->bind_param("si", $phone, $dbId);
    $chk->execute();
    if ($chk->get_result()->num_rows) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Phone already used"]]);
        exit;
    }

    // Determine new activated_at: Only reset if changing from No to Yes (renewal). If keeping Yes, keep old.
    $new_activated_at = $current_activated_at; // Default: keep current
    if ($new_gold === 'Yes' && $current_gold === 'No') {
        $new_activated_at = $timestamp; // New activation/renewal
    } elseif ($new_gold === 'No') {
        $new_activated_at = NULL; // Downgrade
    }
    // If keeping Yes (expired or active), $new_activated_at remains old (no reset)

    // Build UPDATE query dynamically: Only update fields that changed
    $updateFields = "name = ?, phone = ?";
    $params = [$name, $phone];
    $types = "ss";
    if ($new_gold !== $current_gold) {
        $updateFields .= ", membership = ?";
        $params[] = $new_gold;
        $types .= "s";
    }
    if ($new_activated_at !== $current_activated_at) {
        $updateFields .= ", membership_activated_at = ?";
        $params[] = $new_activated_at;
        $types .= "s";
    }
    $updateFields .= " WHERE member_id = ?";
    $params[] = $edit_member_id;
    $types .= "s";

    $upd = $conn->prepare("UPDATE member SET $updateFields");
    $upd->bind_param($types, ...$params);
    if ($upd->execute()) {
        $output = ["head" => ["code" => 200, "msg" => "Member updated successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Update error: " . $upd->error]];
    }
    echo json_encode($output);
    exit;
}

// ------------ 4. DELETE (soft)  ---------------
elseif ($action === 'deleteMember' && isset($obj->delete_member_id)) {
    $delete_member_id = $obj->delete_member_id;
    $stmt = $conn->prepare("UPDATE member SET delete_at = 1 WHERE id = ?");
    $stmt->bind_param("i", $delete_member_id);
    if ($stmt->execute()) {
        $output = ["head" => ["code" => 200, "msg" => "Member deleted successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Delete error: " . $stmt->error]];
    }
    echo json_encode($output);
    exit;
}

//  --------------  5. TOGGLE GOLD MEMBERSHIP ---------------
elseif ($action === 'toggleGold' && isset($obj->member_id) && isset($obj->make_gold)) {
    $member_id  = $obj->member_id;
    $make_gold  = ($obj->make_gold === true || $obj->make_gold === 'Yes') ? 'Yes' : 'No';

    // verify exists & get current
    $stmt = $conn->prepare("SELECT id, membership, membership_activated_at FROM member WHERE member_id = ? AND delete_at = 0");
    $stmt->bind_param("s", $member_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        echo json_encode(["head" => ["code" => 404, "msg" => "Member not found"]]);
        exit;
    }
    $current_gold = $row['membership'];
    $current_activated_at = $row['membership_activated_at'];

    // Check downgrade: If current Yes, new No, and not expired
    if ($current_gold === 'Yes' && $make_gold === 'No' && $current_activated_at && strtotime($current_activated_at . ' +1 year') > strtotime($timestamp)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Cannot downgrade Gold membership before 1 year expiry"]]);
        exit;
    }

    // For toggle: Always set activated_at if Yes (renewal in listing)
    $new_activated_at = ($make_gold === 'Yes') ? $timestamp : NULL;

    $upd = $conn->prepare("UPDATE member SET membership = ?, membership_activated_at = ? WHERE member_id = ?");
    $upd->bind_param("sss", $make_gold, $new_activated_at, $member_id);
    if ($upd->execute()) {
        $output = ["head" => ["code" => 200, "msg" => "Gold membership updated"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Update error"]];
    }
    echo json_encode($output);
    exit;
} else {
    $output = ["head" => ["code" => 400, "msg" => "Invalid action / parameters"]];
}

echo json_encode($output, JSON_NUMERIC_CHECK);
