<?php

/** @var MappedRow mrow */

namespace Stanford\ProjPANSMigrator;

include_once "emLoggerTrait.php";
include_once "EMConfigurationException.php";
require_once "classes/RepeatingForms.php";
include_once 'classes/Mapper.php';
include_once 'classes/DDMigrator.php';
include_once 'classes/MappedRow.php';
include_once 'classes/Transmogrifier.php';

use REDCap;
use Exception;
use Survey;

class ProjPANSMigrator extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private $mapper;

    public function dumpMap($file, $origin_pid) {
        $this->emDebug("Starting Map Dump");

        //test PDF
        //Survey::archiveResponseAsPDF('111-0011-01','1776','consent_for_child_healthy', 1);

        //exit;

        //upload csv file that defines the mapping from old field to new field
        //$this->mapper = new Mapper($this->getProjectSetting('origin-pid'), $file);
        $mapper = new Mapper($origin_pid, $file);

        $mapper->downloadCSVFile();
        //$mapper->printDictionary(); exit;

    }


    /**
     *
     * @param $file
     * @param $origin_pid
     * @param int $first_ct
     * @param null $test_ct
     * @throws Exception
     */
    public function process($file, $origin_pid, $first_ct= 0, $test_ct = null) {

        $target_visit_event = $this->getProjectSetting('visit-event-id') ;
        $target_main_event = $this->getProjectSetting('main-config-event-id');
        //$origin_pid =  $this->getProjectSetting('origin-pid'); //"233";
        $origin_main_event = $this->getProjectSetting('origin-main-event');

        $not_entered = array();
        $data_invalid = array();

        $this->emDebug("Starting Migration");

        //upload csv file that defines the mapping from old field to new field
        //$this->mapper = new Mapper($this->getProjectSetting('origin-pid'), $file);
        $this->mapper = new Mapper($origin_pid, $file);
        $transmogrifier = new Transmogrifier($this->mapper->getMapper());

        //$this->mapper->printDictionary(); exit;

        //change May 14: decided not to make episodes into repeating forms
        // Create the RepeatingForms for the project
        //name as 'rf_' + name of form (used in excel file to create variable name
        foreach ($this->mapper->getRepeatingForms() as $r_form) {
            ${"rf_" . $r_form} = RepeatingForms::byForm($this->getProjectId(), $r_form);
        }

        //Repeating Event for visits
        $rf_event = RepeatingForms::byEvent($this->getProjectId(),$target_visit_event );


        // 1. get the extract from project 1
        //$md = $this->getMetadata(237);
        //$this->emDebug($md);

        $this->emDebug("About to get data");

        //there seems to be an issue with getdata running into PHP Fatal error:  Allowed memory size of 2147483648 bytes exhausted
        $params = array(
            'project_id'   => $origin_pid,
            'return_format' => 'array',
            'events'        => array($origin_main_event),
            'records'       => array('77-0001-01', '77-0001-02','77-0001-03','77-0001-04','77-0001-05','77-0001-06'),
            'fields'        => null
        );
        $data = REDCap::getData($params);

        //$data = REDCap::getData($origin_pid, 'array', null, null, array($origin_main_event));
        $ctr = 0;

        // foreach row in first event
        foreach($data as $record => $event) {
            if ($ctr < $first_ct) {
                $ctr++;
                continue;
            }

            //for testing if we have a test_ct set then stop
            if ((null !== $test_ct) && ($ctr > $test_ct)) break;


            echo "<br> Analyzing row $ctr: RECORD: $record ";

            foreach($event as $row ) {
                //check that the ID doesn't already exist

                $origin_id_field = $this->getProjectSetting('origin-main-id'); //'clinical_barcode';
                $mrn_field       = $this->getProjectSetting('target-mrn-field');

                try {
                    $mrow = new MappedRow($ctr, $row, $origin_id_field, $mrn_field, $this->mapper->getMapper(), $transmogrifier);
                    if (!empty($mrow->getDataError())) {
                        $data_invalid[$record] = $mrow->getDataError();
                        $this->emError($mrow->getDataError());
                    }
                } catch (EMConfigurationException $ece) {
                    $msg = 'Unable to process row $ctr: ' . $ece->getMessage();
                    $this->emError($msg);
                    $this->logProblemRow($ctr, $row, $msg, $not_entered);
                    die ($msg);  // EM config is not set properly. Just abandon ship;'
                } catch (Exception $e) {
                    $msg = 'Unable to process row $ctr: ' . $e->getMessage();
                    $this->emError($msg);
                    $this->logProblemRow($ctr, $row, $msg, $not_entered);
                    continue;
                }

//                if ($mrow->processRow() === FALSE) {
//                    $this->logProblemRow($ctr, $row, $msg, $not_entered);
//                };


                //check whether the id_pans id (ex: 77-0148) already exists in which case only handle the visit portion of the row
                //$filter = "[" . REDCap::getEventNames(true, false,$target_main_event) . "][" . $target_id . "] = '$id'";
                //xxyjl $found = $this->checkIDExists($id, $target_id,$target_main_event);
                try {
                    $found = $mrow->checkIDExistsInMain();
                } catch (\Exception $e) {
                    $msg = 'Unable to process row $ctr: '. $e->getMessage();
                    $this->emError($msg);
                    $this->logProblemRow($ctr, $row, $msg, $not_entered);
                    continue;
                }


                //Set the participant ID
                if (empty($found)) {
                    $this->emDEbug("Row $ctr: EMPTY: $record NOT FOUND");
                    //not found so create a new record ID

                    //get a new record ID in the format S_0001
                    $record_id = $this->getNextId($target_main_event,"S", 4);
                    $this->emDebug("Row $ctr: Starting migration of $record to new id: $record_id");

                } else {
                    //$this->emDEbug("FOUND", $found);
                    //id is  found (already exists), so only add as a visit.
                    //now check that visit ID already doesn't exist

                    //$record_id = $found[0][REDCap::getRecordIdField()];
                    $record_id = $found['record']; //with the new SQL version
                    $this->emDEbug("Row $ctr: Found record ($record_id) ".$mrow->getIBHID()." with count " . count($row));
                }

                //HANDLE MAIN EVENT DATA
                //if (empty($found)) { //or if proctocl is 77 and visit id is 01 (overwrite in that case?
                //TODO: check sometimes demog not in first visit, just insert multiple times??
                //if ($mrow->getVisitID() == '01') {  // and 77??

                if (null !== ($mrow->getMainData())) {
                    //save the main event data
                    //$return = REDCap::saveData('json', json_encode(array($main_data)));
                    //RepeatingForms uses array. i think expected format is [id][event] = $data
                    $temp_instance = array();  //reset to empty
                    $temp_instance[$record_id][$this->getProjectSetting('main-config-event-id')] = $mrow->getMainData();

                    //$this->emDebug($temp_instance);
                    $return = REDCap::saveData('array', $temp_instance);

                    if (isset($return["errors"]) and !empty($return["errors"])) {
                        $msg = "Row $ctr: Not able to save project data for record $record_id with original id: " . $mrow->getOriginalID(). implode(" / ",$return['errors']);
                        $this->emError($msg, $return['errors'], $temp_instance);
                        $this->logProblemRow($ctr, $row, $msg,  $not_entered);
                    } else {
                        $this->emLog("Row $ctr: Successfully saved main event data for record " . $mrow->getOriginalID() . " with new id $record_id");
                    }
                }
                //}


                //HANDLE VISIT EVENT DATA
                //check that the visit ID doesn't already exist in the  $target_visit_id ex: 'id_pans_visit'
                //[filterLogic] => [visit_arm_1][id_pans_visit] = '77-0148-02'
                $visit_found = $mrow->checkIDExistsInVisit();
                if (empty($visit_found)) {
                    //VISIT ID not found

                    if (null !== ($mrow->getVisitData())) {
                        $this->emDebug("Row $ctr: Starting Visit Event migration w count of " . sizeof($mrow->getVisitData())); //, $mrow->getVisitData());

                        foreach ($mrow->getVisitData() as $v_event => $v_data) {
                            $v_event_id = REDCap::getEventIdFromUniqueEvent($v_event);

                            if (empty($v_event_id)) {
                                $msg = "Row $ctr: EVENT ID was not found: $v_event_id from event name $v_event";
                                $this->emError($msg);
                                $this->logProblemRow($ctr, $row, $msg, $not_entered);
                                continue;
                            }

                            //bug where getNextinstanceId is returning stale values.
                            //$next_instance_orig = $rf_event->getNextInstanceId($record_id, $v_event_id);
                            //$this->emDebug("Start getDAta nextInstance");
                            //$next_instance = $rf_event->getNextInstanceIDForceReload($record_id, $v_event_id);
                            //Switched to using SQL
                            $next_instance = $rf_event->getNextInstanceIDSQL($record_id, $v_event_id);

                            $this->emDebug("Row $ctr: record:" . $mrow->getOriginalID() . " REPEAT EVENT: $v_event Next instance is $next_instance in event $v_event_id");
                            $status = $rf_event->saveInstance($record_id, $v_data, $next_instance, $v_event_id);

                            if ($rf_event->last_error_message) {
                                $this->emError("Row $ctr: There was an error saving record $record_id: in event <$v_event_id>", $rf_event->last_error_message);
                                $this->logProblemRow($ctr, $row, $rf_event->last_error_message, $not_entered);

                            }

                        }
                    } else {
                        $msg = "Row $ctr: Visit Event had no data to enter for " . $mrow->getOriginalID();
                        $this->emError($msg);
                        $this->logProblemRow($ctr, $row, $msg, $not_entered);
                    }


                    //HANDLE REPEATING FORM DATA
                    //I"m making an assumption here that there will be no repeating form data without a visit data

                    //save the repeat form
                    //$this->emDebug("Row $ctr: Starting Repeat Form migration w count of " . sizeof($mrow->getRepeatFormData()), $mrow->getRepeatFormData());

                    //xxyjl todo: check if the visit_id already exists?
                    foreach ($mrow->getRepeatFormData() as $form_name => $instances) {
                        $this->emDebug("Repeat Form instrument $form_name ");
                        foreach ($instances as $form_instance => $form_data) {
                            $rf_form = ${"rf_" . $form_name};
                            $this->emDebug("Row $ctr: Working on $form_name with $rf_form on instance number ". $form_instance . " Adding as $next_instance");

                            $next_instance = $rf_form->getNextInstanceId($record_id, $target_main_event);

                            $rf_form->saveInstance($record_id, $form_data, $next_instance, $target_main_event);

                            //if ($rf_form->last_error_message) {
                            if ($rf_form===false) {
                                $this->emError("Row $ctr: There was an error: ", $rf_form->last_error_message);
                                $this->logProblemRow($ctr, $row, $rf_form->last_error_message,  $not_entered);
                            }
                        }
                    }
                } else {
                    //VISIT ID found
                    $msg = "Row $ctr:  VISIT ".$mrow->getOriginalID()." FOUND in participant ID {$visit_found['record']} with repeat instance ID: " .
                        $visit_found['instance'] . ". NOT entering data.";
                    $this->emError($msg);
                    $this->logProblemRow($ctr, $row, $msg,  $not_entered);
                }

            }
            $ctr++;
            unset($mrow);
        }

        $this->emDEbug("NOT ENTERED: ".json_encode($not_entered));
        $this->emDebug("INVALID DATA: " . json_encode($data_invalid));
        //printout the error file
        //file_put_contents("foo.csv", $not_entered);


        //exit;
        echo "<br>INVALID DATA: <pre>";
        print_r($data_invalid);
        echo "</pre>";
        echo "PROBLEM ROWS: <pre>";
        print_r($not_entered);
        echo "</pre>";


        //$this->downloadCSVFile("troublerows.csv",$not_entered);

    }


    public function migrateDataDictionary($file, $origin_pid) {


        $dd_mig = new DDMigrator($file, $origin_pid);

        //pass on the original data dictionary
        $dd_mig->updateDDFromOriginal();
        //$dd_mig->print(100);
    }

    function logProblemRow($ctr, $row, $msg, &$not_entered)  {
        //$msg = " VISIT FOUND with instance ID: " . $found_event_instance_id . " NOT entering data.";

        $not_entered[$ctr]['reason'] = $msg;
        //$not_entered[$ctr]['data'] = $row;  //probably should implode it.  have to handle checkboxes first



    }


    function getNextId( $event_id, $prefix = '', $padding = false) {
        $id_field = REDCap::getRecordIdField();
        $q = REDCap::getData($this->getProjectId(),'array',NULL,array($id_field), $event_id);


        $i = 1;
        do {
            // Make a padded number
            if ($padding) {
                // make sure we haven't exceeded padding, pad of 2 means
                $max = 10 ** $padding;
                if ($i >= $max) {
                    $this->emError("Error - $i exceeds max of $max permitted by padding of $padding characters");
                    return false;
                }
                $id = str_pad($i, $padding, "0", STR_PAD_LEFT);
            } else {
                $id = $i;
            }

            // Add the prefix
            $id = $prefix . $id;

            $i++;
        } while (!empty($q[$id][$event_id][$id_field]));

        return $id;
    }

}