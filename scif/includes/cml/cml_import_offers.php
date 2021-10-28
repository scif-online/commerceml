<?php
// CommerceML Импорт Товаров, остатков и цен
defined('CML_INCLUDE_FOLDER') or die('Access denied');

// вставляем импортируемые данные во временную таблицу
function cml_temp_table($fields,$sql) {
global $db;
$sql_create='CREATE TEMPORARY TABLE `_cml` (';
 foreach ($fields AS $key=>$val) {
 $sql_create.='`'.$key.'` '.$val['sql'].',';
 }
$sql_create.=' UNIQUE KEY `cml_id` (`cml_id`))';
$db->sql_query($sql_create);
// DEBUG echo $sql;
$db->sql_query('INSERT IGNORE INTO _cml VALUES '.mb_substr($sql,0,-1));
return $db->sql_affected_rows();
}

// переносим данные из временной таблицы в СКИФ
function cml_insert($table,$fields) {
global $db, $now, $userdata;
unset($fields['parent']); // в parent у нас CML ID родителя, его нам вставлять не нужно
$fields_insert='`'.implode('`,`',array_keys($fields)).'`';
$db->sql_query('INSERT IGNORE INTO '.SCIF_PREFIX.$table
.' ('.$fields_insert.', date_insert, user_insert)
SELECT '.$fields_insert.', '.$now.', '.$userdata['id'].' FROM _cml');
// проверим, что не удалось вставить (нарушение уникальности)
$res=$db->sql_query('SELECT c.'.implode(',c.',array_keys($fields))
.' FROM _cml c
LEFT JOIN '.SCIF_PREFIX.$table.' s ON c.cml_id=s.cml_id
WHERE s.cml_id IS NULL');
$total_rows=$db->sql_num_rows($res);
 if ($total_rows) {
 echo error('Не удалось вставить строк в таблицу '.$table.': <b>'.$total_rows.'</b>, вероятно, из-за нарушения условий уникальности')
 .'<table class="border auto"><thead><tr class="sel_group">';
   foreach ($fields AS $key=>$val) {
   echo '<th>'.$key.'</th>';
   }
  echo '</thead><tbody>';
  while ($row=$db->sql_fetch_assoc($res)) {
  echo '<tr>';
   foreach ($fields AS $key=>$val) {
   echo '<td>'.$row[$key].'</td>';
   }
  echo '</tr>';
  }
 echo '</tbody></table>';
 }
}

// удаляем временную таблицу
function cml_temp_table_drop() {
global $db;
$db->sql_query('DROP TABLE `_cml`');
}

// рекурсивый обход групп
function cml_recursion($arr,$parent=0) {
global $items;
 foreach ($arr AS $val) {
 // для вставки по временную таблицу для простановки родителей
 // иногда в файле может быть $val->Родитель, но полагаться на это нельзя, поэтому передаем его как текущий id
 $id=(string)$val->Ид;
 $items[$id]=array('name'=>(string)$val->Наименование,'parent'=>$parent);
  if (!empty($val->Группы->Группа)) {
  cml_recursion($val->Группы->Группа,$id);
  }
 }
}

// проверим уникальный индекс parent_name и отключим его перед обновлениями
function check_parent_name_uniq($table,$operation='check') {
global $db;
 if ($operation=='check') { // проверяем и удаляем индекс
  if ($db->sql_num_rows($db->sql_query('SELECT CONSTRAINT_NAME
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_NAME = "'.SCIF_PREFIX.$table.'"
  AND CONSTRAINT_TYPE = "UNIQUE"
  AND CONSTRAINT_NAME = "parent_name"'))) {
  $db->sql_query('ALTER TABLE `'.SCIF_PREFIX.$table.'` DROP INDEX `parent_name`');
  return true;
  } else {
  return false;
  }
 } else { // возвращаем индекс
 $db->sql_query('ALTER TABLE `'.SCIF_PREFIX.$table.'` ADD UNIQUE `parent_name` (`parent`,`name`)');
 }
}

// ======================= Группы товаров ====================================
/*
if (!empty($obj->Каталог->Товары->Товар)) {
$fields=array(
'cml_id'=>array('sql'=>'varchar(36) NOT NULL'),
'name'=>array('sql'=>'varchar(100) NOT NULL DEFAULT ""'),
'chpu'=>array('sql'=>'varchar(255) DEFAULT NULL'),
'parent'=>array('sql'=>'varchar(36) NULL DEFAULT NULL'),
);
$items=array();
cml_recursion($obj->Классификатор->Группы->Группа);
 if (count($items)) { // если в файле есть группы
 // проверим уникальный индекс parent_name и отключим его перед обновлениями
 $parent_name_uniq=check_parent_name_uniq('spr_noms_gr');
 // готовим SQL-запрос
 $sql='';
  foreach ($items AS $cml_id=>$val) {
  // TODO если имя не уникально, нужно либо и ЧПУ делать неуникальным, либо формировать не по имени
  $sql.='("'.$cml_id.'","'.htmlclean($val['name']).'","'.translit_to_eng($val['name']).'","'.$val['parent'].'"),';
  }

 // вставляем во временную таблицу
 $affected=cml_temp_table($fields,$sql);
  if ($affected!=count($items)) {
  echo error('Не удалось добавить все группы. В файле: <b>'.count($items).'</b>, добавлено: <b>'.$affected.'</b>.
  Вероятно, в файле есть группы с повторяющимися ID');
  }

  // обновляем существующие элементы
  if ($update) {
  $db->sql_query('UPDATE '.SCIF_PREFIX.'spr_noms_gr s
  JOIN _cml o ON s.cml_id=o.cml_id
  SET s.name=o.name, date_update="'.$now.'", user_update="'.$userdata['id'].'"');
  }

  // добавляем новые элементы
  if ($insert) {
  cml_insert('spr_noms_gr',$fields);
  }

 // не проверяем $update и $insert т.к. новых могло не быть, а родители могли измениться
 // обновляем родителей в основной таблице
 $db->sql_query('UPDATE '.SCIF_PREFIX.'spr_noms_gr s
 JOIN _cml o ON s.cml_id=o.cml_id
 JOIN '.SCIF_PREFIX.'spr_noms_gr sp ON o.parent=sp.cml_id
 SET s.parent=sp.id');

 // удалим временную таблицу
 cml_temp_table_drop();

 // очищаем кэш дерева групп
 $cache_file=WN_PATH.'cache/includes/tree_'.SCIF_BASE.'_spr_noms_gr.inc';
  if (file_exists($cache_file)) { unlink($cache_file); }

 // если был уникальный индекс, вернем его
  if ($parent_name_uniq) {
  check_parent_name_uniq('spr_noms_gr','return');
  }
 }
} // не было групп в файле
*/

// ===================== Товары ==============================================
if (!empty($obj->Каталог->Товары->Товар)) {
$fields=array(
'cml_id'=>array('sql'=>'varchar(36) NOT NULL'),
'name'=>array('sql'=>'varchar(100) NOT NULL DEFAULT ""'),
'chpu'=>array('sql'=>'varchar(255) DEFAULT NULL'),
'parent'=>array('sql'=>'varchar(36) NULL DEFAULT NULL'),
'barcode'=>array('sql'=>'varchar(13) DEFAULT NULL'),
'vendorcode'=>array('sql'=>'varchar(13) NOT NULL DEFAULT ""'),
'desc'=>array('sql'=>'text NOT NULL')
);
// готовим SQL-запрос для вставки
$sql='';
$count=0;
$cml_ids=array();
$cml_ids_doubles=array();
 foreach ($obj->Каталог->Товары->Товар AS $val) {
 $count++;
 // TODO если имя не уникально, нужно либо и ЧПУ делать неуникальным, либо формировать не по имени
 $cml_id=(string)$val->Ид;
 $pos=strpos($cml_id,'#'); // в выгрузке опции товаров выгружаются в виде id_родителя#id_товара
  if ($pos) {
  $cml_id=substr($cml_id,($pos+1));
  }
  if (!in_array($cml_id,$cml_ids)) {
  $cml_ids[]=$cml_id;
  $barcode=trim((string)$val->Штрихкод);
  $sql.='("'.$cml_id.'","'
  .htmlclean((string)$val->Наименование).'","'
  .translit_to_eng((string)$val->Наименование).'","'
  .(string)$val->Группы->Ид[0].'",'
  .($barcode?'"'.$barcode.'"':'NULL').',"'
  .(string)$val->Артикул.'","'
  .addslashes(nl2br((string)$val->Описание)).'"),';
  // 'logo_url'=>(string)$val->Картинка
  } else {
  echo $cml_id.'<br>';
   if (!in_array($cml_id,$cml_ids_doubles)) {
   $cml_ids_doubles[]=$cml_id;
   }
  }
 }

 if (count($cml_ids_doubles)) {
 echo error('В файле присутствуют товары с дублирующимися ID: '.implode(',',$cml_ids_doubles));
 }

// проверим уникальный индекс parent_name и отключим его перед обновлениями
$parent_name_uniq=check_parent_name_uniq('spr_noms');

$affected=cml_temp_table($fields,$sql);
 if ($affected!=$count) {
 echo error('Не удалось добавить все товары. В файле: <b>'.$count.'</b>, добавлено: <b>'.$affected.'</b>.
 Вероятно, в файле есть товары с повторяющимися ID');
 }

 // обновляем существующие элементы
 if ($update) {
 $db->sql_query('UPDATE '.SCIF_PREFIX.'spr_noms s
 JOIN _cml o ON s.cml_id=o.cml_id
 SET s.name=o.name, date_update="'.$now.'", user_update="'.$userdata['id'].'"');
 }

 // добавляем новые элементы
 if ($insert) {
 cml_insert('spr_noms',$fields);
 }

// не проверяем $update и $insert т.к. новых могло не быть, а родители могли измениться
// обновляем родителей в основной таблице
$db->sql_query('UPDATE '.SCIF_PREFIX.'spr_noms s
JOIN _cml o ON s.cml_id=o.cml_id
JOIN '.SCIF_PREFIX.'spr_noms_gr sp ON o.parent=sp.cml_id
SET s.parent=sp.id');

cml_temp_table_drop(); // удалим временную таблицу

// если был уникальный индекс, вернем его
 if ($parent_name_uniq) {
 check_parent_name_uniq('spr_noms','return');
 }

} // не было товаров в файле

// ================= Предложения (цены) =======================================
if (!empty($obj->ИзмененияПакетаПредложений->Предложения->Предложение)) {

// есть пакет предложений, получим типы цен из первого предложения
$cml_prices_ids='';
 foreach ($obj->ИзмененияПакетаПредложений->Предложения->Предложение->Цены->Цена AS $val) {
 $cml_prices_ids.='"'.$val->ИдТипаЦены.'",';
 }
 if (!$cml_prices_ids) {
 echo error('Не удалось получить типы цен в "ИзмененияПакетаПредложений->Предложения->Предложение->Цены->Цена"');
 return;
 }
$cml_prices_ids=mb_substr($cml_prices_ids,0,-1);
$result=$db->sql_query('SELECT id, cml_id
FROM '.SCIF_PREFIX.'spr_prices
WHERE cml_id IN ('.$cml_prices_ids.')');
 if (!$db->sql_num_rows($result)) {
 echo error('Не удалось получить типы цен в базе СКИФ по следующим кодам: '.$cml_prices_ids.'
 <br>Проверьте и, при необходимости, проставьте коды в поле cml_id справочника типов цен `'.SCIF_PREFIX.'spr_prices`');
 return;
 }
$fields['cml_id']=array('sql'=>'varchar(36) NOT NULL');
 while ($row=$db->sql_fetch_assoc($result)) {
 $cml_prices[$row['cml_id']]=$row['id'];
 $fields['price'.$row['id']]=array('sql'=>'decimal(10,2) unsigned NOT NULL DEFAULT "0.00"');
 }

// готовим SQL
$sql='';
 foreach ($obj->ИзмененияПакетаПредложений->Предложения->Предложение AS $val) {
 // если нужно импортировать оприходование начальных остатков .(string)$val->Количество.'","'
 $sql.='("'.(string)$val->Ид.'"';
  foreach ($val->Цены->Цена AS $price) {
  $sql.=',"'.(string)$price->ЦенаЗаЕдиницу.'"';
  }
 $sql.='),';
 }
$affected=cml_temp_table($fields,$sql);

// обновляем цены в справочнике
$sql='UPDATE '.SCIF_PREFIX.'spr_noms s JOIN _cml o ON s.cml_id=o.cml_id SET ';
 foreach ($cml_prices AS $price) {
 $sql.='s.price'.$price.'=o.price'.$price.',';
 }
$db->sql_query(substr($sql,0,-1));
cml_temp_table_drop(); // удалим временную таблицу
} // нет предложений
//echo '<pre>'.print_r($items,true).'</pre>';