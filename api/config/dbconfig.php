<?php
$name = "localhost";
$username = "root";
$password = "";
$database = "blacktrends";

// Establishing the connection
$conn = new mysqli($name, $username, $password, $database);

// Check for connection errors
if ($conn->connect_error) {
    $output = array();
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "DB Connection Lost: " . $conn->connect_error;

    echo json_encode($output, JSON_NUMERIC_CHECK);
    exit();
}

// Function to check if a string contains only numbers
function numericCheck($data)
{
    return preg_match('/^\d+$/', $data) === 1;
}

// Function to generate a unique ID
function uniqueID($prefix_name, $auto_increment_id)
{
    date_default_timezone_set('Asia/Calcutta');
    $timestamp = date('YmdHis');
    $encryptId = $prefix_name . "_" . $timestamp . "_" . $auto_increment_id;
    return md5($encryptId);
}

// Function to generate unique member_no
function generateMemberNo($insertId)
{
    return "Member_" . sprintf("%03d", $insertId);
}

// ---------------- Helper Functions ----------------

function updateStaffTotals($conn, $details, $operation)
{
    if (empty($details)) return false;
    $parsedDetails = json_decode($details, true);
    if (!is_array($parsedDetails)) return false;
    $multiplier = ($operation === 'add') ? 1 : -1;

    foreach ($parsedDetails as $item) {
        if (isset($item['staff_id']) && $item['staff_id'] !== null && $item['staff_id'] !== '' && isset($item['total'])) {
            $staff_id = $item['staff_id'];
            $amount = floatval($item['total']) * $multiplier;
            $stmt = $conn->prepare("UPDATE staff SET total = total + ? WHERE staff_id = ? AND delete_at = 0");
            $stmt->bind_param("ds", $amount, $staff_id);
            $stmt->execute();
        }
    }
    return true;
}

function updateMemberTotals($conn, $member_id_str)
{
    if (empty($member_id_str)) return false;

    // Use member_id string in WHERE
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total_visit_count,
            COALESCE(SUM(total), 0) AS total_spending,
            MAX(billing_date) AS last_visit_date
        FROM billing
        WHERE member_id = ? AND delete_at = 0
    ");
    $stmt->bind_param("s", $member_id_str);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    $total_visit_count = intval($result['total_visit_count']);
    $total_spending = floatval($result['total_spending']);
    $last_visit_date = $result['last_visit_date'] ?? null;

    // Update member table using member.member_id (string)
    $upd = $conn->prepare("
        UPDATE member
        SET last_visit_date = ?, total_visit_count = ?, total_spending = ?
        WHERE member_id = ? AND delete_at = 0
    ");

    $upd->bind_param("sids", $last_visit_date, $total_visit_count, $total_spending, $member_id_str);
    return $upd->execute();
}
