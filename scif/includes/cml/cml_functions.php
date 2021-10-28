<?php
// Функции обмена данными по CommerceML.
// При необходимости, замените в массиве ID на GUID
$cml=array(
'cml_id_type'=>'id', // тип формирования ID для новых элементов: 'id' - числовой код=ID элемента СКИФ или 'uuid()' - GUID
'class'=>array('id'=>'1','name'=>'Классификатор'), // классификатор
'owner'=>array('id'=>'1','name'=>'СКИФ','fullname'=>'СКИФ'), // владелец
'properties'=>array(
 2=>array('id'=>'2','name'=>'Бренд') // бренд (торговая марка)
 ),
'catalog'=>array('id'=>'1','name'=>'Основной каталог товаров'), // каталог
'offer'=>array('id'=>'1','name'=>'Пакет предложений (Основной каталог товаров)'), // пакет предложений
'offer_prices'=>array(2), // выгружаемые для пакета предложений типы цен
'currencies'=>array('643'=>array('name'=>'руб'),'840'=>array('name'=>'USD'),'978'=>array('name'=>'EUR'),'980'=>array('name'=>'грн')),
'offer_stores'=>array(1), // склады для количества товаров в пакете предложений
'where_spr_noms'=>'', // условие для выгрузки товаров и пакета предложений, например, n.store1>0 или n.parent IN (1,2)
// код группы в справочнике контрагентов в СКИФ, в которую будут добавляться покупатели интернет-магазина
'scif_contr_group'=>1,
// значения для создания документа в СКИФ при импорте заказа из магазина
// если задан contr, клиента берем из настроек, а не из файла
'invoice'=>array('user_insert'=>1,'org'=>1,'store'=>1,'manager'=>1,'type'=>2,'price_type'=>2),
'exchange_url'=>'https://a3.scif.online/shop/cml1c/7e842851-2ef4-4a10-b3a5-51d13881abd1/', // адрес для автоматического обмена, получите его в панели управления CMS интернет-магазина
);

// путь для файла записи лога планировщика
define('FILE_SYNC_CML',WN_PATH.'www_data/sync_сml_'.SCIF_BASE.'.inc');

// получение и сохранение времени последней синхронизации
function last_sync_cml($data=false) {
global $now;
$arr=array('export'=>0,'import'=>0);
 if (file_exists(FILE_SYNC_CML)) {
 $arr=unserialize(file_get_contents(FILE_SYNC_CML));
 } else { // первый запуск, создадим поля в базе данных
 cml_check_structure();
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
function cml_check_structure() {
global $db;
$tables_scif=array('spr_noms','spr_noms_gr','spr_contrs','spr_values','spr_prices','doc'); // ,'spr_contrs','spr_finitems','orders','spr_contrs_gr'
 foreach ($tables_scif AS $table) {
  if (!$db->sql_num_rows($db->sql_query('SHOW COLUMNS FROM '.($table=='orders'?WN_CATALOG_ORDERS:SCIF_PREFIX.$table).' WHERE Field = "cml_id"'))) {
  $sql='ALTER TABLE `'
  .($table=='orders'?WN_CATALOG_ORDERS:SCIF_PREFIX.$table)
  .'` ADD `cml_id` VARCHAR(36) NULL DEFAULT NULL,'
//  .($table=='spr_noms'?' ADD `cml_option_id` INT NULL DEFAULT NULL,':'')
  .($table=='spr_noms'?' ADD `cml_stock` DECIMAL(11,3) NOT NULL DEFAULT "0.000",':'')
  .' ADD UNIQUE('
  .($table=='spr_values'?'`property`,':'')
  .'`cml_id`'
//  .($table=='spr_noms'?',`cml_option_id`':'')
  .')';
  $db->sql_query($sql);
   if ($table=='doc') { // добавим также в поле doc_history
   $db->sql_query('ALTER TABLE `'.SCIF_PREFIX.'doc_history` ADD `cml_id` VARCHAR(36) NULL DEFAULT NULL');
   }
  }
 }
 if ($db->errors) {
 die('Не удалось создать поля "cml_id" в синхронизируемых таблицах СКИФ.<br>
 Проверьте наличие у пользователя прав на выполнение операций ALTER TABLE<br>'.$db->errors);
 }
}

// формат идентификаторов товаров: id_родителя#id_товара или id_товара
function product_cml_id($product_id,$parent_id='') {
 if ($parent_id) {
 return $parent_id.'#'.$product_id;
 } else {
 // вариант если нужно всегда выгружать в полном формате:
 // return $product_id.'#'.$product_id;
 return $product_id;
 }
}

// генерация GUID для новых элементов
function cml_guid($key) {
return strtolower(trim(com_create_guid(), '{}'));
}

// заполнить ID =id или GUID =uuid() незаполненные поля cml_id
function cml_fill_id($tables) {
global $db, $cml;
$tables=explode(',',$tables);
 foreach ($tables AS $table) {
 $db->sql_query('UPDATE '.SCIF_PREFIX.$table.' SET cml_id='.(!empty($cml['cml_id_type'])?$cml['cml_id_type']:'id').' WHERE cml_id IS NULL');
 }
}