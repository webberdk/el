<?php
require dirname(__DIR__) . '/functions.php';
$checks = 0;
function verifyDmi(bool $ok, string $why): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($why);
}
function dmiFixture(array $times, array $temps, array $winds, array $rain, array $cloud): array {
    $ranges = [];
    foreach (['temperature-2m'=>$temps, 'wind-speed-10m'=>$winds, 'total-precipitation'=>$rain,
        'fraction-of-cloud-cover'=>$cloud] as $key=>$values)
        $ranges[$key] = ['axisNames'=>['t','y','x'], 'shape'=>[count($times),1,1], 'values'=>$values];
    return ['type'=>'Coverage', 'domain'=>['axes'=>['t'=>['values'=>$times],
        'x'=>['values'=>[12.30]], 'y'=>['values'=>[55.58]]]], 'ranges'=>$ranges];
}
$times = ['2026-10-25T00:00:00.000Z','2026-10-25T01:00:00.000Z','2026-10-25T02:00:00.000Z'];
$f = dmiFixture($times, [273.15,283.15,null], [0,2,null], [4,6.5,7], [0,1,0.5]);
$r = rensVejr($f); $keys = array_keys($r);
verifyDmi(count($r) === 3, 'Hourly coverage parsed');
verifyDmi($r[$keys[0]]['temperatur'] === 0.0 && $r[$keys[1]]['temperatur'] === 10.0, 'Kelvin to Celsius');
verifyDmi($r[$keys[0]]['vind'] === 0.0, 'Zero wind retained');
verifyDmi($r[$keys[2]]['temperatur'] === null && $r[$keys[2]]['vind'] === null, 'Missing values stay unknown');
verifyDmi($r[$keys[0]]['nedboer'] === 2.5 && $r[$keys[1]]['nedboer'] === 0.5, 'Accumulations become interval sums');
verifyDmi($r[$keys[2]]['nedboer'] === null, 'No next sample gives unknown precipitation');
verifyDmi(date('H:i',$keys[0]) === '02:00' && date('H:i',$keys[1]) === '02:00'
    && date('P',$keys[0]) !== date('P',$keys[1]), 'Repeated local hours preserved');
verifyDmi(!$r[$keys[0]]['dag'], 'Sun position identifies night');
verifyDmi(vejrSymbol(['nedboer'=>0,'skydaekke'=>0,'dag'=>false])[0] === 'moon', 'Clear night symbol');
verifyDmi(vejrSymbol(['nedboer'=>0,'skydaekke'=>1,'dag'=>true])[0] === 'cloud', 'Overcast symbol');
verifyDmi(vejrSymbol(['nedboer'=>2,'skydaekke'=>1,'nedboerstype'=>3,'dag'=>true])[0] === 'cloud-snow', 'Snow type symbol');
verifyDmi(vejrSymbol(['nedboer'=>2,'skydaekke'=>1,'nedboerstype'=>5,'dag'=>true])[1] === 'Underafkølet nedbør', 'Freezing rain type');
$bad=$f; $bad['ranges']['total-precipitation']['values']=[4,1,2];
verifyDmi(rensVejr($bad)[$keys[0]]['nedboer'] === null, 'Accumulation reset is not a fake zero');
$bad=$f; $bad['ranges']['total-precipitation']['values']=[4,null,7];
verifyDmi(rensVejr($bad)[$keys[0]]['nedboer'] === null, 'Missing adjacent accumulation');
$bad=$f; $bad['domain']['axes']['t']['values'][1]='2026-10-25T03:00:00.000Z';
verifyDmi(rensVejr($bad) === [], 'Unsorted time axis rejected');
$gap=dmiFixture([$times[0],$times[2]], [273.15,283.15], [1,2], [4,7], [0,1]);
verifyDmi(rensVejr($gap)[$keys[0]]['nedboer'] === null, 'Two-hour gap cannot become one-hour precipitation');
$bad=$f; $bad['ranges']['wind-speed-10m']['shape']=[3,2,1];
verifyDmi(rensVejr($bad) === [], 'Wrong grid shape rejected');
$bad=$f; $bad['domain']['axes']['t']['values'][0]='2026-02-30T00:00:00.000Z';
verifyDmi(rensVejr($bad) === [], 'Invalid calendar date rejected');
$bad=$f; $bad['ranges']['temperature-2m']['values'][0]='273.15';
verifyDmi(rensVejr($bad)[$keys[0]]['temperatur'] === null, 'String values rejected');
verifyDmi(rensVejr(['hourly'=>[]]) === [], 'Old provider cache rejected');
verifyDmi(strpos(dmiFejlTekst(429), '429') !== false, 'Busy response identified');
verifyDmi(strpos(dmiFejlTekst(0), 'forbindelse') !== false, 'Connection failure identified');
verifyDmi(strpos(dmiFejlTekst(200), 'dataformat') !== false, 'Invalid successful response identified');
echo "OK: $checks DMI checks\n";
