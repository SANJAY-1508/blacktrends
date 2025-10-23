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

// <<<<<<<<<<===================== List Users =====================>>>>>>>>>>
if (!isset($obj->action)) {
    echo json_encode([
        "head" => ["code" => 400, "msg" => "Action parameter is missing"]
    ]);
    exit();
}

$action = $obj->action;

if ($action === 'listUsers') {
    $search_text = isset($obj->search_text) ? $obj->search_text : '';
    $stmt = $conn->prepare("SELECT * FROM `users` WHERE `deleted_at` = 0 AND `Name` LIKE ? ORDER BY `id` DESC");
    $search_text = '%' . $search_text . '%';
    $stmt->bind_param("s", $search_text);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $output = [
            "head" => ["code" => 200, "msg" => "Success"],
            "body" => ["users" => $users]
        ];
    } else {
        $output = [
            "head" => ["code" => 200, "msg" => "User Details Not Found"],
            "body" => ["users" => []]
        ];
    }
}


// Check if the action is 'addusers'
elseif ($action === 'addusers' && isset($obj->Name) && isset($obj->Mobile_Number) && isset($obj->Password)) {
    // Assign values from the object
    $Name = $obj->Name;
    $Mobile_Number = $obj->Mobile_Number;
    $Password = $obj->Password;


    // Validate Required Fields
    if (!empty($Name) && !empty($Mobile_Number) && !empty($Password)) {

        // Validate Mobile Number (Numeric and exactly 10 digits)
        if (is_numeric($Mobile_Number) && strlen($Mobile_Number) == 10) {
            // Prepare statement to check if Mobile Number already exists
            $stmt = $conn->prepare("SELECT * FROM `users` WHERE `Mobile_Number` = ? AND deleted_at = 0");
            $stmt->bind_param("s", $Mobile_Number);
            $stmt->execute();
            $mobileCheck = $stmt->get_result();

            if ($mobileCheck->num_rows == 0) {
                // Prepare statement to insert the new user
                $stmtInsert = $conn->prepare("INSERT INTO `users` (`Name`, `Mobile_Number`, `Password`, `created_at_datetime`, `deleted_at`) 
                                                  VALUES (?, ?, ?,NOW(), 0)");
                $stmtInsert->bind_param("sss", $Name, $Mobile_Number, $Password);

                if ($stmtInsert->execute()) {
                    $insertId = $stmtInsert->insert_id;

                    // Generate unique user ID using the insert ID
                    $user_id = uniqueID("user", $insertId); // Assuming uniqueID function is available

                    // Prepare statement to update the user ID
                    $stmtUpdate = $conn->prepare("UPDATE `users` SET `user_id` = ? WHERE `id` = ?");
                    $stmtUpdate->bind_param("si", $user_id, $insertId);

                    if ($stmtUpdate->execute()) {
                        $stmt = $conn->prepare("SELECT * FROM `users` WHERE `deleted_at` = 0 AND id = $insertId  ORDER BY `id` DESC");
                        $stmt->execute();
                        $result = $stmt->get_result();

                        if ($result->num_rows > 0) {
                            $users = $result->fetch_all(MYSQLI_ASSOC);
                        }
                        $output = ["head" => ["code" => 200, "msg" => "User Created Successfully", "users" => $users]];
                    } else {
                        $output = ["head" => ["code" => 400, "msg" => "Failed to Update User ID"]];
                    }
                    $stmtUpdate->close();
                } else {
                    $output = ["head" => ["code" => 400, "msg" => "Failed to Create User. Error: " . $stmtInsert->error]];
                }
                $stmtInsert->close();
            } else {
                $output = ["head" => ["code" => 400, "msg" => "Mobile Number Already Exists"]];
            }
            $stmt->close();
        } else {
            $output = ["head" => ["code" => 400, "msg" => "Invalid Mobile Number"]];
        }
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Please provide all required details"]];
    }

    // Send JSON response
    echo json_encode($output);
    exit;
}




// Check if the action is 'editusers'
elseif ($action === 'updateuser' && isset($obj->user_id) && isset($obj->Name) && isset($obj->Mobile_Number) && isset($obj->Password)) {
    // Extract user data
    $user_id = $obj->user_id;
    $Name = $obj->Name;
    $Mobile_Number = $obj->Mobile_Number;
    $Password = $obj->Password;


    // Validate Required Fields
    if (!empty($Name) && !empty($Mobile_Number) && !empty($Password)) {

        // Validate Mobile Number (Numeric and exactly 10 digits)
        if (is_numeric($Mobile_Number) && strlen($Mobile_Number) == 10) {
            // Update User
            $updateUser = "UPDATE `users` SET 
                               `Name` = '$Name', 
                               `Mobile_Number` = '$Mobile_Number', 
                               `Password` = '$Password'
                               WHERE `id` = '$user_id'";

            if ($conn->query($updateUser)) {
                $output = ["head" => ["code" => 200, "msg" => "User Details Updated Successfully", "id" => $user_id]];
            } else {
                // Log the SQL error and return it
                error_log("SQL Error: " . $conn->error);
                $output = ["head" => ["code" => 400, "msg" => "Failed to Update User. Error: " . $conn->error]];
            }
        } else {
            $output = ["head" => ["code" => 400, "msg" => "Invalid Mobile Number"]];
        }
    } else {
        $output = ["head" => ["code" => 400, "msg" => "Please provide all required details"]];
    }

    // Return the JSON response
    echo json_encode($output);
    exit;
}




// <<<<<<<<<<===================== Delete User =====================>>>>>>>>>>
elseif ($action === "deleteUsers") {
    $delete_user_id = $obj->delete_user_id ?? null;

    if (!empty($delete_user_id)) {
        $deleteUserQuery = "UPDATE `users` SET `deleted_at` = 1 WHERE `id` = ?";
        $stmt = $conn->prepare($deleteUserQuery);
        $stmt->bind_param("i", $delete_user_id);

        if ($stmt->execute()) {
            $output = ["head" => [
                "code" => 200,
                "msg" => "User Deleted Successfully"
            ]];
        } else {
            $output = ["head" => ["code" => 400, "msg" => "Failed to Delete User"]];
        }
        $stmt->close();
    } else {
        $output = ["head" => [
            "code" => 400,
            "msg" => "Please provide all required details"
        ]];
    }
} elseif ($action === "login") {
    // Extract login details from request
    $Mobile_Number = $obj->Mobile_Number ?? '';
    $Password = $obj->Password ?? '';

    // Validate inputs
    if (empty($Mobile_Number) || empty($Password)) {
        $output = [
            "head" => [
                "code" => 400,
                "msg" => "Please provide both mobile number and password"
            ]
        ];
    } else {
        // Prepare the query to verify credentials
        $stmt = $conn->prepare("SELECT * FROM `users` WHERE `Mobile_Number` = ? AND `Password` = ? AND `deleted_at` = 0");
        $stmt->bind_param("ss", $Mobile_Number, $Password);

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                // Credentials are valid
                $user = $result->fetch_assoc();
                $output = [
                    "head" => [
                        "code" => 200,
                        "msg" => "Login Successful"
                    ],
                    "body" => [
                        "user_id" => $user['user_id'],
                        "name" => $user['Name'],
                        "mobile_number" => $user['Mobile_Number']
                    ]
                ];
            } else {
                // Invalid credentials
                $output = [
                    "head" => [
                        "code" => 401,
                        "msg" => "Invalid credentials"
                    ]
                ];
            }
        } else {
            // Database query failed
            $output = [
                "head" => [
                    "code" => 500,
                    "msg" => "An error occurred while processing the request"
                ]
            ];
            error_log("Database query error: " . $stmt->error);
        }

        $stmt->close();
    }

    echo json_encode($output);
    exit;
} else {
    $output = [
        "head" => ["code" => 400, "msg" => "Invalid Parameters"],
        "inputs" => $obj
    ];
}

echo json_encode($output, JSON_NUMERIC_CHECK);
