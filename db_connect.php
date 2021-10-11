<?php
const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS_PROD = '';
const DB_PASS_TEST = 'root';
const DB_NAME = 'google_data';

$db = new mysqli(DB_HOST , DB_USER, DB_PASS_TEST,DB_NAME);
//$db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);



