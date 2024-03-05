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
    private $visit_id;     // visit       ex: 01

    private $legacy_main_id_field;  //keep copy of original ID
    private $legacy_visit_id_field;  //keep copy of original visit ID for visit event

    private $main_data;
    private $visit_data;
    private $repeat_form_data;
    private $error_msg;

    private $mapper;
    private $transmogrifier;  // converter of fieldtypes

    private $data_errors;

    public function __construct($ctr, $row, $id_field, $mrn_field, $mapper, $transmogrifier) {
        global $module;

        $this->ctr       = $ctr;
        $this->origin_id = $row[$id_field];

        $this->setMRN($row[$mrn_field]);
        $this->mrn_field = $mrn_field;

        $this->mapper    = $mapper;
        $this->transmogrifier = $transmogrifier;

        $this->setIDs($this->origin_id);

        $this->mapRow($row);


    }


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
                $this->legacy_main_id_field = $module->getProjectSetting('target-cytof-id'); //'id_cytof'
                $this->legacy_visit_id_field = $module->getProjectSetting('visit-cytof-id');
                break;
            case "126": //MONOCYTE
                $this->legacy_main_id_field = $module->getProjectSetting('target-monocyte-id'); //'id_monocyte'
                $this->legacy_visit_id_field = $module->getProjectSetting('visit-monocyte-id');
                break;
            default;
                $module->emError("ID=$origin_id did not have a recognized protocol: $this->protocol_id");
                $this->legacy_main_id_field = null;
                $this->legacy_visit_id_field = null;
                break;
        }

    }



    function checkIDExistsInMain() {
        global $module;
        $id = $this->ibh_id;
        $target_id_field = $this->legacy_main_id_field;
        $target_event = $module->getProjectSetting('main-config-event-id');

        if (($target_id_field == null) || ($target_event == null)) {
            throw new EMConfigurationException("<br><br>EM Config is not set correctly!!  ID:[ $target_id_field ] or EVENT: [ $target_event ] not set. Please RECHECK your EM Config for all mandatory fields");
        }

        //if pans or hc, check if the id already exists in the baseline.
        if (($this->protocol_id == '77') || ($this->protocol_id == '111')) {
            $found = $this->checkIDExists($id, $target_id_field, $target_event);
        } else {
            //otherwise use MRN to search
            $module->emDebug('Protocol is '. $this->protocol_id . " so using MRN to locate: ". $this->mrn);
            $mrn_field = $module->getProjectSetting('target-mrn-field');
            //$found = $this->checkMRNExistsInMain();
            $found = $this->checkIDExists($this->mrn, $mrn_field, $target_event);

            if (empty($found)) {
                //change request: if monocyte or cytof and not found, use protocol id and subject_id to locate ($this->legacy_main_id_field)
                $found = $this->checkIDExists($id, $target_id_field, $target_event);
            }

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


    /**
     * check if the id passed in parameter exists already for the given ID field
     * TODO: convert to SQL rather than use a SQL search
     *
     *
     * @param $id
     * @param $target_id_field
     * @param $target_event
     * @return mixed|null
     */
    function checkIDExists($id, $target_id_field, $target_event) {
        global $module;

        if (empty($id)) {
            $module->emDebug("No id passed.");
            return null;
        }

        /**
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

        $module->emDebug($filter, $params, $records);
*/
        $data_table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($module->getProjectId()) : "redcap_data";

        $sql = sprintf(
            "select rd.record, rd.instance 
from $data_table rd
where
 rd.event_id = %d
and rd.project_id = %d
and rd.field_name = '%s'
and rd.value = '%s'",
            db_escape($target_event),
            $module->getProjectId(),
            db_escape($target_id_field),
            db_escape($id)
        );
        //$module->emDebug("SQL: ". $sql);
        $q = db_query($sql);
        $row = db_fetch_assoc($q);

        return $row;
        /**
        if ($row=db_fetch_assoc($q)) {
            $module->emDebug("SQL found ".$row['record']);
            return $row['record'];
        } else {
            return false;
        }
         */
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
    function mapRow($row) {
        global $module;
        //RepeatingForms saves by 'array' format, so format to be an array save

        //new change: if visit id is 1, then map to the baseline event (regardless of entry in 'to_repeat_event"
        $visit_id = $this->visit_id;

        //array_filter will filter out values of '0' so add function to force it to include the 0 values
        $row = array_filter($row, function($value) {
            return ($value !== null && $value !== false && $value !== '');
        });

        $mapper = $this->mapper;
        $modifier = $this->transmogrifier->getModifier();

        //make the save data array
        $main_data = array();
        $visit_data = array();
        $repeat_form_data = array();
        $error_msg = array();


        foreach ($row as $key => $val) {
            //do some error cleaning here
            if ($key == 'screen_hcdob_v2' && $visit_id !== '01') {
                $msg = "This key, $key, has not enabled for this visit $visit_id. It will not be migrated." . $val;
                $module->emError($msg);
                $error_msg[] = $msg;
                continue;
            }


            if ($key == 'csf_collected') {
                //$module->emDebug("KEY IS $key ". json_encode($val));
            }

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
            $mod_field_array = array();

            //check if there are ny custom recoding needed
            if (array_key_exists($key, $modifier)) {
                foreach ($modifier[$key] as $target_field => $def) {
                    //check if there are customizations to change that $target field
                    switch($def['type']){
                        case "splitName":
                            // expecting two parameters
                            $target_field_array = $this->transmogrifier->splitName($key,$val ); //this can have two fields so expect an array
                            break;
                        case "textToCheckbox":
                            $target_field_array = $this->transmogrifier->textToCheckbox($key, $val);
                            break;
                        case "checkboxToCheckbox":
                            $target_field_array = array_replace($target_field_array, $this->transmogrifier->checkboxToCheckbox($key, $val, $target_field, $def['map']));
                            break;
                        case "radioToCheckbox":
                            $mod_field_array = $this->transmogrifier->radioToCheckbox($key, $val, $target_field, $def['map']);
                            $target_field_array = array_replace($target_field_array,$mod_field_array);
                            break;
                        case "checkboxToRadio":
                            $mod_field_array =  $this->transmogrifier->checkboxToRadio($key, $val, $target_field, $def['map']);
                            $target_field_array = array_replace($target_field_array, $mod_field_array);
                            break;
                        case "recodeRadio":
                            $target_field_array = $this->transmogrifier->recodeRadio($key, $val, $target_field, $def['map']);

                            break;
                        case "addToField":
                            //target field is custom_1,
                            //custom_2 is list of fields to concat

                            $target_field_array = $this->transmogrifier->addToField($key, $row);
                            break;

                        default:

                            $target_field_array[$target_field] = $val;  //only need to do this if we are needing to upload to data fields
                    }


                }
            } else {
                $target_field_array[$target_field] = $val;  //only need to do this if we are needing to upload to data fields
            }


            //$module->emDebug("=========> TARGET",$key,  $target_field_array);

            //if the event form is blank, it's the first event otherwise, it's the repeating event
            //new update, if the visit id is 01 AND PANS, then map it to first event (regardless of entry in to_repeat_event
            $pans_first_visit = ($visit_id == '1') && (($this->protocol_id == '77') || ($this->protocol_id == '111'));
            if ((!empty($mapper[$key]['to_repeat_event'])) && (!$pans_first_visit)) {
            //if ((!empty($mapper[$key]['to_repeat_event'])) && ($visit_id !== '1')) {
                //$module->emDebug("Setting $key into REPEAT EVENT: " .  $mapper[$key][$to_repeat_event]);
                // save to the repeat event
                //this is going to a visit event
                //$visit_data[$this->mapper[$key]['to_field']] = $val;
                //$visit_data[($mapper[$key]['to_repeat_event'])][$mapper[$key]['to_field']] = $val;

                //wrapped everything in array to handle multiple field (like first and last name)
                foreach ($target_field_array as $t_field => $t_val) {
                    //$visit_data[($mapper[$key]['to_repeat_event'])][$target_field] = $val;

                    //for certain fields, we need to over lap multiple assignments
                    //visit_sample gets coded over by 4 separate fields

                    if (!empty($existing_val = $visit_data[($mapper[$key]['to_repeat_event'])][$t_field])) {
                        $module->emDebug("VISIT DATA has existing value for $key ".$t_field);
                        if ($t_field == 'visit_sample') {
                            $module->emDebug("visit_sample will be collated with new value. ".json_encode($t_val));
                            $t_val = array_replace($existing_val, $t_val);
                        }
                    }

                    $visit_data[($mapper[$key]['to_repeat_event'])][$t_field] = $t_val;
                }

                //check if there are any customizations to the repeating event


            } else if (!empty($mapper[$key]['to_form_instance'])) {
                //TODO: delete got rid of instance, so this is no longer used

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
                //this is for the main event
                foreach ($target_field_array as $t_field => $t_val) {
                    //$main_data[$target_field] = $val;
                    if ($t_field==='mrn') {
                        $t_val = self::formatMRNRemoveHyphen($t_val);
                    }
                    $main_data[$t_field] = $t_val;
                }


            }

        }

        //$module->emDebug($main_data, $visit_data);


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

    public function firstVisit() {
        return $this->visit_id == '01' ? true : false;
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