<?php
/**
 * WHAT DATE DOES A SCREEN ACTUALLY RENDER?
 *
 * MAIN_DATE_FORMAT / MAIN_DATE_FORMAT_SHORT / MAIN_DATETIME_FORMAT were written
 * to llx_const and are read by NOTHING in Dolibarr: dol_print_date() resolves its
 * format from the LANGUAGE PACK via $outputlangs->trans("FormatDateShort")
 * (core/lib/functions.lib.php:3937). The database looked configured while every
 * screen still rendered American dates.
 *
 * So this asserts the RENDERED STRING, not the constant. Trusting llx_const is
 * precisely the mistake being guarded against.
 *
 * 31 December is used deliberately: day 31 cannot be mistaken for a month, so
 * day-first vs month-first is unambiguous. en_US would print 12/31/2026.
 *
 * Prints: <rendered day>|<jQuery datepicker format>
 */
define('NOSESSION', '1');
require_once '/var/www/html/master.inc.php';
global $conf;

$l = new Translate('', $conf);
$l->setDefaultLang(getDolGlobalString('MAIN_LANG_DEFAULT'));
$l->load('main');

// en_US ships TWO different American formats - %m/%d/%Y for text and mm/dd/yy for
// the datepicker - which is how 09/14/2026 and 12/31/27 appeared on one screen.
echo dol_print_date(mktime(0, 0, 0, 12, 31, 2026), 'day', false, $l)
   . '|' . $l->trans('FormatDateShortJQuery') . "\n";
