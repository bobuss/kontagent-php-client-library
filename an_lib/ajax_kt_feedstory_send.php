<?php
include_once 'kt_config.php';

$uuid = null;
$st1 = null;
$st2 = null;

if(isset($_GET['uuid']))
    $uuid = $_GET['uuid'];
if(isset($_GET['st1']))
    $st1 = $_GET['st1'];
if(isset($_GET['st2']))
    $st2 = $_GET['st2'];

$an->kt_feedstory_send($uuid, $st1, $st2);

?>