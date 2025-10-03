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
        "SELECT `id`, `member_id`, `member_no`, `name`, `phone`, `gold_membership`,`last_visit_date`,`total_visit_count`, `total_spending`,`create_at`, `delete_at`
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
    $gold   = $obj->gold_membership ?? 'No';

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

    $stmtIns = $conn->prepare(
        "INSERT INTO member (name, phone, gold_membership, create_at, delete_at)
         VALUES (?, ?, ?, NOW(), 0)"
    );
    $stmtIns->bind_param("sss", $name, $phone, $gold);
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
    $gold   = $obj->gold_membership ?? 'No';

    if (empty($name) || empty($phone)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Required fields missing"]]);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9\s.,]+$/', $name) || !preg_match('/^\d{10}$/', $phone)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid data"]]);
        exit;
    }
    if (!in_array($gold, ['Yes', 'No'])) $gold = 'No';

    // get numeric id
    $idStmt = $conn->prepare("SELECT id FROM member WHERE member_id = ? AND delete_at = 0");
    $idStmt->bind_param("s", $edit_member_id);
    $idStmt->execute();
    $row = $idStmt->get_result()->fetch_assoc();
    $dbId = $row['id'] ?? 0;
    if (!$dbId) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Member not found"]]);
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

    $upd = $conn->prepare(
        "UPDATE member SET name = ?, phone = ?, gold_membership = ?
         WHERE member_id = ?"
    );
    $upd->bind_param("ssss", $name, $phone, $gold, $edit_member_id);
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

//  --------------  5. TOGGLE GOLD MEMBERSHIP (new AJAX endpoint) ---------------
elseif ($action === 'toggleGold' && isset($obj->member_id) && isset($obj->make_gold)) {
    $member_id  = $obj->member_id;
    $make_gold  = ($obj->make_gold === true || $obj->make_gold === 'Yes') ? 'Yes' : 'No';

    // verify exists
    $stmt = $conn->prepare("SELECT id FROM member WHERE member_id = ? AND delete_at = 0");
    $stmt->bind_param("s", $member_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(["head" => ["code" => 404, "msg" => "Member not found"]]);
        exit;
    }

    $upd = $conn->prepare("UPDATE member SET gold_membership = ? WHERE member_id = ?");
    $upd->bind_param("ss", $make_gold, $member_id);
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
