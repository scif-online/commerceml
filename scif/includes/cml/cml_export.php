<?php
// CommerceML Экспорт
defined('CML_INCLUDE_FOLDER') or die('Access denied');

$filename='';
$xml='';
$only_changes=((!empty($params['only_changes']) AND !empty($time_last_cml['export']))?$time_last_cml['export']:false);
if (!empty($params['import'])) { // Классификатор
 // заполним cml_id в справочниках. Цены не заполняем, нужно вручную проставить cml_id выгружаемым типам цен, взяв их ID из выгрузки магазина
 if (!empty($cml['export_new_spr'])) {
 cml_fill_id('spr_noms,spr_noms_gr,spr_values');
 }

$filename='import';
$xml.='
<Классификатор СодержитТолькоИзменения="'.($only_changes?'true':'false').'">
 <Ид>'.$cml['class']['id'].'</Ид>
 <ИдКлассификатора>'.$cml['class']['id'].'</ИдКлассификатора>
 <Наименование>'.$cml['class']['name'].'</Наименование>
 <Владелец>
  <Ид>'.$cml['owner']['id'].'</Ид>
  <Наименование>'.$cml['owner']['name'].'</Наименование>
  <ПолноеНаименование>'.$cml['owner']['fullname'].'</ПолноеНаименование>';
  /*
  <ИНН></ИНН>
  <ОКПО></ОКПО>
  <РасчетныеСчета>
   <РасчетныйСчет>
    <НомерСчета></НомерСчета>
    <Банк>
     <Наименование></Наименование>
     <БИК></БИК>
    </Банк>
   </РасчетныйСчет>
  </РасчетныеСчета>
  */
$xml.='
 </Владелец>
 <Группы>';
// дерево групп
$tree=new Tree(SCIF_BASE.'_spr_noms_gr');
function my_view_tree($parent) {
global $tree;
$div='';
 foreach ($tree->tree_parent[$parent] AS $key=>$items) {
 $div.='
 <Группа>
  <Ид>'.$items['id'].'</Ид>
  <Наименование>'.$items['name'].'</Наименование>'
  .($parent?PHP_EOL.'<Родитель>'.$parent.'</Родитель>':'');
  if (isset($tree->tree_parent[$items['id']])) {
  $div.=PHP_EOL.'<Группы>';
  $div.=my_view_tree($items['id']);
  $div.=PHP_EOL.'</Группы>';
  }
 $div.=PHP_EOL.'</Группа>';
 }
return $div;
}
$xml.=my_view_tree(0);
$xml.='
 </Группы>';
 if (isset($cml['properties'])) {
 $res=$db->sql_query('SELECT property, name, cml_id
 FROM '.SCIF_PREFIX.'spr_values
 WHERE property IN ('.implode(',',array_keys($cml['properties'])).')
 AND cml_id IS NOT NULL
 ORDER BY property');
  if ($db->sql_num_rows($res)) {
  $xml.='
  <Свойства>';
  $property=0;
   while ($row=$db->sql_fetch_assoc($res)) {
    if ($property!=$row['property']) {
     if ($property) {
     $xml.='</ВариантыЗначений>
     <ДляТоваров>true</ДляТоваров>
     </Свойство>';
     }
    $xml.='<Свойство>
    <Ид>'.$cml['properties'][$row['property']]['id'].'</Ид>
    <Наименование>'.$cml['properties'][$row['property']]['name'].'</Наименование>
    <ТипЗначений>Справочник</ТипЗначений>
    <ВариантыЗначений>';
    $property=$row['property'];
    }
   $xml.='
   <Справочник>
   <ИдЗначения>'.$row['cml_id'].'</ИдЗначения>
   <Значение>'.$row['name'].'</Значение>
   </Справочник>';
   }
  $xml.='
     </ВариантыЗначений>
    <ДляТоваров>true</ДляТоваров>
   </Свойство>
  </Свойства>';
  }
 }
 $xml.='
</Классификатор>
<Каталог СодержитТолькоИзменения="'.($only_changes?'true':'false').'">
 <Ид>'.$cml['catalog']['id'].'</Ид>
 <ИдКлассификатора>'.$cml['class']['id'].'</ИдКлассификатора>
 <Наименование>'.$cml['catalog']['name'].'</Наименование>
 <Товары>';
  $sql_join='';
  $sql='SELECT n.name, n.vendorcode, n.desc, n.cml_id, g.cml_id AS parent_cml_id';
   if (isset($cml['properties'])) { // свойства
    foreach ($cml['properties'] AS $key=>$val) {
    $sql.=', v'.$key.'.cml_id AS property'.$key;
    $sql_join.=' LEFT JOIN '.SCIF_PREFIX.'spr_values v'.$key.' ON n.property'.$key.'=v'.$key.'.id';
    }
   }
  $sql.=' FROM '.SCIF_PREFIX.'spr_noms n
  LEFT JOIN '.SCIF_PREFIX.'spr_noms_gr g ON n.parent=g.id'
  .$sql_join
  .' WHERE n.cml_id IS NOT NULL'
  .(!empty($cml['where_spr_noms'])?' AND '.$cml['where_spr_noms']:'')
  .($only_changes?' AND (n.date_insert>='.$only_changes.' OR n.date_update>='.$only_changes.')':'');
  $res=$db->sql_query($sql);
   if ($db->sql_num_rows($res)) {
    while ($row=$db->sql_fetch_assoc($res)) {
    $xml.='
    <Товар>
     <Ид>'.product_cml_id($row['cml_id']).'</Ид>
     <Артикул>'.$row['vendorcode'].'</Артикул>
     <Наименование>'.$row['name'].'</Наименование>
     <БазоваяЕдиница Код="796" НаименованиеПолное="Штука" МеждународноеСокращение="PCE">шт</БазоваяЕдиница>
     <Группы>
      <Ид>'.$row['parent_cml_id'].'</Ид>
     </Группы>
     <Описание>'.htmlclean(strip_tags($row['desc'])).'</Описание>
     <ЗначенияСвойств>';
      if (isset($cml['properties'])) {
       foreach ($cml['properties'] AS $key=>$val) {
       $xml.='
       <ЗначенияСвойства>
        <Ид>'.$val['id'].'</Ид>
        <Значение>'.$row['property'.$key].'</Значение>
       </ЗначенияСвойства>';
       }
      }
     $xml.='
     </ЗначенияСвойств>';
     /*
     <ЗначенияРеквизитов>
      <ЗначениеРеквизита>
       <Наименование>ВидНоменклатуры</Наименование>
       <Значение>Товар</Значение>
      </ЗначениеРеквизита>
      <ЗначениеРеквизита>
       <Наименование>ТипНоменклатуры</Наименование>
     	 <Значение>Товар</Значение>
      </ЗначениеРеквизита>
     </ЗначенияРеквизитов>
     */
    $xml.='
    </Товар>';
    }
   }
 $xml.='
 </Товары>
</Каталог>';
}

if (!empty($params['offers'])) { // Пакет предложений
$filename.=($filename?'_':'').'offers';
$xml.='
<ПакетПредложений СодержитТолькоИзменения="'.($only_changes?'true':'false').'">
 <Ид>'.$cml['offer']['id'].'</Ид>
 <Наименование>'.$cml['offer']['name'].'</Наименование>
 <ИдКаталога>'.$cml['catalog']['id'].'</ИдКаталога>
 <ИдКлассификатора>'.$cml['class']['id'].'</ИдКлассификатора>
 <Владелец>
  <Ид>'.$cml['owner']['id'].'</Ид>
  <Наименование>'.$cml['owner']['name'].'</Наименование>
  <ПолноеНаименование>'.$cml['owner']['fullname'].'</ПолноеНаименование>
 </Владелец>
 <ТипыЦен>';
  if (!isset($cml['offer_prices'])) { // выгружаемые типы цен
  $cml['offer_prices']=array_keys($prices);
  }
 $res=$db->sql_query('SELECT id, name, currency, cml_id
 FROM '.SCIF_PREFIX.'spr_prices
 WHERE id IN ('.implode(',',$cml['offer_prices']).')');
  while ($row=$db->sql_fetch_assoc($res)) {
  $xml.='
  <ТипЦены>
   <Ид>'.$row['cml_id'].'</Ид>
   <Наименование>'.$row['name'].'</Наименование>
   <Валюта>'.$cml['currencies'][$row['currency']]['name'].'</Валюта>
    <Налог>
     <Наименование>НДС</Наименование>
     <УчтеноВСумме>true</УчтеноВСумме>
   </Налог>
  </ТипЦены>';
  $prices[$row['id']]['currency_name']=$cml['currencies'][$row['currency']]['name'];
  $prices[$row['id']]['cml_id']=$row['cml_id'];
  }
 $xml.='
 </ТипыЦен>
 <Предложения>';
 $sql='SELECT n.name, n.unit, n.cml_id, n.price'.implode(',n.price',$cml['offer_prices']).', '.$cml['sql_store'].' AS stock
 FROM '.SCIF_PREFIX.'spr_noms n
 WHERE n.cml_id IS NOT NULL'
 .(!empty($cml['where_spr_noms'])?' AND '.$cml['where_spr_noms']:'')
 .($only_changes?' AND (n.date_update>='.$only_changes.' OR '.$cml['sql_store'].'!=n.cml_stock)':'');
 $res=$db->sql_query($sql);
  while ($row=$db->sql_fetch_assoc($res)) {
  $xml.=cml_offer();
  }
 $xml.='
 </Предложения>
</ПакетПредложений>';
// сохраним выгруженные остатки, чтобы при следующей выгрузке иметь возможность отобрать только изменившиеся
$db->sql_query('UPDATE '.SCIF_PREFIX.'spr_noms n SET n.cml_stock='.$cml['sql_store'].' WHERE n.cml_id IS NOT NULL'
.(!empty($cml['where_spr_noms'])?' AND '.$cml['where_spr_noms']:''));
}

$xml='<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация ВерсияСхемы="2.05" ДатаФормирования="'.date('Y-m-d').'T'.date('H:i:s').'">
'.$xml.'
</КоммерческаяИнформация>';

// DEBUG echo '<textarea style="width:100%">'.$xml.'</textarea>';

if (!empty($is_cml_sync)) { return; } // выполнение синхронизации, возвращаем назад $xml, а не файл

last_sync_cml(array('export'=>$now)); // сохраняем время последнего экспорта
ob_end_clean();
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename='.$filename.'.xml');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
echo $xml;
antiddos_end(false);
exit;