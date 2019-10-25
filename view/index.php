<?php

namespace Stanford\ProjPANSMigrator;

/** @var \Stanford\ProjPANSMigrator\ProjPANSMigrator $module */

use REDCap;

$generatorURL = $module->getUrl('classes/Migrator.php', false, true);
?>
<form enctype="multipart/form-data" action="<?php echo $generatorURL ?>" method="post" name="instrument-migrator"
      id="instrument-migrator">
    <h2>Migrate this</h2>
    <input type="file" name="file" id="file" placeholder="mapping csv file">
    <input type="text" name="origin_pid" id="origin_pid "  placeholder="Originating PID">
    <input type="text" name="record_ct" id="record_ct "  placeholder="For Testing: Last counter">
    <input type="submit" id="submit" name="submit" value="Submit">
    <input type="submit" id="dump_map" name="dump_map" value="Dump Map">
</form>
