#!/usr/bin/php

<?php

/*
 *	Have Cron run this script every minute or so
 */

date_default_timezone_set('America/New_York');


require_once("c_eMQ.php");

$eq=new c_eMQ();

echo $eq->sendBatch()."\n";

?>