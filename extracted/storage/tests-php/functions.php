<?php

function get_storage_obj($url,$email) {
global $api, $key;

$url = "http://".$api."/api/v1/$url".$email;
//echo "Opening connection to $url\n";
$json = @file_get_contents($url, false, stream_context_create([
    'http' => [
        'method' => 'GET',
        'header'  => "Content-Type: application/json\nAuthorization: $key",
    ]
]));
//return json_decode($json);
$status = $http_response_header[0];
echo "Returned status: $status\n";
if ($status == 200 || $status == 203) {
echo "Ok, servers responds correctly";
if ($status == 200) return true;
else return false;
} else {
echo "We haven't received any respond from server, is it down?";
return false;
}


}

function StatusCodeDescription($code) {
switch ($code) {
    case 200:
        echo "OK (Resource was found) -> True\n";
        break;
    case 204:
        echo "No Content (resource was not found) -> False\n";
        break;
    case 403:
        echo "Forbidden (no access authorized)\n";
        break;
    case 404:
        echo "Not found\n";
        break;
    case 400:
        echo "Bad Request (bad formulated input)\n";
        break;
    case 401:
        echo "Unauthorized!\n";
        break;
}
}

function StoragePost($key, $url, $data,$reason = "") {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , 'Authorization: '.$key,'Reason: '.$reason));
// if there will be ssl someday :D
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return $httpcode;
}

function StorageImagePost($key,$url,$image,$hash) {
$cfile = new CURLFile($image);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data' , 'Authorization: '.$key,'Hash: '.$hash));
    curl_setopt($ch,CURLOPT_POSTFIELDS,
    array(
      'img' => $cfile,
    ));

// if there will be ssl someday :D
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpcode;
}

?>
