#!/usr/bin/php
<?php

/*
*       Medianova rConfig Automated NetOps Purger
*       
*       Reads rConfig env ini file to retrieve database credentials, reads taken backups,
*       if a backup is older than 2 months it first deletes them from database and then
*       from file system thus prevents storage space from filling up.
*       
*       Emrecan Öksüm was here 13/08/2025 12:03
*/

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Europe/Istanbul'); // Change to your timezone.

$env_loc = '/var/www/html/rconfig/.env';
$dry_run = false; // If set to true, it only prints configs to be deleted but does not take any further action.
if(!file_exists($env_loc))
        die('Can\'t find .env file! please check and update .env file path from the purge script!' . PHP_EOL);

$env_lines = file($env_loc, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$env = [];
foreach ($env_lines as $line) {

        if(strpos(trim($line), '#') === 0)
                continue;

        if(preg_match('/^([^=]+)=(.*)$/', $line, $matches)) {

                $key = trim($matches[1]);
                $value = trim($matches[2], '"\'');
                $env[$key] = $value;
        }
}

if(!isset($env['DB_HOST']) || !isset($env['DB_DATABASE']) || !isset($env['DB_USERNAME']) || !isset($env['DB_PASSWORD']))
        die('Can\'t read database information from .env file! Please check it for corruption.' . PHP_EOL);

$db_host = $env['DB_HOST'];
$db_name = $env['DB_DATABASE'];
$db_user = $env['DB_USERNAME'];
$db_pass = $env['DB_PASSWORD'];

//$limit = 86400; // 1 Day = 86400 seconds. Added for testing purposes, normal usage is like below.
$limit = 2629746; // 1 Month = 2629746 seconds. For epoch calculation seconds is used.
$now = time();

$pdo = new PDO('mysql:host=' . $db_host . ';dbname=' . $db_name, $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES 'utf8'; SET CHARSET 'utf8'");

if(!$pdo)
        die('Couldn\'t connect to database! unexpected error. $env_loc path might be wrong or MySQL might be down.' . PHP_EOL);

$stmt = $pdo->prepare('SELECT * FROM configs');
$stmt->execute();
$configs = $stmt->fetchAll();

foreach($configs as $config) {

        $id = $config['id'];
        $device = $config['device_name'];
        $end_time = trim($config['end_time']);
        $path = $config['config_location'];

        $end_ts = @strtotime($end_time);
        if(!is_numeric($end_ts)) {

                echo $device . ' has invalid config date!' . $end_time . PHP_EOL;
                continue;
        }

        if($now - $end_ts > $limit) {

                if($dry_run) {

                        echo 'Not purging config file due to dry run: ' . $path . PHP_EOL;
                }else{
                        if(file_exists($path))
                                unlink($path);

                        $stmt = $pdo->prepare('DELETE from configs WHERE id = :id');
                        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                        $stmt->execute();
                        echo 'Config file purged: ' . $path . PHP_EOL;
                }
        }
}

unset($stmt);
unset($pdo);
echo 'Completed' . PHP_EOL;
