<?php
$a = array();
$array = array('a'=>'1','b'=>'2');
array_push($a,$array);
array_push($a,array('a'=>'3','b'=>'4'));
$a =json_encode($a);
str_replace($a,'{','[');
str_replace($a,'}',']');
str_replace($a,':','=>');
echo $a;
?>