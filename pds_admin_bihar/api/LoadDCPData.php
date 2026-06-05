<?php
// Disable timeouts (can run for several minutes)
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '1024M');

require('../util/Connection.php');
require('../structures/DCP.php');
require('../util/SessionFunction.php');
require('../util/SessionCheck.php');
require('../util/Logger.php');
require('../util/Security.php');
require('Header.php');

function formatName($name)
{
    if (!$name) return '';
    $name = preg_replace('/[^a-zA-Z0-9_ ]/', '', $name);
    $name = ucwords(strtolower($name));
    return trim($name);
}

function isValidCoordinate($value, $type)
{
    if ($value === null || $value === '')
        return false;
    if (!is_numeric($value))
        return false;
    $v = (float) $value;
    return $type === 'latitude' ? ($v >= -90 && $v <= 90) : ($v >= -180 && $v <= 180);
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

// Current month & year (send as integers to match API requirement)
$currentMonth = (int) date('n');
$currentYear = (int) date('Y');

$apiData = [
    'Month' => $currentMonth,
    'Year' => $currentYear
];

// 2. Fetch DCP details using the retrieved token
$apiUrl = 'https://cooponline.bihar.gov.in/DCPApi/Warehouse/GetDCPDetails';

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
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => json_encode($apiData),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
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
    echo "Error connecting to DCP Details API: " . $error . "\n";
    exit();
}
if ($httpCode !== 200) {
    echo "DCP Details API returned error status: " . $httpCode . "\n";
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

$dcpData = $apiResponse['lstDCPData'] ?? null;
if (empty($dcpData)) {
    echo "No DCP data found for $currentMonth/$currentYear.\n";
    exit();
}

// Clear existing data before pushing fresh data to prevent duplicates
mysqli_query($con, "TRUNCATE TABLE dcp");

$insertedCount = 0;
$errorCount = 0;

foreach ($dcpData as $data) {
    try {
        if (empty($data['Id']) || empty($data['Name']) || empty($data['District'])) {
            $errorCount++;
            continue;
        }

        $dcp = new DCP;
        $dcp->setDistrict(formatName($data['District'] ?? ''));
        $dcp->setName($data['Name'] ?? '');
        $dcp->setId($data['Id'] ?? '');
        
        $type = $data['Type'] ?? '';
        if (empty($type)) {
            $type = 'DCP';
        }
        $dcp->setType($type);

        $lat = isset($data['Latitude']) && is_numeric($data['Latitude']) ? $data['Latitude'] : 0;
        $lon = isset($data['Longitude']) && is_numeric($data['Longitude']) ? $data['Longitude'] : 0;
        $dcp->setLatitude($lat);
        $dcp->setLongitude($lon);

        $wheatDemand = 0;
        $friceDemand = 0;

        if (isset($data['Inventories']) && is_array($data['Inventories'])) {
            foreach ($data['Inventories'] as $inv) {
                $commodity = $inv['commodity'] ?? '';
                if (stripos($commodity, 'Wheat') !== false) {
                    $wheatDemand = $inv['inventory'] ?? 0;
                } elseif (stripos($commodity, 'Forified') !== false || stripos($commodity, 'frice') !== false) {
                    $friceDemand = $inv['inventory'] ?? 0;
                }
            }
        }

        $dcp->setDemand($friceDemand);       // demand maps to Procurement FRice
        $dcp->setDemandrice($wheatDemand);   // demand_rice maps to Procurement Wheat
        $dcp->setUniqueid(substr(uniqid("DCP_"), 0, 15));
        $dcp->setActive($data['active'] ?? '1');

        $insertQuery = $dcp->insert($dcp);
        if (mysqli_query($con, $insertQuery)) {
            $insertedCount++;
            writeLog("User -> " . ($_SESSION['user'] ?? 'SYSTEM') .
                " | DCP loaded from API -> " . ($data['Name'] ?? '') .
                " | District -> " . ($data['District'] ?? ''));
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
echo "Source: GetDCPDetails | Period: " . date('F Y') . "\n";

// Redirect to DCP page after completion
echo "<script type='text/javascript'>";
echo "setTimeout(function() {";
echo "window.location.href = '../DCP.php';";
echo "}, 3000);"; // Wait 3 seconds to show the summary
echo "</script>";

require('Fullui.php');
?>
