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

$action = $obj->action ?? 'listProductAndService';

// -------- 1. LIST  --------------------- 
if ($action === 'listProductAndService') {
    $search_text = $obj->search_text ?? '';
    $stmt = $conn->prepare(
        "SELECT `id`, `productandservice_id`, `category_id`, `category_name`, `productandservice_name`, `productandservice_price`, `serial_number`,
                `create_at`, `delete_at`
         FROM `productandservice`
         WHERE `delete_at` = 0
           AND (`productandservice_name` LIKE ? OR `serial_number` LIKE ?)
         ORDER BY `id` ASC"
    );
    $search_text = '%' . $search_text . '%';
    $stmt->bind_param("ss", $search_text, $search_text);
    $stmt->execute();
    $result = $stmt->get_result();

    $body = ["productandservice" => []];
    if ($result->num_rows > 0) {
        $body["productandservice"] = $result->fetch_all(MYSQLI_ASSOC);
    }
    $output = [
        "head" => ["code" => 200, "msg" => $body["productandservice"] ? "Success" : "Product & Service Details Not Found"],
        "body" => $body
    ];
}

//  -----------  2. ADD  -----------------
elseif ($action === 'addProductAndService' && isset($obj->productandservice_name) && isset($obj->productandservice_price) && isset($obj->category_id) && isset($obj->serial_no)) {
    $name   = trim($obj->productandservice_name);
    $price  = trim($obj->productandservice_price);
    $cat_id = trim($obj->category_id);
    $serial_no = trim($obj->serial_no);

    if (empty($name) || empty($price) || empty($cat_id) || empty($serial_no)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Required fields missing"]]);
        exit;
    }

    if (!is_numeric($price) || $price <= 0) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Price must be a positive number"]]);
        exit;
    }

    // check category exists
    $stmt = $conn->prepare("SELECT category_name FROM category WHERE category_id = ? AND delete_at = 0");
    $stmt->bind_param("s", $cat_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid category"]]);
        exit;
    }
    $cat_row = $result->fetch_assoc();
    $cat_name = $cat_row['category_name'];

    // uniqueness on name
    $stmt = $conn->prepare("SELECT id FROM productandservice WHERE productandservice_name = ? AND delete_at = 0");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    if ($stmt->get_result()->num_rows) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Name already exists"]]);
        exit;
    }

    // uniqueness on serial_no
    $stmt = $conn->prepare("SELECT id FROM productandservice WHERE serial_number = ? AND delete_at = 0");
    $stmt->bind_param("s", $serial_no);
    $stmt->execute();
    if ($stmt->get_result()->num_rows) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Serial number already exists"]]);
        exit;
    }

    $stmtIns = $conn->prepare(
        "INSERT INTO productandservice (productandservice_name, productandservice_price, category_id, category_name, serial_number, create_at, delete_at)
         VALUES (?, ?, ?, ?, ?, NOW(), 0)"
    );
    $stmtIns->bind_param("sdsss", $name, $price, $cat_id, $cat_name, $serial_no);
    if ($stmtIns->execute()) {
        $insertId  = $stmtIns->insert_id;
        $ps_id     = uniqueID("productandservice", $insertId);      // <-- your helper
        $upd = $conn->prepare("UPDATE productandservice SET productandservice_id = ? WHERE id = ?");
        $upd->bind_param("si", $ps_id, $insertId);
        $upd->execute();

        $output = ["head" => ["code" => 200, "msg" => "Product & Service created successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Insert error: " . $stmtIns->error]];
    }
    echo json_encode($output);
    exit;
}

//  ----------- 3. UPDATE ---------------- 
elseif ($action === 'updateProductAndService' && isset($obj->edit_productandservice_id) && isset($obj->category_id) && isset($obj->serial_no)) {
    $edit_ps_id = $obj->edit_productandservice_id;
    $name   = trim($obj->productandservice_name);
    $price  = trim($obj->productandservice_price);
    $cat_id = trim($obj->category_id);
    $serial_no = trim($obj->serial_no);

    if (empty($name) || empty($price) || empty($cat_id) || empty($serial_no)) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Required fields missing"]]);
        exit;
    }
    if (!is_numeric($price) || $price <= 0) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid data"]]);
        exit;
    }

    // get numeric id
    $idStmt = $conn->prepare("SELECT id FROM productandservice WHERE productandservice_id = ? AND delete_at = 0");
    $idStmt->bind_param("s", $edit_ps_id);
    $idStmt->execute();
    $row = $idStmt->get_result()->fetch_assoc();
    $dbId = $row['id'] ?? 0;
    if (!$dbId) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Product & Service not found"]]);
        exit;
    }

    // check category
    $stmt = $conn->prepare("SELECT category_name FROM category WHERE category_id = ? AND delete_at = 0");
    $stmt->bind_param("s", $cat_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid category"]]);
        exit;
    }
    $cat_row = $result->fetch_assoc();
    $cat_name = $cat_row['category_name'];

    // uniqueness on name (ignore own record)
    $chk = $conn->prepare(
        "SELECT id FROM productandservice WHERE productandservice_name = ? AND id != ? AND delete_at = 0"
    );
    $chk->bind_param("si", $name, $dbId);
    $chk->execute();
    if ($chk->get_result()->num_rows) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Name already used"]]);
        exit;
    }

    // uniqueness on serial_no (ignore own record)
    $chk = $conn->prepare(
        "SELECT id FROM productandservice WHERE serial_number = ? AND id != ? AND delete_at = 0"
    );
    $chk->bind_param("si", $serial_no, $dbId);
    $chk->execute();
    if ($chk->get_result()->num_rows) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Serial number already used"]]);
        exit;
    }

    $upd = $conn->prepare(
        "UPDATE productandservice SET productandservice_name = ?, productandservice_price = ?, category_id = ?, category_name = ?, serial_number = ?
         WHERE productandservice_id = ?"
    );
    $upd->bind_param("sdssss", $name, $price, $cat_id, $cat_name, $serial_no, $edit_ps_id);
    if ($upd->execute()) {
        $output = ["head" => ["code" => 200, "msg" => "Product & Service updated successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Update error: " . $upd->error]];
    }
    echo json_encode($output);
    exit;
}

// ----------- 4. DELETE (soft)  -----------
elseif ($action === 'deleteProductAndService' && isset($obj->delete_productandservice_id)) {
    $delete_ps_id = $obj->delete_productandservice_id;   // numeric id
    $stmt = $conn->prepare("UPDATE productandservice SET delete_at = 1 WHERE id = ?");
    $stmt->bind_param("i", $delete_ps_id);
    if ($stmt->execute()) {
        $output = ["head" => ["code" => 200, "msg" => "Product & Service deleted successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Delete error: " . $stmt->error]];
    }
    echo json_encode($output);
    exit;
} else {
    $output = ["head" => ["code" => 400, "msg" => "Invalid action / parameters"]];
}

echo json_encode($output, JSON_NUMERIC_CHECK);
