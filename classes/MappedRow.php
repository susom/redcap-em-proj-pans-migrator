<?php


namespace Stanford\ProjPANSMigrator;

use REDCap;
use Exception;

class MappedRow {

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


    public function __construct($row, $id_field, $mrn_field, $mapper) {
        global $module;

        $this->origin_id = $row[$id_field];

        $this->mrn       = $row[$mrn_field];
        $this->mrn_field = $mrn_field;

        $this->mapper    = $mapper;

        $this->setIDs($this->origin_id);

        $this->mapRow($row, $mapper);


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

        return ($records);
    }

    function checkIDExistsInMain() {
        global $module;
        $id = $this->ibh_id;
        $target_id_field = $this->legacy_main_id_field;
        $target_event = $module->getProjectSetting('main-config-event-id');

        if (($target_id_field == null) || ($target_event == null)) {
            throw new Exception("ID: $target_id_field or EVENT: $target_event not set to check ID");
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
            if ($mapper[$key]['from_fieldtype'] == 'checkbox') {
                if (empty(array_filter($val))) {
                    continue; //don't upload this checkbox
                }
            }

            //also skip if it's a calculated field
            if ($mapper[$key]['from_fieldtype'] == 'calc') {
                continue; //don't upload this checkbox
            }

            //also skip if descriptive
            if ($mapper[$key]['from_fieldtype'] == 'descriptive') {
                continue; //don't upload this descriptive
            }

            if (empty($mapper[$key]['to_field'])) {
                $msg = "This key, $key, has no to field ";
                //$this->emError($msg);
                $error_msg[] = $msg;
                continue;
            }

            //if the event form is blank, it's the first event otherwise, it's the repeating event
            if (!empty($mapper[$key]['to_repeat_event'])) {
                //$module->emDebug("Setting $key into REPEAT EVENT");
                // save to the repeat event
                //this is going to a visit event
                //$visit_data[$this->mapper[$key]['to_field']] = $val;
                $visit_data[($mapper[$key]['to_repeat_event'])][$mapper[$key]['to_field']] = $val;

            } else if (!empty($mapper[$key]['to_form_instance'])) {
                //if to_form_instance is blank, then it goes into the main event
                //$module->emDebug("Setting $key to value of $val into REPEAT FORM. ".$this->mapper[$key]['from_fieldtype']. " to " . $mapper[$key]['to_form_instance']);
                $instance_parts = explode(':', $mapper[$key]['to_form_instance']);
                $repeat_form_data[$instance_parts[0]][$instance_parts[1]][$mapper[$key]['to_field']] = $val;

                //xxyjl: this is bogus, but adding the visit+id here will be set multiple times, ok?
                $repeat_form_data[$instance_parts[0]][$instance_parts[1]][$instance_parts[0]."_visit_id"] = $this->origin_id;

            } else {
                $main_data[$this->mapper[$key]['to_field']] = $val;
            }

        }

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

        //return array($main_data, $visit_data, $repeat_form_data, $error_msg);

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