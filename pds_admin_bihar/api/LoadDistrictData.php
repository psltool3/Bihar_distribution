<?php
// Disable timeouts
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '1024M');

require('../util/Connection.php');
require('../structures/District.php');
require('../util/SessionFunction.php');
require('../util/SessionCheck.php');
require('../util/Logger.php');
require('../util/Security.php');
require('Header.php');

function formatName($name) {
    $name = preg_replace('/[^a-zA-Z\s]/', '', $name);
    $name = ucwords(strtolower($name));
    return trim($name);
}

// 1. Get access token from Bihar Token API
$tokenUrl = 'https://cooponline.bihar.gov.in/DCPApi/token';
$tokenFields = [
    'grant_type' => 'password',
    'username' => 'SFCbihar',
    'password' => 'Pat@1234'
];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $tokenUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => http_build_query($tokenFields),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded'
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
]);

$tokenResponse = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);
curl_close($curl);

if ($error) {
    echo "Error connecting to Token API: " . $error . "\n";
    exit();
}
if ($httpCode !== 200) {
    echo "Token API returned error code: " . $httpCode . "\n";
    exit();
}

$tokenData = json_decode($tokenResponse, true);
$accessToken = $tokenData['access_token'] ?? null;
if (!$accessToken) {
    echo "Failed to retrieve access token from response.\n";
    exit();
}

// 2. Fetch district details using the retrieved token
$apiUrl = 'https://cooponline.bihar.gov.in/DCPApi/Warehouse/GetDistrict';

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 240,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'GET',
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
]);

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);
curl_close($curl);

if ($error) {
    echo "Error connecting to District API: " . $error . "\n";
    exit();
}
if ($httpCode !== 200) {
    echo "District API returned error status: " . $httpCode . "\n";
    exit();
}

$apiResponse = json_decode($response, true);
if (!$apiResponse || !is_array($apiResponse)) {
    echo "Invalid API response or API returned error.\n";
    exit();
}

if (isset($apiResponse['Status']) && $apiResponse['Status'] !== 200) {
    echo "API returned error: " . ($apiResponse['Message'] ?? 'Unknown error') . "\n";
    exit();
}

$districtsData = $apiResponse['lstDistricts'] ?? null;
if (empty($districtsData)) {
    echo "No districts data found.\n";
    exit();
}

// Clear existing districts before pushing fresh data to prevent duplicates
mysqli_query($con, "TRUNCATE TABLE districts");

$insertedCount = 0;
$errorCount = 0;

foreach ($districtsData as $data) {
    try {
        if (empty($data['Id']) || empty($data['District'])) {
            $errorCount++;
            continue;
        }

        $district = new District;
        $district->setId((string)$data['Id']);
        $district->setName(formatName($data['District'] ?? ''));

        $insertQuery = $district->insert($district);
        if (mysqli_query($con, $insertQuery)) {
            $insertedCount++;
            writeLog("User -> " . ($_SESSION['user'] ?? 'SYSTEM') .
                " | District loaded from API -> " . ($data['District'] ?? ''));
        } else {
            $errorCount++;
        }

    } catch (Exception $e) {
        $errorCount++;
        continue;
    }
}

mysqli_close($con);

// Plain text summary (no scripts)
echo "Data Load Complete\n";
echo "-------------------------\n";
echo "New records inserted : $insertedCount\n";
echo "Records with errors  : $errorCount\n";
echo "-------------------------\n";
echo "Source: GetDistrict\n";

// Redirect to District page after completion
echo "<script type='text/javascript'>";
echo "setTimeout(function() {";
echo "window.location.href = '../District.php';";
echo "}, 3000);"; // Wait 3 seconds to show the summary
echo "</script>";

require('Fullui.php');
?>
