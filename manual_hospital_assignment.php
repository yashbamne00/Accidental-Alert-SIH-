<?php

include 'db_connect.php';

function sendWhatsAppMessage($idInstance, $apiTokenInstance, $recipient, $message)
{
    $url = "https://api.green-api.com/waInstance$idInstance/SendMessage/$apiTokenInstance";

    $data = [
        'chatId' => $recipient . '@c.us',
        'message' => $message
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($statusCode == 200) ? "Message sent successfully!" : "Error: $response";
}

function findNearbyHospitals($latitude, $longitude)
{
    $radius = 5000; // Radius in meters
    $url = "https://overpass-api.de/api/interpreter?data=[out:json];(node(around:$radius,$latitude,$longitude)[amenity=hospital];);out;";
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Smarthelm/1.0 (khanfarhanzafar123@gmail.com)"
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) return [];

    $data = json_decode($response, true);
    $hospitals = [];
    if (isset($data['elements'])) {
        foreach ($data['elements'] as $element) {
            if (isset($element['tags']['name'])) {
                $hospitals[] = [
                    'name' => $element['tags']['name'],
                    'latitude' => $element['lat'],
                    'longitude' => $element['lon']
                ];
            }
        }
    }
    return $hospitals;
}

function haversineDistance($lat1, $lon1, $lat2, $lon2)
{
    $earthRadius = 6371; // Radius in km
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);
    $dLat = $lat2 - $lat1;
    $dLon = $lon2 - $lon1;

    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos($lat1) * cos($lat2) *
        sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c; // Distance in km
}

function verifyHospitalNames($hospitals, $conn)
{
    $verified = [];
    foreach ($hospitals as $hospital) {
        $firstLetter = substr($hospital['name'], 0, 1);
        $sql = "SELECT hid, name, contact FROM hospitals WHERE name LIKE '$firstLetter%'";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                if (strcasecmp($row['name'], $hospital['name']) == 0) {
                    $hospital['hospital_id'] = $row['hid'];
                    $hospital['contact'] = $row['contact'];
                    $verified[] = $hospital;
                    break;
                }
            }
        }
    }
    return $verified;
}

function sortHospitalsByDistance($latitude, $longitude, $hospitals, $excludedHospitalIds = []) {
    foreach ($hospitals as &$hospital) {
        // Calculate the distance and add it to the hospital array
        $hospital['distance'] = haversineDistance($latitude, $longitude, $hospital['latitude'], $hospital['longitude']);
    }
    unset($hospital); // Break reference to avoid potential side effects

    // Sort hospitals by distance using a traditional comparison
    usort($hospitals, function ($a, $b) {
        if ($a['distance'] == $b['distance']) {
            return 0;
        }
        return ($a['distance'] < $b['distance']) ? -1 : 1;
    });

    return $hospitals;
}


function findClosestHospital($latitude, $longitude, $hospitals, $excludedHospitalIds) {
    $closest = null;
    $minDistance = PHP_INT_MAX;
    foreach ($hospitals as $hospital) {
        if (in_array($hospital['hospital_id'], $excludedHospitalIds)) {
            continue;
        }
        $distance = haversineDistance($latitude, $longitude, $hospital['latitude'], $hospital['longitude']);
        if ($distance < $minDistance) {
            $minDistance = $distance;
            $closest = $hospital;
            $closest['distance'] = $distance;
        }
    }
    return $closest;
}



$sql = "SELECT hid,name, contact, user_id, user_name, user_parent_contact_no, assignment_timestamp, request_accepted_flag FROM hospitals WHERE user_id IS NOT NULL";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $elapsed = isset($row['assignment_timestamp']) ? time() - $row['assignment_timestamp'] : PHP_INT_MAX;

        if ($row['request_accepted_flag'] == 1) {

            $recipient = '91' . $row['user_parent_contact_no'];
            $userName = $row['user_name'];
            $hospitalID = $row['hid'];
            $hospitalName = $row['name'];
            $contactno = $row['contact'];
            $message = "Your child $userName is in an emergency.\n";
            $message .= "Assigned Hospital: $hospitalName (ID: $hospitalID).\n";
            $message .= "Please reach out to the hospital for further assistance. Contact number: $contactno.\n";

            sendWhatsAppMessage('7103163647','7a0c6ad91a864395a6671a3d307f97e9fd952b46c4034f799a', $recipient, $message);

            $sqlClearHospital = "UPDATE hospitals SET user_id = NULL, user_name = NULL,user_age=NULL, user_contact_no = NULL, user_parent_contact_no = NULL, user_latitude = NULL, user_longitude = NULL, send_request_flag = 0, accident_detected_flag = 0, request_accepted_flag = 0, assignment_timestamp = NULL WHERE hid = '{$row['hid']}'";
            $conn->query($sqlClearHospital);
            $resetUserFlagSql = "UPDATE users SET accident_detected_flag = 0,request_accepted_flag = 0, hospital_assigned = 0 WHERE id = '{$row['user_id']}'";
            $conn->query($resetUserFlagSql);


        }
        elseif ($row['request_accepted_flag'] == 0 && $elapsed > 30) {
            $sqlClearHospital = "UPDATE hospitals SET user_id = NULL, user_name = NULL,user_age=NULL, user_contact_no = NULL, user_parent_contact_no = NULL, user_latitude = NULL, user_longitude = NULL, send_request_flag = 0, accident_detected_flag = 0, request_accepted_flag = 0, assignment_timestamp = NULL WHERE hid = '{$row['hid']}'";
            $conn->query($sqlClearHospital);
            $resetUserFlagSql = "UPDATE users SET hospital_assigned = 0 WHERE id = '{$row['user_id']}'";
            $conn->query($resetUserFlagSql);
//
        }
    }
}
$sql = "SELECT id, name, age, contact_no, parent_contact, latitude, longitude, accident_detected_flag, attempted_hospitals FROM users WHERE accident_detected_flag = 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {

        if ($row['accident_detected_flag'] == 1) {
            $sqlCheck = "SELECT hid FROM hospitals WHERE request_accepted_flag = 0 AND TIMESTAMPDIFF(SECOND, FROM_UNIXTIME(assignment_timestamp), NOW()) < 120 AND user_id = '{$row['id']}'";
            $checkResult = $conn->query($sqlCheck);

            if ($checkResult && $checkResult->num_rows > 0) {
                continue;
            }


            $nearbyHospitals = findNearbyHospitals($row['latitude'], $row['longitude']);
            if (!empty($nearbyHospitals)) {
                $verifiedHospitals = verifyHospitalNames($nearbyHospitals, $conn);
                if (!empty($verifiedHospitals)) {
                    $attemptedHospitals = json_decode($row['attempted_hospitals'], true) ?: [];
                    $nearestHospital = findClosestHospital($row['latitude'], $row['longitude'], $verifiedHospitals, $attemptedHospitals);
                }
            }
        }
    }
}
$sql = "SELECT id, name, age, contact_no, parent_contact, latitude, longitude, accident_detected_flag, hospital_assigned FROM users WHERE accident_detected_flag = 1";
$result = $conn->query($sql);

$data = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $user = $row;

        // Check if a hospital has been assigned
        if ($row['hospital_assigned'] == 1) {
            // Fetch the assigned hospital details for the user
            $sql = "SELECT hid, name, contact FROM hospitals WHERE user_id = '{$row['id']}'";
            $assigned_hospital_result = $conn->query($sql);

            if ($assigned_hospital_result && $assigned_hospital_result->num_rows > 0) {
                while ($assigned_hospital_row = $assigned_hospital_result->fetch_assoc()) {
                    // Fetch the assigned hospital details
                    $assignedHospital = [
                        'name' => $assigned_hospital_row['name'], // Assigned hospital name
                        'id' => $assigned_hospital_row['hid'], // Assigned hospital ID
                        'contact' => $assigned_hospital_row['contact'], // Assigned hospital contact number
                    ];

                    // Assign the hospital details to the user
                    $user['assigned_hospital'] = $assignedHospital;
                }
            }
        }

        else {
            // If no hospital is assigned, find verified hospitals
            $nearbyHospitals = findNearbyHospitals($user['latitude'], $user['longitude']);
            $verifiedHospitals = [];

            if (!empty($nearbyHospitals)) {
                $verifiedHospitals = verifyHospitalNames($nearbyHospitals, $conn);
                if (!empty($verifiedHospitals)) {
                    $verifiedHospitals = sortHospitalsByDistance($user['latitude'], $user['longitude'], $verifiedHospitals);
                }
            }

            $user['verified_hospitals'] = $verifiedHospitals;
        }

        $data[] = $user;
    }
}

header('Content-Type: application/json');
echo json_encode($data);

$conn->close();
?>
