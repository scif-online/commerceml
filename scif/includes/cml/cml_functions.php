<?php
// Функции обмена данными по CommerceML.

// путь для файла записи лога планировщика
define('FILE_SYNC_CML',WN_PATH.'www_data/sync_cml_'.SCIF_BASE.'.inc');

$telegram=((isset($cml['telegram']) AND !empty($cml['telegram']['token']))?$cml['telegram']:false);

// получение и сохранение времени последней синхронизации
function last_sync_cml($data=false) {
global $now;
$arr=array('export'=>0,'import'=>0);
 if (file_exists(FILE_SYNC_CML)) {
 $arr=unserialize(file_get_contents(FILE_SYNC_CML));
 } else {
 $arr['structure']=cml_check_structure();
 }
 if (!$data) { // получаем данные
 return $arr;
 } else { // сохраняем
  foreach ($data AS $key=>$val) {
  $arr[$key]=$val;
  }
 file_put_contents(FILE_SYNC_CML,serialize($arr));
 }
}

// проверка наличия полей cml_id в таблицах СКИФ. При отсутствии создаём
// ALTER TABLE `wn_scif1_spr_prices` ADD `cml_id` VARCHAR( 36 ) NULL DEFAULT NULL , ADD UNIQUE (`cml_id`);
function cml_check_structure($type='VARCHAR(36)') {
global $db;
$div='';
$tables_scif=array('spr_noms','spr_noms_gr','spr_contrs','spr_values','spr_prices','doc'); // ,'spr_contrs','spr_finitems','orders','spr_contrs_gr'
 foreach ($tables_scif AS $table) {
  if (!$db->sql_num_rows($db->sql_query('SHOW COLUMNS FROM '.($table=='orders'?WN_CATALOG_ORDERS:SCIF_PREFIX.$table).' WHERE Field = "cml_id"'))) {
  $sql='ALTER TABLE `'
  .($table=='orders'?WN_CATALOG_ORDERS:SCIF_PREFIX.$table)
  .'` ADD `cml_id` '.$type.' NULL DEFAULT NULL,'
//  .($table=='spr_noms'?' ADD `cml_option_id` INT NULL DEFAULT NULL,':'')
  .($table=='spr_noms'?' ADD `cml_stock` DECIMAL(11,3) NOT NULL DEFAULT "0.000",':'')
  .' ADD UNIQUE('
  .($table=='spr_values'?'`property`,':'')
  .'`cml_id`'
//  .($table=='spr_noms'?',`cml_option_id`':'')
  .')';
  // $db->sql_query($sql);
  $div.=$sql.';'.PHP_EOL;
   if ($table=='doc') { // добавим также в поле doc_history
   //$db->sql_query('ALTER TABLE `'.SCIF_PREFIX.'doc_history` ADD `cml_id` '.$type.' NULL DEFAULT NULL');
   $div.='ALTER TABLE `'.SCIF_PREFIX.'doc_history` ADD `cml_id` '.$type.' NULL DEFAULT NULL;'.PHP_EOL;
   }
  }
 }
 /*
 if ($db->errors) {
 die('Не удалось создать поля "cml_id" в синхронизируемых таблицах СКИФ.<br>
 Проверьте наличие у пользователя прав на выполнение операций ALTER TABLE<br>'.$db->errors);
 }
 */
return $div;
}

// формат идентификаторов товаров: id_родителя#id_товара или id_товара
if (!function_exists('product_cml_id')) {
function product_cml_id($product_id,$parent_id='') {
 if ($parent_id) {
 return $parent_id.'#'.$product_id;
 } else {
 // вариант если нужно всегда выгружать в полном формате:
 // return $product_id.'#'.$product_id;
 return $product_id;
 }
}}

// генерация GUID для новых элементов
function cml_guid($key) {
return strtolower(trim(com_create_guid(), '{}'));
}

// заполнить ID =id или GUID =uuid() незаполненные поля cml_id
function cml_fill_id($tables) {
global $db, $cml;
$tables=explode(',',$tables);
 foreach ($tables AS $table) {
 $db->sql_query('UPDATE '.SCIF_PREFIX.$table.'
 SET cml_id='.(!empty($cml['cml_id_type'])?$cml['cml_id_type']:'id')
 .' WHERE cml_id IS NULL'
 .(!empty($cml['where_'.$table])?' AND '.$cml['where_'.$table]:''));
 }
}

// получим коды товаров из базы СКИФ по cml_id
// можно переопределить функцию на пользовательскую в wn_settings.php
if (!function_exists('cml_goods')) {
function cml_goods($goods) {
global $db;
$res=$db->sql_query('SELECT id, cml_id
FROM '.SCIF_PREFIX.'spr_noms
WHERE cml_id IN ("'.implode('","',array_keys($goods)).'")');
 while ($row=$db->sql_fetch_assoc($res)) {
 $goods[$row['cml_id']]['scif_id']=$row['id'];
 }
return $goods;
}}

// формирование строки товарного предложения
if (!function_exists('cml_offer')) {
function cml_offer() {
global $row, $cml, $prices;
  $xml='
  <Предложение>
   <Ид>'.product_cml_id($row['cml_id']).'</Ид>
   <Наименование>'.$row['name'].'</Наименование>
   <БазоваяЕдиница Код="796" НаименованиеПолное="Штука" МеждународноеСокращение="PCE">шт</БазоваяЕдиница>
   <Цены>';
    foreach ($cml['offer_prices'] AS $key) {
    $xml.='
    <Цена>
    <Представление>'.$row['price'.$key].' '.$prices[$key]['currency_name'].' за '.$row['unit'].'</Представление>
     <ИдТипаЦены>'.$prices[$key]['cml_id'].'</ИдТипаЦены>
     <ЦенаЗаЕдиницу>'.$row['price'.$key].'</ЦенаЗаЕдиницу>
     <Валюта>'.$prices[$key]['currency_name'].'</Валюта>
     <Единица>'.$row['unit'].'</Единица>
     <Коэффициент>1</Коэффициент>
    </Цена>';
    }
   $xml.='
   </Цены>
   <Количество>'.(float)$row['stock'].'</Количество>
  </Предложение>';
return $xml;
}}

// функция выполняемая после синхронизация
if (!function_exists('cml_sync_after')) {
function cml_sync_after() {
global $errors, $telegram, $cml_imported_docs;
// отправляем уведомление в Телеграм об импортированных заказах и ошибках
 if (!empty($telegram['notify_id'])) {
 $msg='';
  if (count($cml_imported_docs)) {
  $msg.='Импортированы заказы с сайта: '.implode(',',array_keys($cml_imported_docs));
  }
  if ($errors) {
  $msg.=($msg?PHP_EOL:'').'Ошибки импорта заказов с сайта: '.$errors;
  }
  if ($msg) {
  // проверим, чтобы при ошибке не слать каждый запуск одно и то же сообщение
  $file_last_msg=WN_PATH.'www_data/cml_last_msg.inc';
   if (!file($file_last_msg) OR file_get_contents($file_last_msg)!=$msg) {
   telegram_notify($msg);
   file_put_contents($file_last_msg,$msg);
   }
  }
 }
}}
