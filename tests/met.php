<?php
require dirname(__DIR__) . '/functions.php';
$checks = 0;
function verifyMet(bool $ok, string $why): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($why);
}
function metFixture(): array {
    $series = [];
    foreach (['2026-10-25T00:00:00Z','2026-10-25T01:00:00Z','2026-10-25T02:00:00Z'] as $i => $time)
        $series[] = ['time' => $time, 'data' => ['instant' => ['details' => [
            'air_temperature' => $i === 2 ? null : 10 - $i * 10, 'wind_speed' => 0,
            'cloud_area_fraction' => 50]], 'next_1_hours' => ['details' => ['precipitation_amount' => $i],
            'summary' => ['symbol_code' => 'partlycloudy_night']]]];
    return ['type'=>'Feature', 'properties'=>['meta'=>['units'=>[
        'air_temperature'=>'celsius','wind_speed'=>'m/s','cloud_area_fraction'=>'%', 'precipitation_amount'=>'mm']],
        'timeseries'=>$series]];
}
$f = metFixture(); $r = rensVejr($f); $keys = array_keys($r);
verifyMet(count($r) === 3, 'MET hourly data parsed');
verifyMet($r[$keys[0]]['temperatur'] === 10.0 && $r[$keys[1]]['temperatur'] === 0.0, 'Celsius preserved including zero');
verifyMet($r[$keys[0]]['vind'] === 0.0, 'Zero wind retained');
verifyMet($r[$keys[0]]['skydaekke'] === 0.5, 'Percent cloud cover normalized');
verifyMet($r[$keys[1]]['nedboer'] === 1.0, 'Next hour rain preserved without cumulative subtraction');
verifyMet($r[$keys[2]]['temperatur'] === null, 'Missing temperature is unknown');
verifyMet(date('H:i',$keys[0]) === '02:00' && date('H:i',$keys[1]) === '02:00'
    && date('P',$keys[0]) !== date('P',$keys[1]), 'Repeated local hours stay distinct');
$bad=$f; $bad['properties']['meta']['units']['air_temperature']='kelvin';
verifyMet(rensVejr($bad) === [], 'Unexpected units rejected');
$bad=$f; $bad['properties']['timeseries'][1]['time']='2026-02-30T00:00:00Z';
verifyMet(rensVejr($bad) === [], 'Invalid calendar date rejected');
$bad=$f; $bad['properties']['timeseries'][1]['time']=$bad['properties']['timeseries'][0]['time'];
verifyMet(rensVejr($bad) === [], 'Duplicate timestamp rejected');
$bad=$f; $bad['properties']['timeseries'][0]['data']['instant']['details']['wind_speed']='2';
verifyMet(rensVejr($bad)[$keys[0]]['vind'] === null, 'String values rejected');
$bad=$f; unset($bad['properties']['timeseries'][0]['data']['next_1_hours']);
$bad['properties']['timeseries'][0]['data']['next_6_hours']=['details'=>['precipitation_amount'=>12]];
verifyMet(rensVejr($bad)[$keys[0]]['nedboer'] === null, 'Six-hour rain is not invented hourly data');
verifyMet(rensVejr(['type'=>'Coverage']) === [], 'Previous provider cache rejected');
foreach (['clearsky_night'=>'moon','clearsky_day'=>'sun','partlycloudy_night'=>'cloud-moon',
    'rain'=>'cloud-rain','heavysnow'=>'cloud-snow','sleet'=>'cloud-snow','fog'=>'cloud-fog',
    'rainandthunder'=>'cloud-lightning','cloudy'=>'cloud'] as $code=>$icon)
    verifyMet(vejrSymbol(['symbol'=>$code])[0] === $icon, 'MET symbol '.$code);
verifyMet(strpos(vejrFejlTekst(429),'429') !== false, 'Rate limit diagnostics');
verifyMet(strpos(vejrFejlTekst(0),'forbindelse') !== false, 'Connection diagnostics');
echo "OK: $checks MET checks\n";
