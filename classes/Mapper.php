<?php


namespace Stanford\ProjPANSMigrator;

use REDCap;


class Mapper
{

    const LAST_ROW = 1120; //5000 if not testing

    private $data_dict;
    private $to_data_dict;
    private $mapper;
    private $repeating_forms;
    private $header;
    private $transmogrifier;

    /**
     * [treatment_other_ep1] => Array (
     *      [from_field] => treatment_other_ep1
            [to_repeat_event] =>
            [to_form_instance] => episode:1
            [to_field] => ep_treatment_other
            [custom] =>
            [form_name] =>
            [notes] =>
            [from_fieldtype] => notes
            [from_form] => pans_patient_questionnaire
     */


    /**
     *
     * Mapper constructor.
     * @param $origin_pid
     * @param $file
     *
     */
    public function __construct($origin_pid, $file) {
        global $module;
        //$this->data_dict = REDCap::getDataDictionary($origin_pid, 'array', false );
        $this->data_dict = $module->getMetadata($origin_pid);
        $this->to_data_dict = $module->getMetadata($module->getProjectId());
        //$module->emDebug($origin_pid, $this->to_data_dict); exit;

        //$module->emDebug($this->data_dict);

        $this->mapper = $this->createMapper($file);
        //$module->emDebug($this->mapper);  exit;

        try {
            $this->transmogrifier = Transmogrifier::getInstance($this->mapper);
        } catch (\Exception $e) {
            die ("Unable to create mapper!  " . $e->getMessage());
        }

        //$module->emDebug($this->transmogrifier->getModifier()); exit;


        $this->repeating_forms = $this->getUniqueRepeatingForms();

        $module->emDebug($this->repeating_forms);
    }

    /**
     * Look throough the to_form_instance column of the map and make a list of all the repeating forms (unique
     * with instance number removed)
     */
    function getUniqueRepeatingForms() {

        $all_forms = array_unique(array_column($this->mapper, 'to_form_instance'));
        foreach($all_forms as $form) {
            $form_parts = explode(":", $form);
            $repeating_forms[] = $form_parts[0];
        }
        return array_filter($repeating_forms);
    }

    /**
     *
     * @param $file
     * @return array
     */
    function createMapper($file) {
        global $module;

        $data = array();
        $formName = '';
        $pointer = 0;
        $this->header = array();


        if ($file) {
            while (($line = fgetcsv($file, 1000, ",")) !== false) {
                //$this->emDebug("LINE $pointer: ", $line);

                if ($pointer == 0) {
                    $this->header =  $line;

                    //add the extra column headers
                    array_push($this->header, 'from_fieldtype','from_choice', 'to_choice');

                    //$module->emDebug("after:", $this->header); exit;
                    $pointer++;
                    continue;
                }

                $ptr = 0;
                $from_field = $line[$ptr];
                foreach ($this->header as $col_title) {
                    //$module->emDebug($ptr. " : " . $from_field. " : " .  $col_title . " : " . $line[$ptr]);
                    $data[$from_field][$col_title] = $line[$ptr++];
                }


               //add some extra meata-data from data-dictionary
/**
                * Data Dictionary example
                *     [arthritis] => Array
                (
                    [field_name] => arthritis
                    [form_name] => physical_neurological_exam_findings_d2cab9
                [section_header] => Joints
                [field_type] => checkbox
                [field_label] => Arthritis
                [select_choices_or_calculations] => 0, No|1, Yes|99, Not done
                [field_note] =>
            [text_validation_type_or_show_slider_number] =>
            [text_validation_min] =>
            [text_validation_max] =>
            [identifier] =>
            [branching_logic] =>
            [required_field] =>
            [custom_alignment] =>
            [question_number] =>
            [matrix_group_name] =>
            [matrix_ranking] =>
            [field_annotation] =>
        )
*/

                $to_field = $data[$from_field]['to_field'];


                $data[$from_field]['from_fieldtype'] = $this->data_dict[$from_field]['field_type'];
                $data[$from_field]['from_form'] = $this->data_dict[$from_field]['form_name'];
                $data[$from_field]['to_form'] = $this->to_data_dict[$to_field]['form_name'];

                //if field_type is radio/checkbox/dropdwon highlight if the choices are different.
                if (0 == strcmp('checkbox', $this->data_dict[$from_field]['field_type']) ||
                    0 == strcmp('radio', $this->data_dict[$from_field]['field_type']) ||
                    0 == strcmp('dropdown', $this->data_dict[$from_field]['field_type']) ) {

                    //trim white space
                    $from_str_orig = $this->data_dict[$from_field]['select_choices_or_calculations'];
                    $to_str_orig   = $this->to_data_dict[$to_field]['select_choices_or_calculations'];
                    $from_str = preg_replace('/\s+/', '', trim(strtolower($from_str_orig)));
                    $to_str = preg_replace('/\s+/', '', trim(strtolower($to_str_orig)));


                    if (0 !== strcmp($from_str,$to_str)) {
                        //$module->emDEbug($from_field, $from_str,$to_field, $to_str);  exit;
                        $data[$from_field]['from_choice'] = "'".$this->data_dict[$from_field]['select_choices_or_calculations']."'";
                        $data[$from_field]['to_choice'] = "'".$this->to_data_dict[$to_field]['select_choices_or_calculations']."'";
                    }
                }

                $pointer++;
                //$this->emDebug($data, $line);
                //TODO: reset pointer to 5000, setting to 100 for testing
                if ($pointer == self::LAST_ROW) break;
            }
        }
        fclose($file);

        return $data;


    }

    function printDictionary() {
        //TODO: internal commas are messing up the csv download
        //$this->downloadCSVFile("foo.csv", $this->mapper);

        $this->transmogrifier->print();;

        echo '<br>============================================';
        echo "<br>FIELD MAPPING";
        echo "<br>". implode (",", $this->header);

        foreach ($this->mapper as $row) {
            echo "<br>". implode (",", $row);
        }

    }


    private function downloadCSVFile2($filename, $data)
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $fp = fopen('php://output', 'wb');

        foreach ($this->mapper as $row) {
            fputcsv($fp, $row);//, "\t", '"' );
        }

        fclose($fp);
    }


    private function downloadCSVFile($filename, $data)
    {
        $data = implode("\n", $data);
        // Download file and then delete it from the server
        header('Pragma: anytextexeptno-cache', true);
        header('Content-Type: application/octet-stream"');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $data;
        exit();
    }

    /**
     * GETTER / SETTER
     */

    function getMapper() {
        return $this->mapper;
    }

    function getTransmogrifier() {
        return $this->transmogrifier;
    }

    function getRepeatingForms() {
        return $this->repeating_forms;
    }

}