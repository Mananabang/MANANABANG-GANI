<?php
require __DIR__ . '/config.php';
session_destroy();
session_start();
flash('You have been logged out.');
redirect(BASE . '/index.php');
