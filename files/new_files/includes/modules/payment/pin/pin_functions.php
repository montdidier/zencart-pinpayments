<?php
function zen_get_customer_pin_token($customer_id){
    global $db;

    $customer_tokens_query = "select *
                              from " . TABLE_PIN_TOKENS . "
                              where customer_id = '" . (int)$customer_id . "'";

    $customer_tokens = $db->Execute($customer_tokens_query);

    $tokens = array();
    if ($customer_tokens->RecordCount() > 0) {
        while (!$customer_tokens->EOF) {
            $tokens[] = array("token"=>$customer_tokens->fields['token'],
                "cardinfo"=>$customer_tokens->fields['cardinfo'],
                'pinid'=> $customer_tokens->fields['id'],
                'unique_card'=> $customer_tokens->fields['unique_card'],
                );
            $customer_tokens->MoveNext();
        }

    }

    return $tokens;
}

function zen_check_existing_customer_pin_token($customer_id, $unique_card){
    global $db;

    $customer_tokens_query = "select *
                              from " . TABLE_PIN_TOKENS . "
                              where customer_id = '" . (int)$customer_id . "' AND unique_card='".$unique_card."'";

    $customer_tokens = $db->Execute($customer_tokens_query);

    $tokens = array();
    if ($customer_tokens->RecordCount() > 0) {
        return $customer_tokens->fields['token'];
    }

    return false;
}


function zen_del_customer_pin_token($customer_id, $unique_card){
    global $db;

    $customer_tokens_query = "delete from " . TABLE_PIN_TOKENS . "
                              where customer_id = '" . (int)$customer_id . "' AND unique_card='".$unique_card."'";

    $customer_tokens = $db->Execute($customer_tokens_query);
    return false;
}

?>