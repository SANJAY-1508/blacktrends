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


function applyMilestoneDiscount($conn, $member_id_str, $product_details_array, &$extra_discount_rate, $membership, $timestamp, $this_billing_total = 0)
{
    if (empty($member_id_str)) {
        $extra_discount_rate = 0;
        return;
    }

    // Fetch current milestone
    $stmt = $conn->prepare("SELECT * FROM member_discount_milestone WHERE member_id = ?");
    $stmt->bind_param("s", $member_id_str);
    $stmt->execute();
    $milestone = $stmt->get_result()->fetch_assoc();

    $is_membership_yes = ($membership === 'Yes');

    $extra_discount_rate = 0;
    $now = $timestamp;

    if ($milestone && $milestone['is_active'] && $milestone['discount_expiry_date'] > $now) {
        // Active discount - apply extra rate (10%)
        $extra_discount_rate = $milestone['discount_rate'];  // Always 10
        return; // Don't accumulate during active period
    }

    // No active discount - accumulate this billing's total
    if (empty($this_billing_total)) {
        $this_billing_total = 0;
        foreach ($product_details_array as $row) {
            $this_billing_total += floatval($row['total'] ?? 0);
        }
    }

    // Update/Insert milestone
    if (!$milestone) {
        // Insert new with 0
        $ins = $conn->prepare("INSERT INTO member_discount_milestone (member_id, milestone_total) VALUES (?, 0)");
        $ins->bind_param("s", $member_id_str);
        $ins->execute();

        // Immediately update with this total (FIX: This was missing!)
        $milestone_total = $this_billing_total;
        $upd_new = $conn->prepare("UPDATE member_discount_milestone SET milestone_total = ? WHERE member_id = ?");
        $upd_new->bind_param("ds", $milestone_total, $member_id_str);
        $upd_new->execute();
    } else {
        $milestone_total = $milestone['milestone_total'] + $this_billing_total;
        $upd = $conn->prepare("UPDATE member_discount_milestone SET milestone_total = ? WHERE member_id = ?");
        $upd->bind_param("ds", $milestone_total, $member_id_str);
        $upd->execute();
    }

    // Check if reached 5000
    if ($milestone_total >= 5000) {
        $discount_rate = 10;  // Always extra 10% (frontend handles base 18% for Yes)

        $start_date = $now;
        $expiry_date = date('Y-m-d H:i:s', strtotime($start_date . ' + 3 months'));

        $upd_disc = $conn->prepare("UPDATE member_discount_milestone SET 
            discount_rate = ?, discount_start_date = ?, discount_expiry_date = ?, is_active = 1, milestone_total = 0 
            WHERE member_id = ?");
        $upd_disc->bind_param("dsss", $discount_rate, $start_date, $expiry_date, $member_id_str);
        $upd_disc->execute();

        $extra_discount_rate = $discount_rate;
    }
}
