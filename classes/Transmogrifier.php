<?php

namespace Stanford\ProjPANSMigrator;

use Exception;
use \REDCap;

class Transmogrifier {

    private static $instance = null;



    private $supported_custom = array("splitName", "textToCheckbox", "checkboxToCheckbox", "addToField");


    // array with from_field and array as value with map
    private $modifier = array();

    private function __construct($mapper) {
        global $module;
        foreach ($mapper as $k => $v) {
            if (!empty($v['custom'])) {
                $module->emDebug("doing $k with ". $v['custom']);
                if (!in_array($v['custom'],$this->supported_custom)) {
                    $module->emDebug("doing $k with ". $v['custom'] . " : ". $v['from_field'], $v);
                    throw new Exception("Aborting migration!!!  Unsupported custom type for field [$k]: ".$v['custom'] );
                }

                $modifier[$k]["custom"] = $v['custom'];
                $modifier[$k]["custom_1"] = $v['custom_1'];
                $modifier[$k]["custom_2"] = $v['custom_2'];

                switch($v['custom']){
                    case "splitName":
                        //split by '+'
                        $modifier[$k]['fields'] = explode("+", $v['custom_1']);  //expecting '+' delimited
                        break;
                    case "textToCheckbox":
                        $modifier[$k]['fields'] = $v['custom_1']; //target field
                        $modifier[$k]['mapping'] = self::formatCheckboxLookup($v['custom_2']);
                        break;
                    case "checkboxToCheckbox":
                        $modifier[$k]['fields'] = $v['custom_1']; //target field
                        $modifier[$k]['mapping'] = json_decode($v['custom_2'], true);
                        break;
                    case "addToField":
                        $modifier[$k]['fields'] = $v['custom_1'];  //target field
                        $modifier[$k]['mapping'] = explode("+", $v['custom_2']);  //expecting '+' delimited, concat both fields and enter into target
                    default:

                }
            }
        }

        $this->modifier = $modifier;
    }


    private function formatCheckboxLookup($map_json) {
        $json_to_array = json_decode($map_json, true);

        foreach ($json_to_array as $from => $to) {
            //remove whitespace and upper
            $from_fixed = self::formatForStringCompare($from);
            $checkbox_map[$from_fixed] = explode('+', $to);
        }

        return $checkbox_map;
    }

    public function formatForStringCompare($string)  {
        return preg_replace('/\s*\W*/', '', strtoupper($string));
    }

    /**
     * Split the incoming field into the number of outgoing fields
     * Expecting $incoming to be the value to split
     * $outgoing is delimited by + sign
     * For example: $incoming = "John Abel Jones"
     *              $outgoing = "first_name+last_name"
     */
    public function splitName($from_field, $incoming_value) {
        global $module;

        $re = '/(?<first>.+?)[\s]+(?<middle>.+?[\s,]+)?(?<last>.+)$/m';

        preg_match_all($re, $incoming_value, $matches, PREG_SET_ORDER, 0);

        $first = $matches[0]['first'];
        $middle = $matches[0]['middle'];
        $last = $matches[0]['last'];

        $target_field = $this->modifier[$from_field]['fields'];

        $return_array[$target_field[0]] = trim($first . " " . $middle);
        $return_array[$target_field[1]] = $last;

        //$module->emDebug($incoming_value, $target_field, $return_array);
        return $return_array;

    }

    /**
     * Mapping is in $modifier
     * Example:
     * [ethnicity] => Array
        (
            [custom] => textToCheckbox
            [custom_1] => ethnicity_2
            [custom_2] => {"Asian":"2","Asian/ Indian":"2","Asian/Caucasian":"0+2","Caucasian":"0","Caucasian/ Asian":"0+2"...
            [fields] => ethnicity_2
            [mapping] => Array
                (
                    [ASIAN] => Array
                        (
                            [0] => 2
                        )

     * @param $incoming
     */
    public function textToCheckbox($from_field, $incoming_value) {

        global $module;
        //$module->emDebug("Transmogrifying $incoming_value for $from_field....", $this->modifier);

        //convert the incoming
        $formatted = self::formatForStringCompare($incoming_value);
        $target_field = $this->modifier[$from_field]['fields'];
        $outgoing_value = $this->modifier[$from_field]['mapping'][$formatted];

        $module->emDebug("Formatted $formatted / targetfield: $target_field / outgoing : $outgoing_value");
        foreach ($outgoing_value as $k => $v) {

            //$return_array[$target_field. '___'.$v] = 1;  //nope, wrong format, using arrays so just the coded value
            $return_array[$target_field][$v] = 1;  //nope, using arrays so just the coded value
        }

        return $return_array;
    }

    /**
     * Used for recoding checkbox values
     *
     *
     */
    public function checkboxToCheckbox($from_field, $incoming_value) {
        global $module;
        //
        $target_field = $this->modifier[$from_field]['fields'];
        $outgoing_value = $this->modifier[$from_field]['mapping'];

        foreach ($incoming_value as $code => $value) {
            $outgoing[$outgoing_value[$code]] = $value;
        }

        $return_array[$target_field]=$outgoing;

        return $return_array;


    }

    public function addToField($from_field, $row) {
        global $module;

        $target_field = $this->modifier[$from_field]['fields'];
        $concat_fields = $this->modifier[$from_field]['mapping'];
        //$module->emDebug($from_field,$concat_fields);

        foreach ($concat_fields as $c_fields) {
            $val[trim($c_fields)] = $row[trim($c_fields)];
        }
        $return_array[$target_field]= implode("\n",$val);
        //$module->emDebug("CONCATTED: ",$return_array);
        return $return_array;

    }

    public function print() {
        echo "<br>CUSTOM REMAP SETTING<br><br>";

        foreach ($this->getModifier() as $field=>$map) {
            //echo "<br>". $field  . ' to '.$map['fields'] .  implode("/", $map['mapping']);
            echo "<br>". strtoupper($field)." <pre>";
            print_r( $map);
            echo "</pre>";

        }
    }

    public function getModifier() {
        return $this->modifier;
    }

    public static function getInstance($mapper) {
        if (!isset(self::$instance)) {
            self::$instance = new Transmogrifier($mapper);
        }

        return self::$instance;
    }

}

