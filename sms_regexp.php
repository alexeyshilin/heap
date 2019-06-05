<?php

$sms_text = 'Пароль: 5526
Спишется 1039,77р.
Перевод на счет 4100175017397';

function get_sms_values($text)
{
	\preg_match_all('/(((?<![\d])(?P<amount>\d{1,7}([,.]\d{1,2})?)(\s*руб|р.))|((?<![\d])(?P<account>\d{11,20})(?![\d]))|((?<![\d])(?P<pass>\d{4,10})(?![\d])))/', $text, $matches);

	$f = function($arr) { foreach($arr as $key => $val) if($val != "") return $val; };
	return array($f($matches['amount']), $f($matches['account']), $f($matches['pass']));
}

list($amount, $account, $pass) = get_sms_values($sms_text);
