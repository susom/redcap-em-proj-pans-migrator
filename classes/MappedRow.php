<?php


namespace Stanford\ProjPANSMigrator;

include_once "DataCheck.php";

use Exception;
use REDCap;

class MappedRow {
    private $ctr;

    private $mrn;
    private $mrn_field;

    private $origin_id;    //original id. ex; 77-1234-01
    private $ibh_id;      //protocol+sub ex: 77-1234
    private $protocol_id;  //protocolid   ex: 77
    private $subject_id;   // subject     ex: 1234
    private $visit_id;     // viist       ex: 01

    private $legacy_main_id_field;  //keep copy of original ID
    private $legacy_visit_id_field;  //keep copy of original visit ID for visit event

    private $main_data;
    private $visit_data;
    private $repeat_form_data;
    private $error_msg;

    private $mapper;
    private $transmogrifier;  // converter of fieldtypes

    private $data_errors;

    public function __construct($ctr, $row, $id_field, $mrn_field, $mapper) {
        global $module;

        $this->ctr       = $ctr;
        $this->origin_id = $row[$id_field];

        $this->setMRN($row[$mrn_field]);
        $this->mrn_field = $mrn_field;

        $this->mapper    = $mapper;
        $this->transmogrifier = Transmogrifier::getInstance($mapper);

        $this->setIDs($this->origin_id);

        $this->mapRow($row, $mapper);


    }

    /**
    public function processRow() {
        global $module;
        $row_problems = array();

        $target_main_event = $module->getProjectSetting('main-config-event-id');

        //check whether the id_pans id (ex: 77-0148) already exists in which case only handle the visit portion of the row
        //$filter = "[" . REDCap::getEventNames(true, false,$target_main_event) . "][" . $target_id . "] = '$id'";
        //xxyjl $found = $this->checkIDExists($id, $target_id,$target_main_event);
        try {
            $found = $this->checkIDExistsInMain();
        } catch (\Exception $e) {
            $msg = 'Unable to process row $ctr: '. $e->getMessage();
            $module->emError($msg);
            die ($msg);  // EM config is not set properly. Just abandon ship;
        }


        //Set the paritcipant ID
        if (empty($found)) {
            $module->emDEbug("EMPTY: {$this->getOriginalID()} NOT FOUND");
            //not found so create a new record ID

            //get a new record ID in the format S_0001
            $record_id = $module->getNextId($target_main_event,"S", 4);
            $module->emDebug("Row $this->ctr: Starting migration to new id: $this->origin_id");

        } else {
            //$this->emDEbug("FOUND", $found);
            //id is  found (already exists), so only add as a visit.
            //now check that visit ID already doesn't exist

            $record_id = $found[0][REDCap::getRecordIdField()];
            $module->emDEbug("Row $this->ctr: Found record ($record_id) ".$this->getIBHID()." with count " . count($this->row));
        }

        //HANDLE MAIN EVENT DATA
        //if (empty($found)) { //or if proctocl is 77 and visit id is 01 (overwrite in that case?
        if ($this->getVisitID() == '01') {  // and 77??

            if (null !== ($this->getMainData())) {
                //save the main event data
                //$return = REDCap::saveData('json', json_encode(array($main_data)));
                //RepeatingForms uses array. i think expected format is [id][event] = $data
                $temp_instance[$record_id][$module->getProjectSetting('main-config-event-id')] = $this->getMainData();

                //$module->emDebug($temp_instance);
                $return = REDCap::saveData('array', $temp_instance);

                if (isset($return["errors"]) and !empty($return["errors"])) {
                    $msg = "Not able to save project data for record $record_id with original id: " . $this->getOriginalID(). implode(" / ",$return['errors']);
                    $module->emError($msg, $return['errors']);
                    $module->logProblemRow($ctr, $row, $msg,  $not_entered);
                } else {
                    $module->emLog("Successfully saved main event data for record " . $this->getOriginalID() . " with new id $record_id");
                }
            }
        }


        //HANDLE VISIT EVENT DATA
        //check that the visit ID doesn't already exist in the  $target_visit_id ex: 'id_pans_visit'
        //[filterLogic] => [visit_arm_1][id_pans_visit] = '77-0148-02'
        $visit_found = $this->checkIDExistsInVisit();
        if (empty($visit_found)) {
            //VISIT ID not found

            if (null !== ($this->getVisitData())) {
                $module->emDebug("Row $ctr: Starting Visit Event migration w count of " . sizeof($this->getVisitData()));

                foreach ($this->getVisitData() as $v_event => $v_data) {
                    $v_event_id = REDCap::getEventIdFromUniqueEvent($v_event);
                    //$next_instance = $rf_event->getNextInstanceId($record_id, $v_event_id);

                    //$module->emDebug("REPEAT EVENT: $v_event Next instance is $next_instance");
                    $status = $rf_event->saveInstance($record_id, $v_data, $next_instance, $v_event_id);

                    if ($rf_event->last_error_message) {
                        $module->emError("There was an error: ", $rf_event->last_error_message);
                        $module->logProblemRow($ctr, $row, $rf_event->last_error_message,  $not_entered);

                    }
                }
            } else {
                $msg = "Visit Event had no data to enter for " . $this->getOriginalID();
                $module->emError($msg);
                $module->logProblemRow($this->ctr, $row, $msg, $not_entered);
            }


            //HANDLE REPEATING FORM DATA
            //I"m making an assumption here that there will be no repeating form data without a visit data

            //save the repeat form
            //$module->emDebug("Row $ctr: Starting Repeat Form migration w count of " . sizeof($mrow->getRepeatFormData()), $mrow->getRepeatFormData());

            //xxyjl todo: check if the visit_id already exists?
            foreach ($this->getRepeatFormData() as $form_name => $instances) {
                $module->emDebug("Repeat Form instrument $form_name ");
                foreach ($instances as $form_instance => $form_data) {
                    $rf_form = ${"rf_" . $form_name};
                    $module->emDebug("Working on $form_name with $rf_form on instance number ". $form_instance . " Adding as $next_instance");

                    $next_instance = $rf_form->getNextInstanceId($record_id, $target_main_event);

                    $rf_form->saveInstance($record_id, $form_data, $next_instance, $target_main_event);

                    if ($rf_form->last_error_message) {
                        $module->emError("There was an error: ", $rf_form->last_error_message);
                        $module->logProblemRow($ctr, $row, $rf_form->last_error_message,  $not_entered);

                    }

                }

            }
        } else {
            //VISIT ID found

            $found_event_instance_id = $visit_found[0]['redcap_repeat_instance'];
            $msg = "Row $ctr:  VISIT ".$this->getOriginalID()." FOUND in participant ID {$visit_found[0][REDCap::getRecordIdField()]} with repeat instance ID: " .
                $found_event_instance_id .
                " NOT entering data.";
            $module->emError($msg);
            //* $module->logProblemRow($ctr, $row, $msg,  $not_entered);
        }


    }

     */



    function setIDs($origin_id) {
        global $module;


        //grep out the visit portion of the concatted PANSid
        $re = '/(?\'id\'(?\'protocol\'\d+)-(?\'subject\'\d+))-(?\'visit\'\d+)/m';

        preg_match_all($re, $origin_id, $matches, PREG_SET_ORDER, 0);

        $this->ibh_id = $matches[0]['id'];            //the ID portion ex: 77-0123  from complete id 77-0123-01
        $this->protocol_id = $matches[0]['protocol'];  //the protocol id   ex: 77 from complete id 77-0123-01
        $this->subject_id = $matches[0]['subject'];  //the protocol id   ex: 77 from complete id 77-0123-01
        $this->visit_id = $matches[0]['visit'];   //the visit portion -01 from complete id 77-0123-01

        if (sizeof($matches) < 1) {
            //there were no matches
            throw new \Exception('Unable to parse original ID: '.$origin_id);
        }

        switch($this->protocol_id) {
            case "77": //PANS
                $this->legacy_main_id_field = $module->getProjectSetting('target-pans-id'); //'id_pans'
                $this->legacy_visit_id_field = $module->getProjectSetting('visit-pans-id');
                break;
            case "111": //HEALTHY CONTROL
                $this->legacy_main_id_field = $module->getProjectSetting('target-hc-id'); //'id_pans'
                $this->legacy_visit_id_field = $module->getProjectSetting('visit-hc-id');
                break;
            case "118": //CYTOF
                $this->legacy_main_id_field = $module->getProjectSetting('target-cytof-id'); //'id_pans'
                $this->legacy_visit_id_field = $module->getProjectSetting('visit-cytof-id');
                break;
            case "126": //MONOCYTE
                $this->legacy_main_id_field = $module->getProjectSetting('target-monocyte-id'); //'id_pans'
                $this->legacy_visit_id_field = $module->getProjectSetting('visit-monocyte-id');
                break;
            default;
                $module->emError("ID=$origin_id did not have a recognized protocol: $this->protocol_id");
                $this->legacy_main_id_field = null;
                $this->legacy_visit_id_field = null;
                break;
        }

    }


    function checkMRNExistsInMain() {
        global $module;

        //TODO: Uses redcap filter which can't handle wilds. Using option 1 (see setMRN method)
        //PANS project stores mrn with hyphen between 7th and 8th digi
        //option 1: add a hyphen in filter serach
        //option 2: use sql query to search redcap_pdata
        //option 3: store all stripped MRNs and array_key search

        $mrn = $this->mrn;

        if (empty($mrn)) {
            throw new \Exception("MRN is missing for record : ".$this->origin_id);
        }

        $target_event = $module->getProjectSetting('main-config-event-id');
        $mrn_field = $module->getProjectSetting('target-mrn-field');

        $filter = "[" . REDCap::getEventNames(true, false, $target_event) . "][" . $mrn_field . "] = '$mrn'";

        // Use alternative passing of parameters as an associate array
        $params = array(
            'return_format' => 'json',
            'events'        =>  $target_event,
            'fields'        => array( REDCap::getRecordIdField()),
            'filterLogic'   => $filter
        );

        $q = REDCap::getData($params);
        $records = json_decode($q, true);
        //$module->emDEbug("Search for ".$this->mrn. "came up with this", $records);
        return ($records);
    }

    function checkIDExistsInMain() {
        global $module;
        $id = $this->ibh_id;
        $target_id_field = $this->legacy_main_id_field;
        $target_event = $module->getProjectSetting('main-config-event-id');

        if (($target_id_field == null) || ($target_event == null)) {
            throw new EMConfigurationException("<br><br>EM Config is not set correctly!!  ID:[ $target_id_field ] or EVENT: [ $target_event ] not set. Please RECHECK your EM Config for all mandatory fields");
        }

        if ($this->protocol_id == '77') {
            $found = $this->checkIDExists($id, $target_id_field, $target_event);
        } else {
            //otherwise use MRN to search
            $module->emDebug('Protocol is '. $this->protocol_id . " so using MRN to locate: ". $this->mrn);
            $found = $this->checkMRNExistsInMain();

        }
        return $found;
    }

    function checkIDExistsInVisit() {
        global $module;

        $id              = $this->origin_id;
        $target_id_field = $this->legacy_visit_id_field;
        $target_event    = $module->getProjectSetting('visit-event-id');

        if (($target_id_field == null) || ($target_event == null)) {
            throw new Exception("ID: $target_id_field or EVENT: $target_event not set to check ID");
        }

        $found = $this->checkIDExists($id, $target_id_field, $target_event);
        return $found;

    }

    function checkIDExists($id, $target_id_field, $target_event) {
        global $module;

        if (empty($id)) {
            $module->emDebug("No id passed.");
            return null;
        }

        $filter = "[" . REDCap::getEventNames(true, false, $target_event) . "][" . $target_id_field . "] = '$id'";

        // Use alternative passing of parameters as an associate array
        $params = array(
            'return_format' => 'json',
            'events'        =>  $target_event,
            'fields'        => array( REDCap::getRecordIdField()),
            'filterLogic'   => $filter
        );



        $q = REDCap::getData($params);
        $records = json_decode($q, true);

        //$module->emDebug($filter, $params, $records);

        return ($records);

    }


    /**
     * Mapping form has these columns:
     *   from_field
     *   to_repeat_event
     *   to_form_instance
     *   to_field
     *   custom
     *   form_name
     *   notes
     *
     * @param $new_record_id
     * @param $next_instance
     * @param $row
     * @param $mapper
     */
    function mapRow($row, $mapper) {
        global $module;
        //RepeatingForms saves by 'array' format, so format to be an array save

        //array_filter will filter out values of '0' so add function to force it to include the 0 values
        $row = array_filter($row, function($value) {
            return ($value !== null && $value !== false && $value !== '');
        });

        //make the save data array
        $main_data = array();
        $visit_data = array();
        $repeat_form_data = array();
        $error_msg = array();

        foreach ($row as $key => $val) {

            //ignore all the field '_complete'
            if (preg_match('/_complete$/', $key)) {
                //$module->emDebug("Ignoring form complete field: $key");
                continue;
            }

            //check if empty checkbox field
            if ($mapper[$key]['dd_from_fieldtype'] == 'checkbox') {
                if (empty(array_filter($val))) {
                    continue; //don't upload this checkbox
                }
            }

            //also skip if it's a calculated field
            if ($mapper[$key]['dd_from_fieldtype'] == 'calc') {
                continue; //don't upload this checkbox
            }

            //also skip if descriptive
            if ($mapper[$key]['dd_from_fieldtype'] == 'descriptive') {
                continue; //don't upload this descriptive
            }



            if (empty($mapper[$key]['to_field'])) {
                $msg = "This key, $key, has no to field. It will not be migrated.";
                $error_msg[] = $msg;
                continue;
            }

            //check if there are data errors to handle?
            if (!DataCheck::valueValid($key, $val)) {
                $module->emError("Data INVALID / DELETED : key is $key and val is $val" );
                $this->data_errors[$key] = $val;
                $val = NULL;

            };

            $target_field = $mapper[$key]['to_field'];
            $target_field_array = array();

            //check if there are any customizations
//            if (!empty($mapper[$key]['custom'])) {
//                $module->emDebug("$key CUSTOM : ". $mapper[$key]['custom']. " : " . $mapper[$key]['custom_1']. " : " . $mapper[$key]['custom_2']);
//            }

            //check if there are customizations to change that $target field

            switch($mapper[$key]['custom']){
                case "splitName":
                    // expecting two parameters
                    $target_field_array = $this->transmogrifier->splitName($key,$val ); //this can have two fields so expect an array
                    break;
                case "textToCheckbox":
                    $val = $this->transmogrifier->textToCheckbox($key, $val);
                    $target_field_array = $val;  //doing this to handle checkbox remaps if custom
                    //array_merge($target_field_array, $val);
                    break;
                case "checkboxToCheckbox":
                    $val = $this->transmogrifier->checkboxToCheckbox($key, $val);
                    $target_field_array = $val;  //doing this to handle checkbox remaps if custom
                    //array_merge($target_field_array, $val);
                    break;
                case "recodeRadio":
                    $val = $this->transmogrifier->recodeRadio($key, $val);
                    $target_field_array = $val;  //doing this to handle checkbox remaps if custom
                    //array_merge($target_field_array, $val);
                    break;
                case "addToField":
                    //target field is custom_1,
                    //custom_2 is list of fields to concat

                    $target_field_array = $this->transmogrifier->addToField($key, $row);
                    break;

                default:

                    $target_field_array[$target_field] = $val;  //only need to do this if we are needing to upload to data fields
            }

            //$module->emDebug("=========> TARGET",$key,  $target_field_array);

            //if the event form is blank, it's the first event otherwise, it's the repeating event
            if (!empty($mapper[$key]['to_repeat_event'])) {
                //$module->emDebug("Setting $key into REPEAT EVENT");
                // save to the repeat event
                //this is going to a visit event
                //$visit_data[$this->mapper[$key]['to_field']] = $val;
                //$visit_data[($mapper[$key]['to_repeat_event'])][$mapper[$key]['to_field']] = $val;

                //wrapped everything in array to handle multiple field (liek first and last name)
                foreach ($target_field_array as $t_field => $t_val) {
                    //$visit_data[($mapper[$key]['to_repeat_event'])][$target_field] = $val;
                    $visit_data[($mapper[$key]['to_repeat_event'])][$t_field] = $t_val;
                }

                //check if there are any customizations to the repeating event


            } else if (!empty($mapper[$key]['to_form_instance'])) {
                //if to_form_instance is blank, then it goes into the main event
                //$module->emDebug("Setting $key to value of $val into REPEAT FORM. ".$this->mapper[$key]['from_fieldtype']. " to " . $mapper[$key]['to_form_instance']);
                $instance_parts = explode(':', $mapper[$key]['to_form_instance']);
                //$repeat_form_data[$instance_parts[0]][$instance_parts[1]][$mapper[$key]['to_field']] = $val;
                foreach ($target_field_array as $t_field => $t_val) {
                    //$repeat_form_data[$instance_parts[0]][$instance_parts[1]][$target_field] = $val;
                    $repeat_form_data[$instance_parts[0]][$instance_parts[1]][$t_field] = $t_val;
                }

                //xxyjl: this is bogus, but adding the visit+id here will be set multiple times, ok?
                $repeat_form_data[$instance_parts[0]][$instance_parts[1]][$instance_parts[0]."_visit_id"] = $this->origin_id;

            } else {
                foreach ($target_field_array as $t_field => $t_val) {
                    //$main_data[$target_field] = $val;
                    if ($t_field==='mrn') {
                        $t_val = self::formatMRNRemoveHyphen($t_val);
                    }
                    $main_data[$t_field] = $t_val;
                }

//                foreach ($target_field as $k => $v) {
//                    $main_data[$k] = $v;
//                }
            }

        }

        //$module->emDebug($main_data);


        //check that there is data in main_data
        if (sizeof($main_data)<1) {
            $this->main_data = null;
        } else {
            $main_data[$this->legacy_main_id_field] = $this->ibh_id;    //set up the target_id
            $main_data[$module->getProjectSetting('protocol-enrolled') . '___' . $this->protocol_id] = 1;  //set the checkbox field to this protocol
            $this->main_data = $main_data;
        }

        //set up the visit data
        if (sizeof($visit_data)<1) {
            $this->visit_data = null;
        } else {
            //there might be multiple repeat events
            foreach($visit_data as $revent=>$rdata) {
                $visit_data[$revent][$this->legacy_visit_id_field] = $this->origin_id;
                $visit_data[$revent][$module->getProjectSetting('visit-protocol') . '___' . $this->protocol_id] = 1;  //set the checkbox field to this protocol
            }
            $this->visit_data = $visit_data;
        }

        //save the repeat data
        if (sizeof($repeat_form_data)<1) {
            $this->repeat_form_data = null;


        } else {
            //TODO: add the visit id??
            $this->repeat_form_data = $repeat_form_data;
        }

        //if ($this->origin_id == '77-0148-08') {
            //$module->emDebug($error_msg); exit;
            //$module->emDebug($target_field_array, $main_data, $visit_data, $repeat_form_data); exit; //, $error_msg); exit;
        //}
        //return array($main_data, $visit_data, $repeat_form_data, $error_msg);

    }

    public static function formatMRNAddHyphen($mrn) {
        //format it with hyphen for 8 digits so it doesn't overwrite the current (formatted)
        //check for hyphen
        if ((strlen($mrn)== 8) && (!preg_match("/-/i", $mrn))) {
            $mrn = implode("-", str_split($mrn, 7));
        }

        return $mrn;
    }

    public static function formatMRNRemoveHyphen($mrn) {
        //26nov2019: request that MRN be stored without hyphen
        //check for hyphen
        //if  (preg_match("/-/i", $mrn)) {
        $mrn = str_replace("-", "", $mrn);
        //}

        return $mrn;
    }

    /******************************************************/
    /*  SETTER / GETTER METHODS
    /******************************************************/

    public function getOriginalID() {
        return $this->origin_id;
    }
    public function getProtocolID() {
        return $this->protocol_id;
    }
    public function getVisitID() {
        return $this->visit_id;
    }
    public function getIBHID() {
        return $this->ibh_id;
    }
    public function getMainData() {
        return $this->main_data;
    }
    public function getVisitData() {
        return $this->visit_data;
    }
    public function getRepeatFormData() {
        return $this->repeat_form_data;
    }
    public function getErrorMessage() {
        return $this->error_msg;
    }
    public function getDataError() {
        return $this->data_errors;
    }

    public function setMRN($mrn) {
        global $module;

        $this->mrn = self::formatMRNRemoveHyphen($mrn);
    }

    public function setMainData($main_data) {
        $this->main_data = $main_data;
    }
    public function setVisitData($visit_data) {
         $this->visit_data = $visit_data;
    }
    public function setRepeatFormData($repeat_form_data) {
         $this->repeat_form_data = $repeat_form_data;
    }
    public function setErrorMessage($error_msg) {
        $this->error_msg = $error_msg;
    }


}