<?php
include __DIR__ . '/../vendor/autoload.php';

use Simps\MQTT\Client;

$server = 'localhost';     // change if necessary
$port = 1883;                     // change if necessary
$username = 'user001';                   // set your username
$password = 'hLXQ9ubnZGzkzf';                   // set your password
$client_id = Client::genClientID(); // make sure this is unique for connecting to sever - you could use uniqid()

$mqtt = new Bluerhinos\phpMQTT($server, $port, $client_id);

if($mqtt->connect(true, NULL, $username, $password)) {
    $mqtt->publish('simps-mqtt/user001/update', 'Hello World! at ' . date('r'), 0, false);
    $mqtt->close();
} else {
    echo "Time out!\n";
}