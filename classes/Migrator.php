<?php

namespace Stanford\ProjPANSMigrator;

/** @var \Stanford\ProjPANSMigrator\ProjPANSMigrator $module */

use \REDCap;

//require_once ($module->getModulePath() . "classes/RepeatingForms.php");

$module->emDebug($_POST);

if (!$_POST) {
    die( "You cant be here");
}

if (!$_FILES['file']) {
    die("No File posted");
}

if (!$_POST['origin_pid']) {
    die("No originating project ID set.");
}

if ($_POST['record_ct']) {
    $rec_ct = $_POST['record_ct'];
} else {
    $rec_ct = NULL;
}


$origin_pid = $_POST['origin_pid'];
$file = fopen($_FILES['file']['tmp_name'], 'r');




if (isset($_POST['dump_map'])) {
    $module->emDebug("dumping map");
    $module->dumpMap($file, $origin_pid);
    exit;
}



if (isset($_POST['new_dd'])) {
    $module->emDebug("Updating New Data Dictionary");
    $module->migrateDataDictionary($file, $origin_pid);
    exit;
}



if ($file) {
    $data = $module->process($file, $origin_pid, $rec_ct);
} else {
    die("Uploaded file is corrupted!");
}

fclose($file);