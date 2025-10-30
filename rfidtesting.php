<?php
// Enhanced RFID Attendance System by Yarana IoT Guru
// Database credentials
$servername = "localhost";
$username = "yourusername";
$password = "yourpassword";
$dbname = "yourdatabase";

// Function to get database connection
function get_db_connection($servername, $username, $password, $dbname) {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        return null; 
    }
    return $conn;
}

// Global Response Header
if (!isset($_GET['action'])) {
    // Only set JSON header for API calls, not for serving HTML
} else {
    header('Content-Type: application/json');
}

// --- API Endpoints ---

// Handle ESP32 POST requests (Store RFID attendance data)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'register_user')) {
    try {
        $conn = get_db_connection($servername, $username, $password, $dbname);
        if ($conn === null) {
            die(json_encode(["success" => false, "error" => "Connection failed: " . mysqli_connect_error()]));
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (isset($data['tag']) || isset($data['tag_id'])) {
            $tag = isset($data['tag']) ? $data['tag'] : $data['tag_id'];
            $device_id = isset($data['device_id']) ? $data['device_id'] : 'ESP32_001';
            $signal_strength = isset($data['signal']) ? (int)$data['signal'] : null;
            $battery_level = isset($data['battery']) ? (int)$data['battery'] : null;
            
            // Check last entry to determine IN/OUT
            $attendance_type = 'IN';
            $stmt_check = $conn->prepare("SELECT attendance_type, scan_time FROM attendance_logs WHERE tag_id = ? ORDER BY id DESC LIMIT 1");
            $stmt_check->bind_param("s", $tag);
            $stmt_check->execute();
            $result = $stmt_check->get_result();
            
            if ($result->num_rows > 0) {
                $last_record = $result->fetch_assoc();
                // Simple IN/OUT toggle:
                $attendance_type = ($last_record['attendance_type'] === 'IN') ? 'OUT' : 'IN';
            }
            $stmt_check->close();
            
            // Insert attendance record
            $stmt = $conn->prepare("INSERT INTO attendance_logs (tag_id, device_id, attendance_type, signal_strength, battery_level) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssii", $tag, $device_id, $attendance_type, $signal_strength, $battery_level);
            
            if ($stmt->execute()) {
                $response = [
                    "success" => true,
                    "message" => "Attendance recorded successfully",
                    "tag_id" => $tag,
                    "attendance_type" => $attendance_type
                ];
                
                $user_stmt = $conn->prepare("SELECT name, employee_id FROM users WHERE tag_id = ?");
                $user_stmt->bind_param("s", $tag);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                
                if ($user_result->num_rows > 0) {
                    $user = $user_result->fetch_assoc();
                    $response["user_name"] = $user['name'];
                }
                $user_stmt->close();
                
                echo json_encode($response);
            } else {
                echo json_encode(["success" => false, "error" => "Error recording attendance: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(["success" => false, "error" => "Invalid data: 'tag' or 'tag_id' missing"]);
        }
        
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX requests for attendance data
if (isset($_GET['action']) && $_GET['action'] === 'fetch_attendance') {
    try {
        $conn = get_db_connection($servername, $username, $password, $dbname);
        if ($conn === null) {
            echo json_encode(["error" => "Connection failed: " . mysqli_connect_error()]);
            exit();
        }
        
        $date_filter = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        $sql = "SELECT a.id, a.tag_id, a.attendance_type, a.scan_time, a.device_id, a.signal_strength, a.battery_level,
                        u.name, u.employee_id, u.department, u.designation 
                FROM attendance_logs a
                LEFT JOIN users u ON a.tag_id = u.tag_id
                WHERE DATE(a.scan_time) = ?
                ORDER BY a.id DESC LIMIT 200";
                
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $date_filter);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $response = [];
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $response[] = [
                    "id" => (int)$row["id"],
                    "tag_id" => htmlspecialchars($row["tag_id"]),
                    "attendance_type" => $row["attendance_type"],
                    "scan_time" => $row["scan_time"],
                    "device_id" => htmlspecialchars($row["device_id"]),
                    "signal_strength" => $row["signal_strength"],
                    "battery_level" => $row["battery_level"],
                    "user_name" => htmlspecialchars($row["name"] ?? 'Unregistered'),
                    "employee_id" => htmlspecialchars($row["employee_id"] ?? ''),
                    "department" => htmlspecialchars($row["department"] ?? ''),
                    "designation" => htmlspecialchars($row["designation"] ?? '')
                ];
            }
        }
        
        echo json_encode(["success" => true, "data" => $response, "count" => count($response), "date" => $date_filter]);
        $stmt->close();
        $conn->close();
        
    } catch (Exception $e) {
        echo json_encode(["error" => "Database error: " . $e->getMessage()]);
    }
    exit();
}

// Handle real-time attendance updates
if (isset($_GET['action']) && $_GET['action'] === 'check_attendance_updates') {
    $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
    
    try {
        $conn = get_db_connection($servername, $username, $password, $dbname);
        if ($conn === null) {
            echo json_encode(["error" => "Connection failed: " . mysqli_connect_error()]);
            exit();
        }
        
        $sql = "SELECT a.id, a.tag_id, a.attendance_type, a.scan_time, a.device_id, a.signal_strength, a.battery_level,
                        u.name, u.employee_id, u.department, u.designation
                FROM attendance_logs a 
                LEFT JOIN users u ON a.tag_id = u.tag_id
                WHERE a.id > ? AND DATE(a.scan_time) = CURDATE() ORDER BY a.id DESC";
                
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $last_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $new_records = [];
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $new_records[] = [
                    "id" => (int)$row["id"],
                    "tag_id" => htmlspecialchars($row["tag_id"]),
                    "attendance_type" => $row["attendance_type"],
                    "scan_time" => $row["scan_time"],
                    "device_id" => htmlspecialchars($row["device_id"]),
                    "signal_strength" => $row["signal_strength"],
                    "battery_level" => $row["battery_level"],
                    "user_name" => htmlspecialchars($row["name"] ?? 'Unregistered'),
                    "employee_id" => htmlspecialchars($row["employee_id"] ?? ''),
                    "department" => htmlspecialchars($row["department"] ?? ''),
                    "designation" => htmlspecialchars($row["designation"] ?? '')
                ];
            }
        }
        
        echo json_encode([
            "success" => true, 
            "new_records" => $new_records, 
            "has_updates" => count($new_records) > 0
        ]);
        
        $stmt->close();
        $conn->close();
        
    } catch (Exception $e) {
        echo json_encode(["error" => "Database error: " . $e->getMessage()]);
    }
    exit();
}

// Handle user registration/assignment
if (isset($_POST['action']) && $_POST['action'] === 'register_user') {
    if (!isset($_POST['tag_id'], $_POST['name'], $_POST['employee_id'])) {
        echo json_encode(["success" => false, "error" => "Missing required fields"]);
        exit();
    }
    
    $tag_id = $_POST['tag_id'];
    $name = $_POST['name'];
    $employee_id = $_POST['employee_id'];
    $department = isset($_POST['department']) ? $_POST['department'] : null;
    $designation = isset($_POST['designation']) ? $_POST['designation'] : null;
    $phone = isset($_POST['phone']) ? $_POST['phone'] : null;
    $email = isset($_POST['email']) ? $_POST['email'] : null;
    
    try {
        $conn = get_db_connection($servername, $username, $password, $dbname);
        if ($conn === null) {
            echo json_encode(["success" => false, "error" => "Connection failed"]);
            exit();
        }
        
        // Use INSERT INTO ... ON DUPLICATE KEY UPDATE (UPSERT logic)
        $sql = "INSERT INTO users (tag_id, name, employee_id, department, designation, phone, email) 
                VALUES (?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                name = VALUES(name), employee_id = VALUES(employee_id), 
                department = VALUES(department), designation = VALUES(designation),
                phone = VALUES(phone), email = VALUES(email)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssss", $tag_id, $name, $employee_id, $department, $designation, $phone, $email);
        
        if ($stmt->execute()) {
            $message = ($stmt->affected_rows > 1) ? "Employee updated successfully" : "Employee registered successfully";
            echo json_encode(["success" => true, "message" => $message]);
        } else {
            // Check for duplicate employee_id error (if you set unique constraint in MySQL)
            if ($conn->errno == 1062 && strpos($conn->error, 'employee_id') !== false) {
                 echo json_encode(["success" => false, "error" => "Registration failed: Employee ID '$employee_id' already exists."]);
            } else {
                echo json_encode(["success" => false, "error" => "Registration failed: " . $stmt->error]);
            }
        }
        
        $stmt->close();
        $conn->close();
        
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "Server error: " . $e->getMessage()]);
    }
    exit();
}

// Handle attendance statistics
if (isset($_GET['action']) && $_GET['action'] === 'get_attendance_stats') {
    try {
        $conn = get_db_connection($servername, $username, $password, $dbname);
        if ($conn === null) {
            echo json_encode(["error" => "Connection failed"]);
            exit();
        }
        
        $date_filter = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        
        // Total attendance records today
        $total_result = $conn->query("SELECT COUNT(*) as total FROM attendance_logs WHERE DATE(scan_time) = '$date_filter'");
        $total_attendance = $total_result->fetch_assoc()['total'];
        
        // Unique employees present today (based on IN check)
        $present_result = $conn->query("SELECT COUNT(DISTINCT tag_id) as present FROM attendance_logs WHERE DATE(scan_time) = '$date_filter' AND attendance_type = 'IN'");
        $employees_present = $present_result->fetch_assoc()['present'];
        
        // Total registered employees
        $registered_result = $conn->query("SELECT COUNT(*) as registered FROM users");
        $total_employees = $registered_result->fetch_assoc()['registered'];
        
        // Check-ins vs Check-outs today
        $checkin_result = $conn->query("SELECT COUNT(*) as checkins FROM attendance_logs WHERE DATE(scan_time) = '$date_filter' AND attendance_type = 'IN'");
        $total_checkins = $checkin_result->fetch_assoc()['checkins'];
        
        $checkout_result = $conn->query("SELECT COUNT(*) as checkouts FROM attendance_logs WHERE DATE(scan_time) = '$date_filter' AND attendance_type = 'OUT'");
        $total_checkouts = $checkout_result->fetch_assoc()['checkouts'];
        
        // Device status
        $device_result = $conn->query("SELECT device_id, COUNT(*) as scans, MAX(scan_time) as last_seen FROM attendance_logs WHERE DATE(scan_time) = '$date_filter' GROUP BY device_id");
        $devices = [];
        while ($row = $device_result->fetch_assoc()) {
            $devices[] = [
                "device_id" => $row['device_id'],
                "scans_today" => (int)$row['scans'],
                "last_seen" => $row['last_seen'],
                // Device is considered Online if last seen in the last 5 minutes (300 seconds)
                "status" => (strtotime($row['last_seen']) > (time() - 300)) ? "Online" : "Offline"
            ];
        }
        
        echo json_encode([
            "success" => true,
            "stats" => [
                "total_attendance_records" => (int)$total_attendance,
                "employees_present_today" => (int)$employees_present,
                "total_registered_employees" => (int)$total_employees,
                "total_checkins" => (int)$total_checkins,
                "total_checkouts" => (int)$total_checkouts,
                "attendance_percentage" => $total_employees > 0 ? round(($employees_present / $total_employees) * 100, 1) : 0
            ],
            "devices" => $devices,
            "date" => $date_filter
        ]);
        
        $conn->close();
        
    } catch (Exception $e) {
        echo json_encode(["error" => "Database error: " . $e->getMessage()]);
    }
    exit();
}

// Generate attendance report
if (isset($_GET['action']) && $_GET['action'] === 'generate_report') {
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
    
    try {
        $conn = get_db_connection($servername, $username, $password, $dbname);
        if ($conn === null) {
            echo json_encode(["error" => "Connection failed"]);
            exit();
        }
        
        $sql = "SELECT u.name, u.employee_id, u.department, u.designation,
                        DATE(a.scan_time) as date,
                        MIN(CASE WHEN a.attendance_type = 'IN' THEN a.scan_time END) as first_in,
                        MAX(CASE WHEN a.attendance_type = 'OUT' THEN a.scan_time END) as last_out,
                        COUNT(CASE WHEN a.attendance_type = 'IN' THEN 1 END) as total_checkins,
                        COUNT(CASE WHEN a.attendance_type = 'OUT' THEN 1 END) as total_checkouts
                FROM users u
                LEFT JOIN attendance_logs a ON u.tag_id = a.tag_id 
                WHERE DATE(a.scan_time) BETWEEN ? AND ?
                GROUP BY u.tag_id, DATE(a.scan_time)
                ORDER BY u.name, DATE(a.scan_time)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $report = [];
        while ($row = $result->fetch_assoc()) {
            $working_hours = 0;
            $working_hours_formatted = "N/A";
            
            if ($row['first_in'] && $row['last_out']) {
                $in_time = strtotime($row['first_in']);
                $out_time = strtotime($row['last_out']);
                $time_diff = $out_time - $in_time;
                $working_hours = round($time_diff / 3600, 2);
                
                // Format to H:M:S
                $hours = floor($time_diff / 3600);
                $minutes = floor(($time_diff % 3600) / 60);
                $working_hours_formatted = sprintf('%02d:%02d hrs', $hours, $minutes);
            }
            
            $report[] = [
                "name" => $row['name'],
                "employee_id" => $row['employee_id'],
                "department" => $row['department'],
                "designation" => $row['designation'],
                "date" => $row['date'],
                "first_in" => $row['first_in'] ? (new DateTime($row['first_in']))->format('H:i:s') : 'N/A',
                "last_out" => $row['last_out'] ? (new DateTime($row['last_out']))->format('H:i:s') : 'N/A',
                "working_hours_raw" => $working_hours, // for sorting/export
                "working_hours" => $working_hours_formatted,
                "total_checkins" => (int)$row['total_checkins'],
                "total_checkouts" => (int)$row['total_checkouts']
            ];
        }
        
        echo json_encode([
            "success" => true,
            "report" => $report,
            "start_date" => $start_date,
            "end_date" => $end_date,
            "total_records" => count($report)
        ]);
        
        $stmt->close();
        $conn->close();
        
    } catch (Exception $e) {
        echo json_encode(["error" => "Database error: " . $e->getMessage()]);
    }
    exit();
}

// If no action is requested, serve the HTML dashboard
if (isset($_GET['action'])) {
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RFID Attendance System - Yarana IoT Guru</title>
    <style>
        /* ... (Your complete CSS is included here) ... */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Header Styles */
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px 35px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .header h1 {
            color: #4a5568;
            font-size: 2.5em;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .brand-logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .brand-logo img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        .brand-text {
            color: #667eea;
            font-size: 1.3em;
            font-weight: 700;
        }
        
        .header .subtitle {
            text-align: center;
            color: #667eea;
            font-size: 1.3em;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.success {
            background: linear-gradient(135deg, #48bb78, #38a169);
        }
        
        .stat-card.warning {
            background: linear-gradient(135deg, #ed8936, #dd6b20);
        }
        
        .stat-card.info {
            background: linear-gradient(135deg, #4299e1, #3182ce);
        }
        
        .stat-number {
            font-size: 2.2em;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 1em;
            opacity: 0.95;
        }
        
        /* Dashboard Controls */
        .dashboard-controls {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .control-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .date-controls {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .date-controls input[type="date"] {
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1em;
            transition: border-color 0.3s;
        }
        
        .date-controls input[type="date"]:focus {
            border-color: #667eea;
            outline: none;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1em;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ed8936, #dd6b20);
            color: white;
            box-shadow: 0 4px 15px rgba(237, 137, 54, 0.3);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }
        
        /* Dashboard Content */
        .dashboard-content {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .dashboard-title {
            font-size: 1.8em;
            font-weight: 600;
            color: #4a5568;
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            margin-top: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        
        thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }
        
        th {
            padding: 18px 15px;
            color: white;
            font-weight: 600;
            text-align: left;
            font-size: 1em;
            white-space: nowrap;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            transition: background-color 0.3s ease;
        }
        
        tbody tr:hover {
            background-color: #f7fafc;
        }
        
        tbody tr:nth-child(even) {
            background-color: #f8f9ff;
        }
        
        .tag-id {
            font-family: 'Courier New', monospace;
            background: #e2e8f0;
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: bold;
            color: #4a5568;
            cursor: pointer;
        }
        
        .attendance-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9em;
        }
        
        .attendance-in {
            background: #c6f6d5;
            color: #276749;
        }
        
        .attendance-out {
            background: #fed7d7;
            color: #c53030;
        }
        
        .employee-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .employee-name {
            font-weight: 600;
            color: #2d3748;
        }
        
        .employee-id {
            font-size: 0.8em;
            color: #718096;
        }
        
        .department-badge {
            background: #bee3f8;
            color: #2c5282;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .device-status {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .status-online {
            background: #48bb78;
        }
        
        .status-offline {
            background: #e53e3e;
        }
        
        /* Status Indicators */
        .status-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f0fff4;
            border-left: 4px solid #48bb78;
            border-radius: 8px;
        }
        
        .status-indicator.warning {
            background: #fffaf0;
            border-left-color: #ed8936;
        }
        
        .pulse-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #48bb78;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 30px;
            border: none;
            width: 90%;
            max-width: 600px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            animation: slideIn 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .close-btn {
            color: #aaa;
            float: right;
            font-size: 32px;
            font-weight: bold;
            transition: color 0.2s;
            cursor: pointer;
        }
        
        .close-btn:hover {
            color: #333;
        }
        
        .modal-content h2 {
            color: #667eea;
            margin-bottom: 25px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 15px;
            font-size: 1.5em;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a5568;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1em;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            border-color: #667eea;
            outline: none;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        /* Loading and Empty States */
        .loading {
            text-align: center;
            padding: 60px;
            color: #666;
            font-size: 1.2em;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
            font-size: 1.2em;
        }
        
        .empty-state h3 {
            margin-bottom: 15px;
            color: #4a5568;
            font-size: 1.3em;
        }
        
        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            z-index: 1001;
            animation: slideInRight 0.3s ease;
        }
        
        .notification.success {
            background: linear-gradient(135deg, #48bb78, #38a169);
        }
        
        .notification.error {
            background: linear-gradient(135deg, #e53e3e, #c53030);
        }
        
        .notification.info {
            background: linear-gradient(135deg, #4299e1, #3182ce);
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Footer */
        .footer {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px 35px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .footer p {
            color: #4a5568;
            font-weight: 500;
            margin-bottom: 10px;
        }
        
        .footer .brand {
            color: #667eea;
            font-weight: 700;
            font-size: 1.2em;
        }
        
        .social-links {
            margin-top: 15px;
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        
        .social-links a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        
        .social-links a:hover {
            color: #764ba2;
        }
        
        /* Device Status Panel */
        .device-panel {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .device-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .device-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #667eea;
        }
        
        .device-card.online {
            border-left-color: #48bb78;
        }
        
        .device-card.offline {
            border-left-color: #e53e3e;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 2em;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .control-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .date-controls {
                justify-content: center;
            }
            
            .dashboard-header {
                flex-direction: column;
                text-align: center;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 0.9em;
            }
            
            th, td {
                padding: 10px 8px;
            }
            
            .brand-logo {
                flex-direction: column;
                text-align: center;
            }
        }
        
        /* New record highlight */
        .new-record {
            background: linear-gradient(90deg, #48bb78, #38a169) !important;
            color: white;
            animation: newRecordFade 0.5s ease-in;
        }
        
        .new-record .tag-id,
        .new-record .attendance-badge,
        .new-record .department-badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .new-record .employee-id {
            color: #e2e8f0;
        }
        
        @keyframes newRecordFade {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Tab Navigation */
        .tab-nav {
            display: flex;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            padding: 5px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .tab-btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            background: transparent;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #718096;
        }
        
        .tab-btn.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Report Table */
        .report-table {
            font-size: 0.9em;
        }
        
        .report-table th {
            background: linear-gradient(135deg, #4299e1, #3182ce);
        }
        
        .working-hours {
            font-weight: 600;
            color: #2d3748;
        }
        
        .working-hours.overtime {
            color: #ed8936;
        }
        
        .working-hours.undertime {
            color: #e53e3e;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-top">
                <div>
                    <h1>🏢 RFID Attendance System</h1>
                    <p class="subtitle">Smart Employee Attendance Tracking with IoT</p>
                </div>
                <div class="brand-logo">
                    <div class="brand-text">
                        📡 Yarana IoT Guru<br>
                        <small style="font-size: 0.7em; opacity: 0.8;">Advanced IoT Solutions</small>
                    </div>
                </div>
            </div>
            <div class="stats-container">
                <div class="stat-card success">
                    <div class="stat-number" id="employeesPresent">0</div>
                    <div class="stat-label">Employees Present</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-number" id="totalAttendance">0</div>
                    <div class="stat-label">Today's Records</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="attendancePercentage">0%</div>
                    <div class="stat-label">Attendance Rate</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-number" id="totalEmployees">0</div>
                    <div class="stat-label">Registered Employees</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-number" id="totalCheckins">0</div>
                    <div class="stat-label">Check-ins Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="totalCheckouts">0</div>
                    <div class="stat-label">Check-outs Today</div>
                </div>
            </div>
        </div>
        
        <div class="dashboard-controls">
            <div class="control-row">
                <div class="date-controls">
                    <label for="dateFilter" style="font-weight: 600; color: #4a5568;">📅 Select Date:</label>
                    <input type="date" id="dateFilter" value="" onchange="loadAttendanceData()">
                    <button class="btn btn-primary" onclick="setToday()">📅 Today</button>
                    <button class="btn btn-success" onclick="loadAttendanceData()">🔄 Refresh</button>
                </div>
                <div style="display: flex; gap: 15px;">
                    <button class="btn btn-success" onclick="openRegisterModal()">👤 Register Employee</button>
                    <button class="btn btn-warning" onclick="openReportModal()">📊 Generate Report</button>
                </div>
            </div>
        </div>
        
        <div class="device-panel">
            <h3 style="color: #4a5568; margin-bottom: 15px;">📡 Device Status</h3>
            <div class="device-grid" id="deviceGrid">
                <div class="device-card">
                    <div class="device-status">
                        <div class="status-dot status-offline"></div>
                        <span>Checking device status...</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="tab-nav">
            <button class="tab-btn active" onclick="switchTab('attendance')">📋 Live Attendance</button>
            <button class="tab-btn" onclick="switchTab('reports')">📊 Reports & Analytics</button>
        </div>
        
        <div id="attendanceTab" class="tab-content active">
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <h2 class="dashboard-title">📋 Live Attendance Monitor</h2>
                    <div class="status-indicator">
                        <div class="pulse-dot"></div>
                        <span>Real-time monitoring active - Updates every 3 seconds</span>
                    </div>
                </div>
                
                <div class="table-container">
                    <table id="attendanceTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>RFID Tag</th>
                                <th>Employee Details</th>
                                <th>Department</th>
                                <th>Attendance</th>
                                <th>Time</th>
                                <th>Device</th>
                            </tr>
                        </thead>
                        <tbody id="attendanceTableBody">
                            <tr>
                                <td colspan="7" class="loading">
                                    <div class="loading-spinner"></div>
                                    Loading attendance data...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div id="reportsTab" class="tab-content">
            <div class="dashboard-content">
                <div class="dashboard-header">
                    <h2 class="dashboard-title">📊 Attendance Reports</h2>
                </div>
                
                <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
                    <input type="date" id="reportStartDate" class="form-control">
                    <input type="date" id="reportEndDate" class="form-control">
                    <button class="btn btn-primary" onclick="generateReport()">📊 Generate Report</button>
                    <button class="btn btn-success" onclick="exportReport()">📥 Export CSV</button>
                </div>
                
                <div class="table-container">
                    <table id="reportTable" class="report-table">
                        <thead>
                            <tr>
                                <th>Employee (ID/Name)</th>
                                <th>Date</th>
                                <th>First Check-in</th>
                                <th>Last Check-out</th>
                                <th>Working Hours</th>
                                <th>Total Entries</th>
                            </tr>
                        </thead>
                        <tbody id="reportTableBody">
                            <tr>
                                <td colspan="6" class="empty-state">
                                    <h3>📊 Select Date Range</h3>
                                    <p>Choose start and end dates to generate attendance report</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p>Developed by <span class="brand">Yarana IoT Guru</span> | Professional IoT Solutions</p>
            <p style="margin-top: 8px; color: #666; font-size: 0.9em;">
                🌐 ESP32 + 4G LTE Module | RFID RC522 | Real-time Web Dashboard
            </p>
            <div class="social-links">
                <a href="#" onclick="alert('Visit: youtube.com/yaranaaiotguru')">📺 YouTube Channel</a>
                <a href="#" onclick="alert('Follow: @YaranaIoTGuru')">📱 Social Media</a>
                <a href="#" onclick="alert('Contact: support@yaranaaiot.com')">📧 Support</a>
            </div>
            <p style="margin-top: 15px; color: #666; font-size: 0.8em;">
                Last Updated: <span id="lastUpdate">--</span> | System Status: <span id="systemStatus" style="color: #48bb78;">🟢 Online</span>
            </p>
        </div>
    </div>

    <div id="registerModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('registerModal')">&times;</span>
            <h2>👤 Register New Employee</h2>
            <form id="registerForm">
                <div class="form-group">
                    <label>RFID Tag ID:</label>
                    <input type="text" name="tag_id" id="registerTagId" required placeholder="Scan RFID tag or enter manually">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Employee Name:</label>
                        <input type="text" name="name" required placeholder="e.g., Anil Kumar">
                    </div>
                    <div class="form-group">
                        <label>Employee ID:</label>
                        <input type="text" name="employee_id" required placeholder="e.g., EMP001">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Department:</label>
                        <input type="text" name="department" placeholder="e.g., IT Department">
                    </div>
                    <div class="form-group">
                        <label>Designation:</label>
                        <input type="text" name="designation" placeholder="e.g., Software Developer">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone Number:</label>
                        <input type="text" name="phone" placeholder="e.g., +91 98765 43210">
                    </div>
                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" placeholder="e.g., anil@company.com">
                    </div>
                </div>
                <button type="submit" class="btn btn-success" style="width: 100%; margin-top: 20px;">
                    ✅ Register Employee
                </button>
            </form>
            <div id="registerMessage" style="margin-top: 15px; text-align: center; font-weight: 600;"></div>
        </div>
    </div>

    <div id="reportModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('reportModal')">&times;</span>
            <h2>📊 Generate Attendance Report</h2>
            <div class="form-row" style="margin-bottom: 20px;">
                <div class="form-group">
                    <label>Start Date:</label>
                    <input type="date" id="modalStartDate">
                </div>
                <div class="form-group">
                    <label>End Date:</label>
                    <input type="date" id="modalEndDate">
                </div>
            </div>
            <div style="display: flex; gap: 15px; justify-content: center;">
                <button class="btn btn-primary" onclick="generateReportFromModal()">📊 Generate Report</button>
                <button class="btn btn-success" onclick="exportReportFromModal()">📥 Export to CSV</button>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let lastAttendanceId = 0;
        let currentDate = new Date().toISOString().split('T')[0];
        let updateInterval;
        let currentTab = 'attendance';
        let reportData = []; // To hold data for the report tab
        
        // Initialize the application
        function init() {
            setToday();
            loadAttendanceData();
            loadStats();
            startRealTimeUpdates();
            setupEventListeners();
            updateSystemTime();
            setInterval(updateSystemTime, 1000);
        }
        
        // Set today's date
        function setToday() {
            currentDate = new Date().toISOString().split('T')[0];
            document.getElementById('dateFilter').value = currentDate;
            document.getElementById('reportStartDate').value = currentDate;
            document.getElementById('reportEndDate').value = currentDate;
            document.getElementById('modalStartDate').value = currentDate;
            document.getElementById('modalEndDate').value = currentDate;
            // Only refresh if the date was changed, or if it's the first load.
            if (currentTab === 'attendance') {
                loadAttendanceData();
            }
        }
        
        // --- Data Loading ---
        
        async function loadAttendanceData() {
            try {
                const selectedDate = document.getElementById('dateFilter').value || currentDate;
                
                // Show loading state in table
                const tbody = document.getElementById('attendanceTableBody');
                tbody.innerHTML = `<tr><td colspan="7" class="loading"><div class="loading-spinner"></div>Loading attendance data...</td></tr>`;
                
                const response = await fetch(`?action=fetch_attendance&date=${selectedDate}`);
                const data = await response.json();
                
                if (data.success && data.data) {
                    displayAttendanceData(data.data);
                    if (data.data.length > 0) {
                        lastAttendanceId = data.data[0].id;
                    }
                } else if (data.error) {
                    showError('Error loading attendance data: ' + data.error);
                }
                
                loadStats();
                
            } catch (error) {
                console.error('Error loading attendance:', error);
                showError('Failed to load attendance data');
            }
        }
        
        async function loadStats() {
            try {
                const selectedDate = document.getElementById('dateFilter').value || currentDate;
                const response = await fetch(`?action=get_attendance_stats&date=${selectedDate}`);
                const data = await response.json();
                
                if (data.success && data.stats) {
                    const stats = data.stats;
                    document.getElementById('employeesPresent').textContent = stats.employees_present_today;
                    document.getElementById('totalAttendance').textContent = stats.total_attendance_records;
                    document.getElementById('attendancePercentage').textContent = stats.attendance_percentage + '%';
                    document.getElementById('totalEmployees').textContent = stats.total_registered_employees;
                    document.getElementById('totalCheckins').textContent = stats.total_checkins;
                    document.getElementById('totalCheckouts').textContent = stats.total_checkouts;
                    
                    updateDeviceStatus(data.devices || []);
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }
        
        // --- Real-time Updates ---
        
        function startRealTimeUpdates() {
            // Check every 3 seconds
            updateInterval = setInterval(checkForUpdates, 3000);
        }
        
        async function checkForUpdates() {
            // Only check for updates if on the 'attendance' tab and viewing 'Today's' data
            if (currentTab !== 'attendance' || document.getElementById('dateFilter').value !== currentDate) return;
            
            try {
                const response = await fetch(`?action=check_attendance_updates&last_id=${lastAttendanceId}`);
                const data = await response.json();
                
                if (data.success && data.has_updates && data.new_records.length > 0) {
                    insertNewRecords(data.new_records);
                    lastAttendanceId = data.new_records[0].id;
                    loadStats();
                    playNotificationSound();
                    showNotification('New attendance record detected!', 'success');
                }
            } catch (error) {
                console.error('Error checking updates:', error);
            }
        }
        
        // --- UI Rendering Functions ---
        
        function displayAttendanceData(records) {
            const tbody = document.getElementById('attendanceTableBody');
            
            if (records.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="empty-state">
                            <h3>📭 No Attendance Records</h3>
                            <p>No attendance records found for selected date</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            let html = '';
            records.forEach((record, index) => {
                // Row number is records.length - index because data is DESC ordered (newest first)
                html += createAttendanceRow(record, records.length - index, false);
            });
            
            tbody.innerHTML = html;
        }
        
        function insertNewRecords(newRecords) {
            const tbody = document.getElementById('attendanceTableBody');
            
            // If the table was empty (showing the empty state), replace it entirely
            if (tbody.querySelector('.empty-state') || tbody.querySelector('.loading')) {
                displayAttendanceData(newRecords);
                return;
            }
            
            let newRowsHtml = '';
            // Calculate the starting row number for the new records
            const existingRowsCount = tbody.children.length;
            const newCount = newRecords.length;
            
            for (let i = 0; i < newCount; i++) {
                // Note: This row numbering will be approximate for continuous updates
                newRowsHtml += createAttendanceRow(newRecords[i], (existingRowsCount + newCount) - i, true);
            }
            
            tbody.insertAdjacentHTML('afterbegin', newRowsHtml);
            
            // Trim the table to max 200 rows if needed
            while (tbody.children.length > 200) {
                tbody.removeChild(tbody.lastChild);
            }
            
            // Remove highlight after animation
            setTimeout(() => {
                document.querySelectorAll('.new-record').forEach(row => {
                    row.classList.remove('new-record');
                });
            }, 3000);
        }
        
        function createAttendanceRow(record, rowNum, isNew) {
            const scanTime = new Date(record.scan_time).toLocaleString('en-IN', {
                timeZone: 'Asia/Kolkata',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                day: '2-digit',
                month: '2-digit'
            });
            
            const attendanceBadge = record.attendance_type === 'IN' ? 
                '<span class="attendance-badge attendance-in">🟢 CHECK IN</span>' :
                '<span class="attendance-badge attendance-out">🔴 CHECK OUT</span>';
            
            let employeeInfo;
            if (record.user_name === 'Unregistered') {
                employeeInfo = `
                    <div class="employee-info">
                        <span style="color: #e53e3e; font-weight: 600;">❌ Unregistered</span>
                        <button class="btn btn-primary" style="padding: 4px 8px; font-size: 0.8em; margin-top: 5px;" 
                                onclick="openRegisterModalWithTag('${record.tag_id}')">
                            👤 Register
                        </button>
                    </div>
                `;
            } else {
                employeeInfo = `
                    <div class="employee-info">
                        <span class="employee-name">${record.user_name}</span>
                        <span class="employee-id">ID: ${record.employee_id || 'N/A'}</span>
                    </div>
                `;
            }
            
            const departmentBadge = record.department ? 
                `<span class="department-badge">${record.department}</span>` :
                '<span style="color: #718096;">N/A</span>';
            
            const deviceStatus = `
                <div class="device-status">
                    <div class="status-dot ${isNew ? 'status-online' : 'status-online'}"></div>
                    <div>
                        <div style="font-weight: 600;">${record.device_id}</div>
                        <div style="font-size: 0.8em; color: #718096;">
                            Signal: ${record.signal_strength || 'N/A'}dBm
                        </div>
                    </div>
                </div>
            `;
            
            return `
                <tr class="${isNew ? 'new-record' : ''}">
                    <td><strong>${rowNum}</strong></td>
                    <td><span class="tag-id" onclick="copyToClipboard('${record.tag_id}')">${record.tag_id}</span></td>
                    <td>${employeeInfo}</td>
                    <td>${departmentBadge}</td>
                    <td>${attendanceBadge}</td>
                    <td style="font-weight: 600; color: #2d3748;">${scanTime}</td>
                    <td>${deviceStatus}</td>
                </tr>
            `;
        }
        
        function updateDeviceStatus(devices) {
            const deviceGrid = document.getElementById('deviceGrid');
            
            if (devices.length === 0) {
                deviceGrid.innerHTML = `
                    <div class="device-card offline">
                        <div class="device-status">
                            <div class="status-dot status-offline"></div>
                            <span>No devices active today</span>
                        </div>
                    </div>
                `;
                return;
            }
            
            let html = '';
            devices.forEach(device => {
                const isOnline = device.status === 'Online';
                html += `
                    <div class="device-card ${isOnline ? 'online' : 'offline'}">
                        <div class="device-status">
                            <div class="status-dot ${isOnline ? 'status-online' : 'status-offline'}"></div>
                            <div>
                                <div style="font-weight: 600;">${device.device_id}</div>
                                <div style="font-size: 0.8em; color: #718096;">
                                    Scans: ${device.scans_today} | Last: ${new Date(device.last_seen).toLocaleTimeString()}
                                </div>
                                <div style="font-size: 0.8em; color: ${isOnline ? '#48bb78' : '#e53e3e'}; font-weight: 600;">
                                    ${device.status}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            deviceGrid.innerHTML = html;
        }
        
        // --- Modals and Registration ---
        
        function openRegisterModal() {
            document.getElementById('registerForm').reset();
            document.getElementById('registerMessage').textContent = '';
            document.getElementById('registerModal').style.display = 'block';
        }
        
        function openRegisterModalWithTag(tagId) {
            openRegisterModal();
            document.getElementById('registerTagId').value = tagId;
        }
        
        function openReportModal() {
            document.getElementById('reportModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function setupEventListeners() {
            // Register Form Submission
            document.getElementById('registerForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                const messageDiv = document.getElementById('registerMessage');
                messageDiv.textContent = 'Registering...';
                messageDiv.style.color = '#4299e1';
                
                const formData = new FormData(this);
                formData.append('action', 'register_user');
                
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        messageDiv.textContent = `Success: ${data.message}`;
                        messageDiv.style.color = '#48bb78';
                        showNotification(data.message, 'success');
                        
                        setTimeout(() => {
                            closeModal('registerModal');
                            loadAttendanceData(); // Refresh the main attendance list
                        }, 1500);
                    } else {
                        messageDiv.textContent = `Error: ${data.error}`;
                        messageDiv.style.color = '#e53e3e';
                        showNotification(data.error, 'error');
                    }
                } catch (error) {
                    messageDiv.textContent = 'Network Error: Check connection.';
                    messageDiv.style.color = '#e53e3e';
                    showNotification('Registration failed due to network error', 'error');
                }
            });
            
            // Close modal on outside click
            window.onclick = function(event) {
                if (event.target.classList.contains('modal')) {
                    event.target.style.display = "none";
                }
            };
            
            // Tab switching logic (already in HTML onclick)
            // Copy to clipboard logic (already in HTML onclick)
        }
        
        // --- Reporting Functions ---
        
        function switchTab(tabName) {
            currentTab = tabName;
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            document.getElementById(tabName + 'Tab').classList.add('active');
            document.querySelector(`.tab-btn[onclick="switchTab('${tabName}')"]`).classList.add('active');
            
            if (tabName === 'reports' && reportData.length === 0) {
                generateReport(); // Auto-generate today's report on switch
            }
        }
        
        function generateReportFromModal() {
            const start_date = document.getElementById('modalStartDate').value;
            const end_date = document.getElementById('modalEndDate').value;
            closeModal('reportModal');
            switchTab('reports');
            generateReport(start_date, end_date);
        }
        
        async function generateReport(start_date = null, end_date = null) {
            const start = start_date || document.getElementById('reportStartDate').value;
            const end = end_date || document.getElementById('reportEndDate').value;
            
            if (!start || !end) {
                showNotification('Please select both start and end dates.', 'warning');
                return;
            }
            
            const tbody = document.getElementById('reportTableBody');
            tbody.innerHTML = `<tr><td colspan="6" class="loading"><div class="loading-spinner"></div>Generating Report...</td></tr>`;
            
            try {
                const response = await fetch(`?action=generate_report&start_date=${start}&end_date=${end}`);
                const data = await response.json();
                
                if (data.success) {
                    reportData = data.report;
                    displayReport(data.report);
                } else {
                    tbody.innerHTML = `<tr><td colspan="6" class="empty-state" style="color:#e53e3e;">Error: ${data.error}</td></tr>`;
                    reportData = [];
                }
            } catch (error) {
                tbody.innerHTML = `<tr><td colspan="6" class="empty-state" style="color:#e53e3e;">Network error during report generation.</td></tr>`;
                reportData = [];
            }
        }
        
        function displayReport(report) {
            const tbody = document.getElementById('reportTableBody');
            if (report.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="empty-state"><h3>🚫 No Data Found</h3><p>No attendance records found for the selected date range.</p></td></tr>`;
                return;
            }
            
            let html = '';
            report.forEach(row => {
                let hoursClass = '';
                if (row.working_hours_raw >= 9) { // Assuming 9 hours is 'overtime' target for color
                    hoursClass = 'overtime';
                } else if (row.working_hours_raw > 0 && row.working_hours_raw < 7) { // Assuming less than 7 hours is 'undertime'
                    hoursClass = 'undertime';
                }
                
                html += `
                    <tr>
                        <td>
                            <span class="employee-name">${row.name}</span><br>
                            <span class="employee-id">${row.employee_id}</span>
                        </td>
                        <td>${row.date}</td>
                        <td>${row.first_in}</td>
                        <td>${row.last_out}</td>
                        <td class="working-hours ${hoursClass}">
                            ${row.working_hours}
                        </td>
                        <td>${row.total_checkins + row.total_checkouts}</td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }
        
        function exportReportFromModal() {
            const start_date = document.getElementById('modalStartDate').value;
            const end_date = document.getElementById('modalEndDate').value;
            closeModal('reportModal');
            generateReport(start_date, end_date).then(() => {
                exportReport();
            });
        }
        
        function exportReport() {
            if (reportData.length === 0) {
                showNotification('No report data to export. Generate a report first.', 'warning');
                return;
            }
            
            let csvContent = "data:text/csv;charset=utf-8,";
            // Headers
            const headers = ["Employee ID", "Name", "Department", "Designation", "Date", "First Check-in", "Last Check-out", "Working Hours (Raw)", "Working Hours", "Total Check-ins", "Total Check-outs"];
            csvContent += headers.join(",") + "\n";
            
            // Data rows
            reportData.forEach(row => {
                const rowArray = [
                    row.employee_id,
                    `"${row.name.replace(/"/g, '""')}"`, // Handle commas/quotes in names
                    `"${row.department.replace(/"/g, '""')}"`,
                    `"${row.designation.replace(/"/g, '""')}"`,
                    row.date,
                    row.first_in,
                    row.last_out,
                    row.working_hours_raw,
                    row.working_hours,
                    row.total_checkins,
                    row.total_checkouts
                ];
                csvContent += rowArray.join(",") + "\n";
            });
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `attendance_report_${document.getElementById('reportStartDate').value}_to_${document.getElementById('reportEndDate').value}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showNotification('Report exported successfully!', 'info');
        }
        
        // --- Utility Functions ---
        
        function updateSystemTime() {
            const now = new Date().toLocaleString('en-IN', {
                timeZone: 'Asia/Kolkata',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('lastUpdate').textContent = now;
        }
        
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showNotification(`Copied Tag ID: ${text}`, 'info');
            }).catch(() => {
                showNotification('Could not copy to clipboard', 'error');
            });
        }
        
        function playNotificationSound() {
            try {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                oscillator.frequency.value = 800;
                gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + 0.3);
            } catch (error) { /* Ignore audio errors */ }
        }
        
        function showNotification(message, type) {
            const container = document.body;
            const existing = document.querySelector('.notification');
            if (existing) existing.remove();
            
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            container.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.3s ease forwards';
                setTimeout(() => notification.remove(), 300);
            }, 4000);
        }
        
        // Start the dashboard when page loads
        window.addEventListener('load', init);
    </script>
</body>
</html>