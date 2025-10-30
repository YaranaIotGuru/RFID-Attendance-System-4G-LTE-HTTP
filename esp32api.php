<?php
// Enhanced RFID Attendance System - PURE API for IoT Data Logging
header('Content-Type: application/json');

// Database credentials
$servername = "localhost";
$username = "yourusername";
$password = "yourpassword";
$dbname = "yourdatabase";


// Function to get database connection
function get_db_connection($servername, $username, $password, $dbname) {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        error_log("DB Connection Failed: " . $conn->connect_error);
        return null;
    }
    return $conn;
}

// Function to safely receive data from POST requests (JSON or Form Data)
function get_post_data() {
    $input_data = file_get_contents('php://input');
    $json_data = json_decode($input_data, true);
    
    if ($json_data) {
        return $json_data;
    }
    return $_POST;
}

// --- 1. HANDLE POST REQUEST (STORE NEW ATTENDANCE LOG with minimal data) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $data = get_post_data();
    
    // Check for the *only* required field: tag or tag_id
    if (!isset($data['tag']) && !isset($data['tag_id'])) {
        echo json_encode(["success" => false, "error" => "Required field 'tag' or 'tag_id' is missing."]);
        exit();
    }
    
    $tag = isset($data['tag']) ? $data['tag'] : $data['tag_id'];
    
    // --- Auto-generate / Default Values ---
    $device_id = isset($data['device_id']) ? $data['device_id'] : 'IOT_DEVICE_DEFAULT'; // If device_id is not passed, use a default
    $signal_strength = isset($data['signal']) ? (int)$data['signal'] : null;           // Null if not passed
    $battery_level = isset($data['battery']) ? (int)$data['battery'] : null;           // Null if not passed
    
    $conn = get_db_connection($servername, $username, $password, $dbname);
    if ($conn === null) {
        die(json_encode(["success" => false, "error" => "Connection failed."]));
    }
    
    try {
        // Step A: Determine IN/OUT Attendance Type based on last record
        $attendance_type = 'IN';
        $stmt_check = $conn->prepare("SELECT attendance_type FROM attendance_logs WHERE tag_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_check->bind_param("s", $tag);
        $stmt_check->execute();
        $result = $stmt_check->get_result();
        
        if ($result->num_rows > 0) {
            $last_record = $result->fetch_assoc();
            // Toggle logic: If last was IN, next is OUT. If last was OUT, next is IN.
            $attendance_type = ($last_record['attendance_type'] === 'IN') ? 'OUT' : 'IN';
        }
        $stmt_check->close();
        
        // Step B: Insert the new attendance log
        // scan_time is automatically handled by MySQL TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        $sql = "INSERT INTO attendance_logs (tag_id, device_id, attendance_type, signal_strength, battery_level) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        // sssii: string, string, string, integer, integer (for signal/battery which might be NULL or integer)
        // Note: For fields that might be NULL, the integer binding 'i' in bind_param is correct if the data type is INT in MySQL.
        $stmt->bind_param("sssii", $tag, $device_id, $attendance_type, $signal_strength, $battery_level);
        
        if ($stmt->execute()) {
            echo json_encode([
                "success" => true,
                "message" => "Attendance logged successfully",
                "tag_id" => $tag,
                "attendance_type" => $attendance_type,
                "device_id" => $device_id // Echoing back auto-generated/default value
            ]);
        } else {
            echo json_encode(["success" => false, "error" => "Database insert error: " . $stmt->error]);
        }
        $stmt->close();
        
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "Server error: " . $e->getMessage()]);
    }
    $conn->close();
    exit();
}

// --- 2. HANDLE GET REQUEST (RETRIEVE ALL ATTENDANCE LOGS) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    $conn = get_db_connection($servername, $username, $password, $dbname);
    if ($conn === null) {
        die(json_encode(["success" => false, "error" => "Connection failed."]));
    }
    
    try {
        // Fetch all fields, latest first
        $sql = "SELECT id, tag_id, device_id, attendance_type, scan_time, signal_strength, battery_level 
                FROM attendance_logs 
                ORDER BY scan_time DESC LIMIT 100";
        
        $result = $conn->query($sql);
        $response = [];
        
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $response[] = [
                    "id" => (int)$row["id"],
                    "tag_id" => htmlspecialchars($row["tag_id"]),
                    "device_id" => htmlspecialchars($row["device_id"]),
                    "attendance_type" => $row["attendance_type"],
                    "scan_time" => $row["scan_time"], // MySQL timestamp format
                    "signal_strength" => ($row["signal_strength"] === null) ? 'N/A' : (int)$row["signal_strength"],
                    "battery_level" => ($row["battery_level"] === null) ? 'N/A' : (int)$row["battery_level"]
                ];
            }
            echo json_encode(["success" => true, "data" => $response, "count" => count($response)]);
        } else {
            echo json_encode(["success" => true, "data" => [], "message" => "No records found"]);
        }
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "Database query error: " . $e->getMessage()]);
    }
    
    $conn->close();
    exit();
}

// --- 3. HANDLE UNSUPPORTED REQUESTS ---
echo json_encode(["success" => false, "error" => "Unsupported request method or no action specified."]);
// $conn->close() is not needed here as it was already called or exited earlier.
?>