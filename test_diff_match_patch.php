<?php

include('diff_match_patch.php');
mb_internal_encoding("iso-8859-1");

$mastertext = utf8_encode("esto es una prueba de texto\n\n\nahora va una eñe a continu");
$shadow = utf8_encode("esto es una prueba de texto\n\n\nahora va una");

//ponemos una e\xc3\xb1e, ahora viene el ca
//ponemos una e\xc3\xb1e, ahora viene el cachondeo
//$mastertext = utf8_encode("");
//$shadow = utf8_encode("");

$mastertext = utf8_decode("ponemos una eñe, ahora viene el cachondeo");
$shadow = utf8_decode("ponemos una eñe, ahora viene el ca");

echo "\nMASTER: $mastertext\n==========================\n";
echo "\nSHADOW: $shadow\n==========================\n\n";

$dmp = new diff_match_patch();
$diffs = $dmp->diff_main($shadow, $mastertext);

print_r($diffs);
$text = $dmp->diff_toDelta($diffs);
print_r($text);

mb_internal_encoding("UTF-8");

$mastertext = "ponemos una eñe, ahora viene el cachondeo";
$shadow = "ponemos una eñe, ahora viene el ca";

echo "\nMASTER: $mastertext\n==========================\n";
echo "\nSHADOW: $shadow\n==========================\n\n";

$dmp = new diff_match_patch();
$diffs = $dmp->diff_main($shadow, $mastertext);

print_r($diffs);
$text = $dmp->diff_toDelta($diffs);
print_r($text);
?>