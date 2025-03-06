<?php
include 'db_connect.php';

// Decode JSON input
$data = json_decode(file_get_contents('php://input'), true);

// Extract data
$user_id = $data['user_id'];
$user_name = $data['user_name'];
$user_age = $data['user_age'];
$user_contact_no = $data['user_contact_no'];
$user_parent_contact_no = $data['user_parent_contact_no'];
$user_latitude = $data['user_latitude'];
$user_longitude = $data['user_longitude'];
$send_request_flag = $data['send_request_flag'];
$accident_detected_flag = $data['accident_detected_flag'];
$hospital_id = $data['hospital_id'];

// Validate input
if (!$user_id || !$hospital_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

// Update query
$sql = "UPDATE hospitals SET 
    user_id = '$user_id',
    user_name = '$user_name',
    user_age = '$user_age',
    user_contact_no = '$user_contact_no',
    user_parent_contact_no = '$user_parent_contact_no',
    user_latitude = '$user_latitude',
    user_longitude = '$user_longitude',
    send_request_flag = '$send_request_flag',
    accident_detected_flag = '$accident_detected_flag',
    assignment_timestamp = UNIX_TIMESTAMP()
    WHERE hid = '$hospital_id'";
if ($conn->query($sql)) {
    $sqlUserUpdate = "UPDATE users SET hospital_assigned = 1 WHERE id = '$user_id'";
    $result = $conn->query($sqlUserUpdate);
    echo json_encode(['status' => 'success', 'message' => 'Hospital and user details updated successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update hospital details']);
}

$conn->close();
?>
