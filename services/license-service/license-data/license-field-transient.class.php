<?php

namespace IfSo\Services\LicenseService\LicenseData;

require_once __DIR__ . '/license-field.base.php';

class LicenseFieldTransient extends LicenseFieldBase{
    function get_stored_value($opt){
        return \get_transient($this->option_name);
    }
    function set_stored_value($opt,$val){
        \set_transient($this->option_name,true,$val);
    }
    function delete_stored_value(){
        \delete_transient($this->option_name);
    }
}