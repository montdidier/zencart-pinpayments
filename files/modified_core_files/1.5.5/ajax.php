<?php
/**
 * ajax front controller
 *
 * @package templateSystem
 * @copyright Copyright 2003-2014 Zen Cart Development Team
 * @copyright Portion s Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version GIT: $Id: Author: Ian Wilson   New in v1.5.4 $
 * MODIFIED for Pin Payments by ZenExpert
 */
// Abort if the request was not an AJAX call
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    http_response_code(400); // "Bad Request"
    exit();
}
require('includes/application_top.php');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header("Access-Control-Allow-Headers: X-Requested-With");


// --- support functions ------------------
if (!function_exists('utf8_encode_recurse')) {
    function utf8_encode_recurse($mixed_value)
    {
        if (strtolower(CHARSET) == 'utf-8') {
            return $mixed_value;
        } elseif (!is_array($mixed_value)) {
            return utf8_encode((string)$mixed_value);
        } else {
            $result = array();
            foreach ($mixed_value as $key => $value) {
                $result[$key] = utf8_encode($value);
            }
            return $result;
        }
    }
}

function ajaxAbort($status = 400, $msg = null)
{
    http_response_code($status); // 400 = "Bad Request"
    if ($msg) echo $msg;
    require('includes/application_bottom.php');
    exit();
}
// --- support functions ------------------



if (!isset($_GET['act']) || !isset($_GET['method'])) {
    ajaxAbort();
}

$language_page_directory = DIR_WS_LANGUAGES . $_SESSION['language'] . '/';

$className = 'zc' . ucfirst($_GET['act']);
$classFile = $className . '.php';
$basePath  = DIR_FS_CATALOG . DIR_WS_CLASSES;

if (!file_exists(realpath($basePath . 'ajax/' . basename($classFile)))) {
    ajaxAbort();
}

require realpath($basePath . 'ajax/' . basename($classFile));
$class = new $className();
if (!method_exists($class, $_GET['method'])) {
    ajaxAbort(400, 'class method error');
}

// Accepted request, so execute and return appropriate response:
$result = call_user_func(array($class, $_GET['method']));
$result = utf8_encode_recurse($result);
echo json_encode($result);

// process delete pin token
if (isset ($_POST['delPinTokenAct']) && trim($_POST['delPinTokenAct'])=="del" && (int)$_SESSION['customer_id'] > 0) {
    if(isset($_POST['token']) && trim($_POST['token'])!="")
    {
        require_once(DIR_WS_MODULES.'/payment/pin.php');
        $tmp = new pin();
        $tmp->deregisterToken((int)$_SESSION['customer_id'], $_POST['token']);
    }
}

require('includes/application_bottom.php');