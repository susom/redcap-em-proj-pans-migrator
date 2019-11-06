<?php

namespace Stanford\ProjPANSMigrator;

/** @var \Stanford\ProjPANSMigrator\ProjPANSMigrator $module */

use REDCap;

include_once 'Mapper.php';

class DDMigrator
{

    private $data_dict;
    private $data_dict_new;
    private $data_dict2;
    private $to_data_dict;
    private $mapper;
    private $map;
    private $map_mid;


    private $repeating_forms;
    private $header;
    private $transmogrifier;

    private $reg_pattern;
    private $reg_replace;

    /**
     * [treatment_other_ep1] => Array (
     *      [from_field] => treatment_other_ep1
     * [to_repeat_event] =>
     * [to_form_instance] => episode:1
     * [to_field] => ep_treatment_other
     * [custom] =>
     * [form_name] =>
     * [notes] =>
     * [from_fieldtype] => notes
     * [from_form] => pans_patient_questionnaire
     */


    /**
     *
     * Mapper constructor.
     * @param $origin_pid
     * @param $file
     *
     */
    public function __construct($file,$origin_id)
    {
        global $module;

        //or origin_id????
        //$this->mapper = new Mapper($module->getProjectId(), $file);
        $this->mapper = new Mapper($origin_id, $file);


        $this->map = $this->mapper->getMapper();
        //$module->emDebug($this->map );exit;



        //reorganize by mid-field
        $this->map_mid = $this->rekeyMapByMidField();

        //$this->data_dict = REDCap::getDataDictionary($origin_pid, 'array', false );

        $this->data_dict = $module->getMetadata($module->getProjectId());
        //$module->emDebug($this->data_dict); exit;




    }

    public function rekeyMapByMidField() {
        global $module;
        $map_mid = array();
        foreach ($this->map as $k => $v) {

            $map_mid[$v['mid_field']] = $v;

        }
        return $map_mid;
    }


    public function updateDD() {

        global $module;

        //$module->emDebug($this->map);
        //$module->emDebug($this->map_mid); exit;

        //iterate through the current dictionary rather than starting with the mapper
        foreach ($this->data_dict as $key => $val) {
            //get the $to_field from $map
            $to_field = trim($this->map[$key]['to_field']);
            $to_mid_field = trim($this->map_mid[$key]['to_field']);


            $from_form = trim($this->map[$key]['from_form']);
            $to_form = trim($this->map[$key]['to_form']);


            if ($key == 'ep_day' || $key == 'ep_days') {
                $module->emDebug("EPD DAY: $key  / $to_field / $to_mid_field", $this->map[$key], $this->map_mid[$key]);
            }

            if ((empty(trim($to_field))) && (empty(trim($to_mid_field)))) {
                //$module->emDebug("DELETING $key", $to_field, $to_mid_field, $to_form, $from_form, $this->map[$key], $this->map_mid[$key]); exit;
                $module->emDebug("DELETING $key as $to_field and  $to_mid_field are unset. row: ". $this->map[$key]['original_sequence']);
                unset($this->data_dict[$key]);
                continue;
            }

            //check the original field name
            if ((strcasecmp(trim($key), trim($to_field)) !== 0) && (!empty(trim($to_field))) ) {
                //found the key and the tofield is different
                $this->data_dict[$key]['field_name'] = strtolower(trim($to_field));

                $this->reg_pattern[] = '/\['.$key.'(\]|\()/';
                $this->reg_replace[]='['.$to_field.'$1';

            } else if ((strcasecmp(trim($key), trim($to_mid_field)) !== 0) && (!empty(trim($to_mid_field)))) {
                //check the mid-stream field name
                $this->data_dict[$key]['field_name'] = strtolower(trim($to_mid_field));


                $from_form = $this->map_mid[$key]['from_form'];
                $to_form = $this->map_mid[$key]['to_form'];


                $this->reg_pattern[] = '/\['.$key.'(\]|\()/';
                $this->reg_replace[]='['.$to_mid_field.'$1';
            }

            //update forms
            if (strcasecmp( trim($from_form), trim($to_form)) !== 0) {
//                echo "<br>$key: comparing  FORMS: ". $from_form.' is not equal to '.$to_form.' in a case insensitive string comparison';
                $this->data_dict[$key]['form_name'] = strtolower(trim($to_form));
            }
        }

        $this->replaceReferences($this->reg_pattern, $this->reg_replace);
        $module->emDEbug("NEW DICT", $this->data_dict, $this->data_dict2);

        $this->downloadCSV();
    }

    public function updateDD2() {
        global $module;

        echo "UPDAING!!!";
        //iterate through the mapper
        //compare from and to_field : if different, then update all references to field:
        //  field_name, select_choices_or_calculation, branching_logic
        //compare from and to_form : if different, update all references to field:
        //    form_name

        //$this->mapper->printDictionary(); exit;
        //print "<pre>" . print_r($this->mapper->getMapper(),true). "</pre>";


        foreach ($this->mapper->getMapper() as $key => $map) {
            $replacement =  $map['to_field'];
            //echo "<br> CHECKING: $key vs $replacement";

            if ((strcasecmp(trim($key), trim($replacement)) !== 0) && (!empty(trim($replacement))) ) {
                echo '<br> ' .$key. ' is not equal to '.$replacement.' in a case insensitive string comparison<br>';
                if (array_key_exists($key, $this->data_dict)) {
                    if ($key == 'symp_1') {
//                        print "<br> " . $key . ' VS ' . strtolower(trim($replacement)) . " UPDATING: <pre>" . print_r($this->data_dict[$key], true) . "</pre>";
                    }
                    $this->data_dict[$key]['field_name'] = strtolower(trim($replacement));

                    $this->reg_pattern[] = '/\['.$key.'(\]|\()/';
                    $this->reg_replace[]='['.$replacement.'$1';



                }
            }

            if (strcasecmp( trim($map['from_form']), trim($map['to_form'])) !== 0) {
                echo "<br>$key: comparing  FORMS: ". $map['from_form'].' is not equal to '.$map['to_form'].' in a case insensitive string comparison';
                if (array_key_exists($key, $this->data_dict)) {
                    $this->data_dict[$key]['form_name'] = strtolower(trim($map['to_form']));
                    $module->emDebug($map['to_form']);
                }
            }
        }

        $this->replaceReferences($this->reg_pattern, $this->reg_replace);
        $module->emDEbug("NEW DICT", $this->data_dict2);

        $this->downloadCSV();

    }

    private function replaceReferences($target, $replacement) {
        global $module;

        $module->emDebug($target, $replacement);

        $foo =  preg_replace($target, $replacement, json_encode($this->data_dict));
        $this->data_dict2 = json_decode($foo, true);

        /**
        foreach ($this->data_dict as $field => $dict_row) {
            //replace in calculated field
            $re = '/\['.$target.'(\]|\()/m';
            $re2 = '/.*?(?<target>\['.$target.'\]|\().*?/m';
            $str = '[yboc1] + [yboc2] + [yboc3] + [yboc4] + [yboc5]';


            echo "<br><br>";
            echo "<br>REPLACED: " . preg_replace($re, $replacement, $this->data_dict[$target]['select_choices_or_calculations']);

            echo "<br>REPLACED: " . preg_replace($re, $replacement, $this->data_dict[$target]['branching_logic']);

        }
*/
    }


    private function downloadCSV($filename='temp.csv')
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $fp = fopen('php://output', 'wb');

        foreach ($this->data_dict2 as $row) {
            fputcsv($fp, $row);//, "\t", '"' );
        }

        fclose($fp);
    }


    public function print($num=5) {
        global $module;

        $i=0;
        foreach ($this->data_dict as $foo => $bar) {
            //$module->emDebug($foo. " : " .$bar['field_name'] . " / form ".$bar['form_name'] );
            //echo $i . " :: " . $foo. " : " .$bar['field_name'] . " / form ".$bar['form_name'] . "<br>";
            //echo $i . " : ".  $bar;
            if ($i > $num) break;
            $i++;
        }
    }
}
