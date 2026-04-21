<?php
$CONFIG = require '/home8/ivancicg/private/config.php';
$key = urlencode($CONFIG['metalprice_key']);
$url = "https://api.metalpriceapi.com/v1/latest?api_key={$key}&base=USD&currencies=XAU,XAG,XPT,XPD,EUR,GBP,JPY";
$body = file_get_contents($url);
header('Content-Type: application/json');
echo $body;