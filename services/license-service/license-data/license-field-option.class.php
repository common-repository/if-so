<?php

namespace IfSo\Services\LicenseService\LicenseData;

require_once __DIR__ . '/license-field.base.php';

class LicenseFieldOption extends LicenseFieldBase{
    function get_stored_value($opt){
        return \get_option($this->option_name);
    }
    function set_stored_value($opt,$val){
        \update_option($this->option_name,$val);
    }
    function delete_stored_value(){
        \delete_option($this->option_name);
    }
}