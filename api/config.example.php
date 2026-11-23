<?php
// Horizon HRMS - database connection.
// Copy this file to config.php and fill in your own MySQL details.
// config.php is git-ignored so real passwords never reach the repository.
const DB_HOST = '127.0.0.1';      // on InfinityFree: the "MySQL Hostname", e.g. sql123.infinityfree.com
const DB_PORT = 3306;
const DB_NAME = 'horizon_hrms';   // on InfinityFree: e.g. if0_12345678_hrms
const DB_USER = 'hrms_app';       // on InfinityFree: e.g. if0_12345678
const DB_PASS = 'change-me';
const APP_TIMEZONE = 'Asia/Manila';

// Required on any public server: a secret phrase asked for when the site is set up
// for the first time, so nobody else can create the administrator account.
// Leave empty only on a private computer.
const SETUP_KEY = '';
