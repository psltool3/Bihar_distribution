<?php
require('../util/Connection.php');
require('../structures/District.php');
require('../util/SessionFunction.php');
require('../structures/Login.php');

if(!SessionCheck()){
	//return;
}
$message = "";
$month = $_POST['month'];
$year = $_POST['year'];
$query = "SELECT * FROM optimised_table WHERE month='$month' AND year='$year'";
$result = mysqli_query($con,$query);
$response = array();
$response_data = array();
while($row = mysqli_fetch_array($result))
{
	$temp = array();
	$temp["year"] = $row["year"];
	$temp["month"] = $row["month"];
	$temp["id"] = $row["id"];
	$temp["applicable"] = $row["applicable"];
	$temp["last_updated"] = $row["last_updated"];
	array_push($response,$temp);
	$query_approve = "SELECT * FROM optimiseddata_".$row["id"]." WHERE approve_admin<>'yes' OR approve_admin IS NULL";
	$result_approve = mysqli_query($con,$query_approve);
	$numrows_approve = mysqli_num_rows($result_approve);
	if($numrows_approve != 0){
		$message = "Please approve all tags of leg2 first";
	}
}
if(count($response)==0){
	$message = "First optimized the leg2 for this month";
}
$response_data["data"] = $response;
$response_data["message"] = $message;
echo json_encode($response_data);

?>