<?php
$_GET['key']=getenv('TASKESC_CRON_KEY')?:getenv('STUDIOFLOW_CRON_KEY')?:'';
require dirname(__DIR__).'/public/cron.php';
