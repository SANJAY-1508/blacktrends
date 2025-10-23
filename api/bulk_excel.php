<?php
ob_start(); // Buffer output to allow headers even if warnings occur

include 'config/dbconfig.php'; // DB config (defines $conn and functions)

// Show all errors for debugging (remove in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Composer autoload (ensure composer install ran)
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// CORS headers (set early)
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit();
}

// Flush any buffered output before processing
ob_end_clean();

try {
    // Safeguard: Check if functions exist (in case dbconfig.php changes)
    if (!function_exists('uniqueID') || !function_exists('generateMemberNo')) {
        throw new Exception("Required functions (uniqueID, generateMemberNo) not found in dbconfig.php");
    }

    // Check file
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("No valid file uploaded. Error: " . ($_FILES['file']['error'] ?? 'Unknown'));
    }

    // Basic file validation
    $fileType = mime_content_type($_FILES['file']['tmp_name']);
    if (!in_array($fileType, ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])) {
        throw new Exception("Invalid file type. Only .xls and .xlsx allowed.");
    }

    if ($_FILES['file']['size'] > 5 * 1024 * 1024) { // 5MB limit
        throw new Exception("File too large (max 5MB).");
    }

    $filePath = $_FILES['file']['tmp_name'];

    $spreadsheet = IOFactory::load($filePath);
    $sheetNames = $spreadsheet->getSheetNames();
    $insertCount = 0;
    $totalRows = 0;
    $skippedRows = 0;
    $duplicateRows = 0;
    $insertFailed = 0;
    $updatedRows = 0; // For updates on duplicates

    foreach ($sheetNames as $sheetIndex => $sheetName) {
        error_log("Processing sheet: $sheetName"); // Log sheet name

        $sheet = $spreadsheet->getSheet($sheetIndex);
        $rows = $sheet->toArray();

        if (count($rows) < 2) {
            error_log("Sheet $sheetName has too few rows: " . count($rows));
            continue; // Need header + at least one data row
        }

        $sheetTotal = 0;
        $sheetSkipped = 0;
        $sheetDup = 0;
        $sheetInsertFail = 0;
        $sheetUpdated = 0;

        // Loop through data rows (header at index 0, data starts at index 1)
        for ($i = 1; $i < count($rows); $i++) {
            $totalRows++;
            $sheetTotal++;

            $name = isset($rows[$i][0]) ? trim($rows[$i][0]) : ''; // Column A: CUSTOMER NAME (index 0)
            $phone = isset($rows[$i][1]) ? preg_replace('/[^0-9]/', '', $rows[$i][1]) : ''; // Column B: PHONE NUMBER (index 1)

            if (empty($name) || empty($phone) || strlen($phone) !== 10) {
                $skippedRows++;
                $sheetSkipped++;
                // error_log("Skipped row $i in sheet $sheetName: name='$name', phone='$phone' (len=" . strlen($phone) . ")");
                continue;
            }

            // Check if phone exists first (to handle member_id/member_no generation for new inserts)
            $check = $conn->prepare("SELECT id FROM member WHERE phone = ? AND delete_at = 0");
            if (!$check) {
                throw new Exception("Database prepare error: " . $conn->error);
            }
            $check->bind_param("s", $phone);
            $check->execute();
            $result = $check->get_result();
            $exists = $result->num_rows > 0;
            $check->close();

            if ($exists) {
                // Update existing
                $updateStmt = $conn->prepare("UPDATE member SET name = ? WHERE phone = ? AND delete_at = 0");
                if (!$updateStmt) {
                    throw new Exception("Database prepare error: " . $conn->error);
                }
                $updateStmt->bind_param("ss", $name, $phone);
                if ($updateStmt->execute() && $updateStmt->affected_rows > 0) {
                    $updatedRows++;
                    $sheetUpdated++;
                }
                $updateStmt->close();
                continue;
            }

            // Insert new
            $insertStmt = $conn->prepare("INSERT INTO member (name, phone, membership, membership_activated_at, create_at, delete_at) VALUES (?, ?, 'No', NULL, NOW(), 0)");
            if (!$insertStmt) {
                throw new Exception("Database prepare error: " . $conn->error);
            }
            $insertStmt->bind_param("ss", $name, $phone);

            if ($insertStmt->execute()) {
                $insertId = $conn->insert_id;
                $insertStmt->close();

                // Generate and update member_id/member_no
                $member_id = uniqueID("member", $insertId);
                $member_no = generateMemberNo($insertId);

                $upd = $conn->prepare("UPDATE member SET member_id = ?, member_no = ? WHERE id = ?");
                if ($upd) {
                    $upd->bind_param("ssi", $member_id, $member_no, $insertId);
                    if ($upd->execute()) {
                        $insertCount++;
                    } else {
                        error_log("Update failed for new ID $insertId: " . $upd->error);
                        $insertCount++; // Still count as inserted
                    }
                    $upd->close();
                } else {
                    error_log("Update prepare failed for new ID $insertId: " . $conn->error);
                    $insertCount++;
                }
            } else {
                $insertFailed++;
                $sheetInsertFail++;
                error_log("Insert failed for $name ($phone): " . $insertStmt->error);
                $insertStmt->close();
            }
        }

        error_log("Sheet $sheetName: Total rows $sheetTotal, Skipped $sheetSkipped, Updated $sheetUpdated, Insert failed $sheetInsertFail");
    }

    // Log summary
    error_log("Bulk upload summary: Total rows $totalRows, Skipped $skippedRows, Successful inserts $insertCount, Updates $updatedRows, Insert failed $insertFailed");

    // Always output JSON with more details
    header('Content-Type: application/json');
    echo json_encode([
        "status" => "success",
        "inserted" => $insertCount,
        "updated" => $updatedRows,
        "total_rows" => $totalRows,
        "skipped" => $skippedRows,
        "insert_failed" => $insertFailed
    ]);
} catch (Exception $e) {
    error_log("Bulk upload error: " . $e->getMessage()); // Log to server log
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
} catch (Throwable $e) { // Catch fatal errors too
    error_log("Fatal bulk upload error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
}
