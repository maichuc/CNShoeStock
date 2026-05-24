<?php
$apiKey = 'al_n5qzdfzde1ea7hnp1b';
$day = 10;
$month = 2;
$year = 2024;
$url = "https://apiamlich.thuc.me/v1/get-lunar-date?day={$day}&month={$month}&year={$year}&api_key={$apiKey}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
