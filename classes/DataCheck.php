<?php


namespace Stanford\ProjPANSMigrator;



class DataCheck
{
    private static $re_missed_school = '/(?<find>\b([0-9]|1[0-9]|20)\b)/';

    private static $checker = array(
        "missed_school"=>'/(?<find>\b([0-9]|1[0-9]|20)\b)/',
        'sympsib_v2'   => '/(?<find>\b([01]\b))/',
        'gi_new'       => '/(?<find>^([0-9]|[1-9]\d|100)$)/'
    );

    public static function valueValid($field, $val) {
        global $module;

        if (array_key_exists($field, self::$checker)) {
            $module->emDebug("this key is about to be checked:  $field");

            $reg = self::$checker[$field];
            preg_match_all($reg, $val, $matches, PREG_SET_ORDER, 0);
            if (!empty($matches[0]['find'])) {
                return true;
            } else {
                return false;
            }
        }
        return true;
    }
}