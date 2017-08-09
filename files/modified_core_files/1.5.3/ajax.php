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

// process delete pin token
if (isset ($_POST['delPinTokenAct']) && trim($_POST['delPinTokenAct'])=="del" && (int)$_SESSION['customer_id'] > 0) {
    if(isset($_POST['token']) && trim($_POST['token'])!="")
    {
        require_once(DIR_WS_MODULES.'/payment/pin.php');
        $tmp = new pin();
        $tmp->deregisterToken((int)$_SESSION['customer_id'], $_POST['token']);
    }
}
?>