<?php

/**
 * Require the library
 */
require 'src/PHPTailLogs.php';
/**
 * Initilize a new instance of PHPTail
 * @var PHPTail
 */
$tail = new PHPTailLogs();

/**
 * We're getting an AJAX call
 */
if (isset($_GET['ajax'])) {
    echo json_encode($tail->getNewLines());
    die();
}

/**
 * We're getting a config request call
 */
if (isset($_GET['get_filters'])) {
    echo json_encode($tail->getFilters());
    die();
}

/**
 * We're getting a config store call
 */
if (isset($_GET['put_filters'])) {
    $tail->storeFilters($_POST);
    die();
}

/**
 * Regular GET/POST call, print out the GUI
 */
$tail->initLinesState();
$tail->generateGUI();
