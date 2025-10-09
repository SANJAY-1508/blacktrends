<?php

include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:3000"); // Allow only your React app
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE"); // Allow HTTP methods
header("Access-Control-Allow-Headers: Content-Type, Authorization"); // Allow headers
header("Access-Control-Allow-Credentials: true"); // If needed for cookies/auth

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

// <<<<<<<<<<===================== List Categories =====================>>>>>>>>>>
if (!isset($obj->action)) {
    echo json_encode([
        "head" => ["code" => 400, "msg" => "Action parameter is missing"]
    ]);
    exit();
}

$action = $obj->action;

if ($action === 'listCategories') {
    $search_text = isset($obj->search_text) ? $obj->search_text : '';
    $stmt = $conn->prepare("SELECT * FROM `category` WHERE `delete_at` = 0 AND `category_name` LIKE ? ORDER BY `id` DESC");
    $search_text = '%' . $search_text . '%';
    $stmt->bind_param("s", $search_text);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $categories = $result->fetch_all(MYSQLI_ASSOC);
        $output = [
            "head" => ["code" => 200, "msg" => "Success"],
            "body" => ["categories" => $categories]
        ];
    } else {
        $output = [
            "head" => ["code" => 200, "msg" => "Category Details Not Found"],
            "body" => ["categories" => []]
        ];
    }
}

// Check if the action is 'addCategory'
elseif ($action === 'addCategory' && isset($obj->category_name)) {
    // Assign values from the object
    $category_name = $obj->category_name;

    // Validate Required Fields
    if (!empty($category_name)) {
        // Validate category_name (Alphanumeric, spaces, dots, and commas allowed)
        if (preg_match('/^[a-zA-Z0-9., ]+$/', $category_name)) {
            // Prepare statement to check if category_name already exists
            $stmt = $conn->prepare("SELECT * FROM `category` WHERE `category_name` = ? AND delete_at = 0");
            $stmt->bind_param("s", $category_name);
            $stmt->execute();
            $nameCheck = $stmt->get_result();

            if ($nameCheck->num_rows == 0) {
                // Prepare statement to insert the new category
                $stmtInsert = $conn->prepare("INSERT INTO `category` (`category_name`, `create_at`, `delete_at`) 
                                              VALUES (?, NOW(), 0)");
                $stmtInsert->bind_param("s", $category_name);

                if ($stmtInsert->execute()) {
                    $insertId = $stmtInsert->insert_id;

                    // Generate unique category ID using the insert ID
                    $category_id = uniqueID("category", $insertId); // Assuming uniqueID function is available

                    // Prepare statement to update the category ID
                    $stmtUpdate = $conn->prepare("UPDATE `category` SET `category_id` = ? WHERE `id` = ?");
                    $stmtUpdate->bind_param("si", $category_id, $insertId);

                    if ($stmtUpdate->execute()) {
                        $stmt = $conn->prepare("SELECT * FROM `category` WHERE `delete_at` = 0 AND id = ? ORDER BY `id` DESC");
                        $stmt->bind_param("i", $insertId);
                        $stmt->execute();
                        $result = $stmt->get_result();

                        if ($result->num_rows > 0) {
                            $categories = $result->fetch_all(MYSQLI_ASSOC);
                        }
                        $output = ["head" => ["code" => 200, "msg" => "Category Created Successfully", "categories" => $categories ?? []]];
                    } else {
                        $output = ["head" => ["code" => 400, "msg" => "Failed to Update Category ID"]];
                    }
                    $stmtUpdate->close();
                } else {
                    $output = ["head" => ["code" => 400, "msg" => "Failed to Create Category. Error: " . $stmtInsert->error]];
                }
                $stmtInsert->close();
            } else {
                $output = ["head" => ["code" => 400, "msg" => "Category Name Already Exists"]];
            }
            $stmt->close();
        } else {
            $output = ["head" => ["code" => 400, "msg" => "Category name should be alphanumeric and can include spaces, dots, and commas"]];
        }
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Please provide all required details"]];
    }

    // Send JSON response
    echo json_encode($output);
    exit;
}

// Check if the action is 'updateCategory'
elseif ($action === 'updateCategory' && isset($obj->category_id) && isset($obj->category_name)) {
    // Extract category data
    $category_id = $obj->category_id;
    $category_name = $obj->category_name;

    // Validate Required Fields
    if (!empty($category_name)) {
        // Validate category_name (Alphanumeric, spaces, dots, and commas allowed)
        if (preg_match('/^[a-zA-Z0-9., ]+$/', $category_name)) {
            // Update Category using prepared statement
            $stmt = $conn->prepare("UPDATE `category` SET `category_name` = ? WHERE `id` = ? AND `delete_at` = 0");
            $stmt->bind_param("si", $category_name, $category_id);

            if ($stmt->execute()) {
                $output = ["head" => ["code" => 200, "msg" => "Category Details Updated Successfully", "id" => $category_id]];
            } else {
                // Log the SQL error and return it
                error_log("SQL Error: " . $stmt->error);
                $output = ["head" => ["code" => 400, "msg" => "Failed to Update Category. Error: " . $stmt->error]];
            }
            $stmt->close();
        } else {
            $output = ["head" => ["code" => 400, "msg" => "Category name should be alphanumeric and can include spaces, dots, and commas"]];
        }
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Please provide all required details"]];
    }

    // Return the JSON response
    echo json_encode($output);
    exit;
}

// <<<<<<<<<<===================== Delete Category =====================>>>>>>>>>>
elseif ($action === "deleteCategory") {
    $delete_category_id = $obj->delete_category_id ?? null;

    if (!empty($delete_category_id)) {
        $deleteCategoryQuery = "UPDATE `category` SET `delete_at` = 1 WHERE `id` = ?";
        $stmt = $conn->prepare($deleteCategoryQuery);
        $stmt->bind_param("i", $delete_category_id);

        if ($stmt->execute()) {
            $output = ["head" => [
                "code" => 200,
                "msg" => "Category Deleted Successfully"
            ]];
        } else {
            $output = ["head" => ["code" => 400, "msg" => "Failed to Delete Category"]];
        }
        $stmt->close();
    } else {
        $output = ["head" => [
            "code" => 400,
            "msg" => "Please provide all required details"
        ]];
    }
} else {
    $output = [
        "head" => ["code" => 400, "msg" => "Invalid Parameters"],
        "inputs" => $obj
    ];
}

echo json_encode($output, JSON_NUMERIC_CHECK);
