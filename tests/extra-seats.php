<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/PlatformPlans.php';
if(PlatformPlans::amount(1990,990,0)!==1990)throw new RuntimeException('Base Solo wrong.');
if(PlatformPlans::amount(1990,990,1)!==2980)throw new RuntimeException('Extra seat wrong.');
if(PlatformPlans::amount(1990,990,3)!==4960)throw new RuntimeException('Three seats wrong.');
echo "Extra seats amount OK\n";
