<?php
$root = dirname(__DIR__, 2);   // Mutationsproben PowerInvert (0.39.6): php .tools/ref/mutate-invert.php
$cases=[
 ['SetVarFloat ohne Vorzeichen','MeterHub/module.php','$value *= $this->PowerSign();','$value *= 1.0;',['test-powerinvert.php']],
 ['PowerSign immer +1','MeterHub/module.php',"return \$this->ReadPropertyBoolean('PowerInvert') ? -1.0 : 1.0;","return 1.0;",['test-powerinvert.php','test-backfill-invert.php']],
 ['PowerSign immer -1','MeterHub/module.php',"return \$this->ReadPropertyBoolean('PowerInvert') ? -1.0 : 1.0;","return -1.0;",['test-powerinvert.php']],
 ['Ziel ohne Umleitung','MeterHub/module.php','if ($this->PowerSign() > 0) {','if (true) {',['test-powerinvert.php','test-backfill-invert.php']],
 ['Hochrechnung ohne Rückdrehung','MeterHub/module.php','$p *= $sign; // SetVarFloat()','$p *= 1.0; // SetVarFloat()',['test-powerinvert.php','test-virtual.php','test-ref-treiber.php']],
 ['Nachtrag Leistung ohne Vorzeichen','libs/MeterHubInexogy.php',"'scale' => 1000.0 * \$this->PowerSign()]","'scale' => 1000.0]",['test-backfill-invert.php']],
 ['Nachtrag Energie ohne Umleitung','libs/MeterHubInexogy.php',"'ident' => \$this->EnergyIdentForInvert('energy_import')","'ident' => 'energy_import'",['test-backfill-invert.php']],
];
foreach($cases as [$n,$f,$a,$b,$tests]){
  $d=sys_get_temp_dir().'/invm_'.bin2hex(random_bytes(3)); mkdir("$d/MeterHub",0777,true); mkdir("$d/libs"); mkdir("$d/MeterHubDiscovery");
  foreach(['MeterHub','libs','MeterHubDiscovery'] as $x) foreach(glob("$root/$x/*.php") as $g) copy($g,"$d/$x/".basename($g));
  $src=file_get_contents("$d/$f"); if(substr_count($src,$a)!==1){echo "n/a  $n\n";continue;}
  file_put_contents("$d/$f",str_replace($a,$b,$src));
  $caught=false;
  foreach($tests as $t){ $o=shell_exec('MHUB_MODULE='.escapeshellarg("$d/MeterHub/module.php").' MHUB_DISCOVERY='.escapeshellarg("$d/MeterHubDiscovery/module.php").' php '.escapeshellarg("$root/.tools/$t").' 2>&1');
    if(!preg_match('/ALLE PRÜFUNGEN BESTANDEN|Alle Prüfungen bestanden/',$o)) {$caught=$t;break;} }
  $bad = $bad ?? 0; if (!$caught) { $bad++; }
  echo ($caught?"erkannt ($caught)":"NICHT ERKANNT")."  $n\n";
  shell_exec('rm -rf '.escapeshellarg($d));
}

echo $bad ? "NICHT ALLE MUTATIONEN ERKANNT\n" : "ALLE MUTATIONEN ERKANNT\n";
exit($bad ? 1 : 0);
