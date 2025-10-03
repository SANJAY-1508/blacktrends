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
