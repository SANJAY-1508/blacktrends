<?php
include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

$action = $obj->action ?? 'listCompany';

if ($action === 'listCompany') {
    $search_text = isset($obj->search_text) ? $obj->search_text : '';
    $stmt = $conn->prepare("SELECT `id`, `company_id`, `company_name`, `contact_number`, `email`, `address`, `gst_no`, `create_at`, `delete_at` FROM `company` WHERE `delete_at` = 0 AND `company_name` LIKE ? ORDER BY `id` DESC");
    $search_text = '%' . $search_text . '%';
    $stmt->bind_param("s", $search_text);
    $stmt->execute();
    $result = $stmt->get_result();

    $body = ["company" => []];
    if ($result->num_rows > 0) {
        $body["company"] = $result->fetch_all(MYSQLI_ASSOC);
    }
    $output = [
        "head" => ["code" => 200, "msg" => $body["company"] ? "Success" : "Company Details Not Found"],
        "body" => $body
    ];
} elseif ($action === 'addcompany' && isset($obj->company_name) && isset($obj->contact_number)) {
    $company_name = $obj->company_name;
    $contact_number = $obj->contact_number;
    $email = $obj->email ?? '';
    $address = $obj->address ?? '';
    $gst_no = $obj->gst_no ?? '';

    if (!empty($company_name) && !empty($contact_number)) {
        if (preg_match('/^[a-zA-Z0-9., ]+$/', $company_name)) {
            if (is_numeric($contact_number) && strlen($contact_number) == 10) {
                $stmt = $conn->prepare("SELECT * FROM `company` WHERE `contact_number` = ? AND `delete_at` = 0");
                $stmt->bind_param("s", $contact_number);
                $stmt->execute();
                if ($stmt->get_result()->num_rows == 0) {
                    if (!empty($gst_no)) {
                        $gstStmt = $conn->prepare("SELECT * FROM `company` WHERE `gst_no` = ? AND `delete_at` = 0");
                        $gstStmt->bind_param("s", $gst_no);
                        $gstStmt->execute();
                        if ($gstStmt->get_result()->num_rows > 0) {
                            $output = ["head" => ["code" => 400, "msg" => "GST No Already Exists"]];
                            echo json_encode($output);
                            exit;
                        }
                    }

                    $stmtInsert = $conn->prepare("INSERT INTO `company` (`company_name`, `contact_number`, `email`, `address`, `gst_no`, `create_at`, `delete_at`) VALUES (?, ?, ?, ?, ?, NOW(), 0)");
                    $stmtInsert->bind_param("ssssss", $company_name, $contact_number, $email, $address, $gst_no);
                    if ($stmtInsert->execute()) {
                        $insertId = $stmtInsert->insert_id;
                        $company_id = uniqueID("company", $insertId);  // Assuming uniqueID function
                        $stmtUpdate = $conn->prepare("UPDATE `company` SET `company_id` = ? WHERE `id` = ?");
                        $stmtUpdate->bind_param("si", $company_id, $insertId);
                        $stmtUpdate->execute();
                        $output = ["head" => ["code" => 200, "msg" => "Company Created Successfully"]];
                    } else {
                        $output = ["head" => ["code" => 400, "msg" => "Failed to Create Company: " . $stmtInsert->error]];
                    }
                } else {
                    $output = ["head" => ["code" => 400, "msg" => "Contact Number Already Exists"]];
                }
            } else {
                $output = ["head" => ["code" => 400, "msg" => "Invalid Contact Number"]];
            }
        } else {
            $output = ["head" => ["code" => 400, "msg" => "Invalid Company Name"]];
        }
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Required fields missing"]];
    }
    echo json_encode($output);
    exit;
} elseif ($action === 'updatecompany' && isset($obj->edit_company_id)) {
    $edit_company_id = $obj->edit_company_id;
    $company_name = $obj->company_name;
    $contact_number = $obj->contact_number;
    $email = $obj->email ?? '';
    $address = $obj->address ?? '';
    $gst_no = $obj->gst_no ?? '';

    if (!empty($company_name) && !empty($contact_number)) {
        if (preg_match('/^[a-zA-Z0-9., ]+$/', $company_name) && is_numeric($contact_number) && strlen($contact_number) == 10) {
            // Get numeric id for check
            $idStmt = $conn->prepare("SELECT `id` FROM `company` WHERE `company_id` = ? AND `delete_at` = 0");
            $idStmt->bind_param("s", $edit_company_id);
            $idStmt->execute();
            $dbId = $idStmt->get_result()->fetch_assoc()['id'] ?? 0;

            $checkStmt = $conn->prepare("SELECT * FROM `company` WHERE (`contact_number` = ? OR `gst_no` = ?) AND `id` != ? AND `delete_at` = 0");
            $checkStmt->bind_param("ssi", $contact_number, $gst_no, $dbId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows == 0) {
                $updateStmt = $conn->prepare("UPDATE `company` SET `company_name` = ?, `contact_number` = ?, `email` = ?, `address` = ?, `gst_no` = ? WHERE `company_id` = ?");
                $updateStmt->bind_param("ssssss", $company_name, $contact_number, $email, $address, $gst_no, $edit_company_id);
                if ($updateStmt->execute()) {
                    $output = ["head" => ["code" => 200, "msg" => "Company Updated Successfully"]];
                } else {
                    $output = ["head" => ["code" => 400, "msg" => "Failed to Update Company: " . $updateStmt->error]];
                }
            } else {
                $output = ["head" => ["code" => 400, "msg" => "Contact Number or GST No Already Exists"]];
            }
        } else {
            $output = ["head" => ["code" => 400, "msg" => "Invalid fields"]];
        }
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Required fields missing"]];
    }
    echo json_encode($output);
    exit;
} elseif ($action === 'deleteCompany' && isset($obj->delete_company_id)) {
    $delete_company_id = $obj->delete_company_id;
    $stmt = $conn->prepare("UPDATE `company` SET `delete_at` = 1 WHERE `id` = ?");
    $stmt->bind_param("i", $delete_company_id);
    if ($stmt->execute()) {
        $output = ["head" => ["code" => 200, "msg" => "Company Deleted Successfully"]];
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Failed to Delete Company: " . $stmt->error]];
    }
} else {
    $output = ["head" => ["code" => 400, "msg" => "Invalid Parameters"]];
}

echo json_encode($output, JSON_NUMERIC_CHECK);
