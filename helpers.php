<?php
// $fix = 12 and above is not correct
if (!function_exists('number_to_string')) {
	function number_to_string($value, $fix = 11) {
		$string = bcdiv(number_format($value, $fix, '.', ''), 1, $fix);

		return $string = $fix > 0 ? rtrim(rtrim($string, '0'), '.') : $string;
	}
}

if (!function_exists('precision_length')) {
	function precision_length($number) {
		$array = explode('.', number_to_string($number));

		return isset($array[1]) ? (string) strlen($array[1]) : '0';
	}
}

// 3 => 0.001
if (!function_exists('precision_to_tick')) {
	function precision_to_tick($precision) {
		if ((int) $precision === 0) return '1';
		if ((int) $precision === 1) return '0.1';

		return number_format(0, (int) $precision - 1, '.', '') .'1';
	}
}