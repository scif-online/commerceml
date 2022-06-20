<?php
// Обмен данными с интернет-магазином по формату CommerceML

// DEBUG если запускается из браузера, включаем режим отладки
$debug=(!empty($_SERVER['REMOTE_ADDR'])?true:false);
if ($debug) {
ini_set('display_errors',1);
ini_set('error_reporting', E_ALL); // E_STRICT
}

define('WEBNICE','CRON');
define('WN_PATH',str_replace('cron','',dirname(__FILE__)));
require WN_PATH.'includes/config.php';
require WN_PATH.'wn_settings.php';
require WN_PATH.'includes/'.$wn_connect.'.php';
require WN_PATH.'includes/functions.php';

define('SCIF_BASE',SCIF_CATALOG_BASE); // укажите нужную базу для синхронизации: 'scif1'
define('SCIF_PREFIX',WN_PREFIX.SCIF_BASE.'_');

define('CML_INCLUDE_FOLDER',WN_PATH.'scif/includes/cml/');
require CML_INCLUDE_FOLDER.'cml_functions.php';

$last_sync_cml=last_sync_cml(); // даты последней синхронизации
$now=time();

require CML_INCLUDE_FOLDER.'cml_sync.php';

if ($debug) {
echo '<pre>';
print_r($last_sync_cml);
echo '</pre>';
}