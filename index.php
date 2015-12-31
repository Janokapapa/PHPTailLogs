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
    echo $tail->getNewLines();
    die();
}
/**
 * Regular GET/POST call, print out the GUI
 */
$tail->generateGUI();
