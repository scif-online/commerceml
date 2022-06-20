<?php
// CommerceML Импорт Заказов
defined('CML_INCLUDE_FOLDER') or die('Access denied');
//echo '<pre>'.print_r($obj,true).'</pre>';
$date_insert=time();
 foreach ($obj->Документ AS $val) {
 $err='';
 $doc_id=(string)$val->Ид;
 // проверим, нет ли такого документа в СКИФ
  if ($db->sql_num_rows($db->sql_query('SELECT id FROM '.SCIF_PREFIX.'doc WHERE cml_id="'.$doc_id.'"'))) {
  // echo 'Заказ '.$doc_id.' уже импортирован!<br>';
  continue;
  }

 // товары в документе
  if (!empty($val->Товары->Товар)) {
  $goods=array(); $summa=0;
   foreach ($val->Товары->Товар AS $nv) {
   $cml_id=(string)$nv->Ид;
   $pos=strpos($cml_id,'#'); // в выгрузке опции товаров выгружаются в виде id_родителя#id_товара
    if ($pos) {
    $cml_id=substr($cml_id,($pos+1));
    }
   $price=floatval((string)$nv->ЦенаЗаЕдиницу);
   $quant=floatval((string)$nv->Количество);
   $summa+=round($quant*$price);
   $goods[$cml_id]=array('price'=>$price,'quant'=>$quant,'name'=>(string)$nv->Наименование);
   }
  // получим коды товаров из базы СКИФ по cml_id
  $goods=cml_goods($goods);
  // print_r($goods); echo $summa.'<br>';
  // составляем SQL для вставки в таблицу деталей документа
  $invoice_sql='';
   foreach ($goods AS $gk=>$gv) {
    if (empty($gv['scif_id'])) {
    $err.='Не найден товар ['.$gk.'] '.$gv['name'].' для импорта заказа №'.$doc_id.PHP_EOL;
    } else {
    $invoice_sql.='("###","'.$gv['scif_id'].'","'.$gv['quant'].'","'.$gv['price'].'",'.$date_insert.',"'.$cml['invoice']['user_insert'].'"),';
    }
   }
  } else {
  $err.='Нет данных о товарах в документе '.$doc_id;
  }
  if ($err) {
  $errors.=$err;
  continue;
  }
 // echo $invoice_sql;

  // данные о клиенте
  if (!empty($cml['invoice']['contr'])) { // клиента берем из настроек, а не из файла
  $contr_id=$cml['invoice']['contr'];
  } else {
  $contr=$val->Контрагенты->Контрагент;
  $cml_id=(string)$contr->Ид;
  $contr_name=htmlclean((string)$contr->Наименование);
  $email=$phone='';
  $sql='name="'.$contr_name.'",
  parent="'.$cml['scif_contr_group'].'",
  fullname="'.htmlclean((string)$contr->ПолноеНаименование).'",
  address="'.htmlclean((string)(!empty($contr->Адрес->Представление)?$contr->Адрес->Представление:$contr->АдресРегистрации->Представление)).'"';
   // Email и телефон могут содержаться как в Контактах, так и в самом Контрагенте
   if (!empty($contr->Контакты->Контакт)) {
    foreach ($contr->Контакты->Контакт AS $cv) {
     if ((string)$cv->Тип=='Почта') {
     $email=htmlclean((string)$cv->Значение);
     $sql.=',email="'.$email.'"';
     } elseif ((string)$cv->Тип=='ТелефонРабочий') {
     $phone=htmlclean((string)$cv->Значение);
     $sql.=',phone="'.$phone.'"';
     }
    }
   }

   if (!$email AND !empty($contr->email->Представление)) {
   $email=htmlclean((string)$contr->email->Представление);
   }
   if (!$phone AND !empty($contr->Телефон->Представление)) {
   $phone=htmlclean((string)$contr->Телефон->Представление);
   }

   if ($email  AND filter_var($email,FILTER_VALIDATE_EMAIL)) {
   $sql.=',email="'.$email.'"';
   } else {
   $email='';
   }
   if ($phone AND preg_match('#[\d]+#',$phone)) {
   $phone=preg_replace('#[^\d\+]+#','',$phone);
   $phone=substr($phone,-(!empty($cml['phone_length'])?$cml['phone_length']:10));
   $sql.=',phone="'.$phone.'"';
   } else {
   $phone='';
   }

  // проверим, есть ли уже в СКИФ клиент с таким cml_id или name
  $contr_id=false;
  $check_contr=$db->sql_fetch_assoc($db->sql_query('SELECT id, cml_id FROM '.SCIF_PREFIX.'spr_contrs WHERE cml_id="'.$cml_id.'"'));
   if ($check_contr) { // уже есть
   $contr_id=$check_contr['id'];
   } else { // проверим по названию или email
   $check_contr=$db->sql_fetch_assoc($db->sql_query('SELECT id, cml_id FROM '.SCIF_PREFIX.'spr_contrs WHERE name="'.$contr_name.'"'
   .($email?' OR email="'.$email.'"':'')
   .($phone?' OR phone LIKE "%'.preg_replace('#(\d)#','$1%',$phone).'"':'')));
    if ($check_contr) {
    $contr_id=$check_contr['id'];
    // внесем cml_id в базу
    $db->sql_query('UPDATE '.SCIF_PREFIX.'spr_contrs SET cml_id="'.$cml_id.'" WHERE id="'.$contr_id.'"');
    } else { // клиента в СКИФ нет, создаем
     if ($db->sql_query('INSERT INTO '.SCIF_PREFIX.'spr_contrs
     SET '.$sql.', user_insert="'.$cml['invoice']['user_insert'].'", date_insert="'.$date_insert.'", cml_id="'.$cml_id.'"')) {
     $contr_id=$db->sql_insert_id();
     } else {
     $errors.='Не удалось создать контрагента для документа '.$cml_id.', возможно, значения полей не уникальны: '.$sql;
     continue;
     }
    }
   }
  }

  if (!$contr_id) {
  $errors.='Не удалось определить контрагента для документа '.$cml_id;
  continue;
  }
 // клиент получен, можем создавать документ
 $doc_date=trim((string)$val->Дата).' '.(!empty($val->Время)?trim((string)$val->Время):date('H:i:s'));
 $sql_doc='INSERT INTO '.SCIF_PREFIX.'doc SET
 `date_insert`='.$date_insert.',
 `doc_date`="'.$doc_date.'",
 `summa`="'.$summa.'",
 `note`="'.htmlclean((string)$val->Комментарий).'",
 `cml_id`="'.$doc_id.'"';
  if (empty($cml['invoice']['contr'])) {
  $sql_doc.=', `contr`="'.$contr_id.'"';
  }
  foreach ($cml['invoice'] AS $ik=>$iv) {
  $sql_doc.=', `'.$ik.'`="'.$iv.'"';
  }
  if ($db->sql_query($sql_doc)) {
  $scif_id=$db->sql_insert_id();
   if ($scif_id) { // добавляем товары (детали документа)
   $invoice_sql=str_replace('###',$scif_id,$invoice_sql); // подставляем номер документа
    if ($db->sql_query('INSERT INTO '.SCIF_PREFIX.'doc_det (doc_id,nom_id,quant,price,date_insert,user_insert) VALUES '.mb_substr($invoice_sql,0,-1))) {
     if ($cml['invoice']['type']==2) { // документ Продажа, списываем остатки товаров на складе и увеличиваем задолженность клиента
     recalc_remains('after',2,$scif_id,$cml['invoice']['store'],0,$contr_id,$summa);
     }
    $cml_imported_docs[$doc_id]=array('id'=>$scif_id,'summa'=>$summa);
    } else {
    $errors.='Ошибка добавления товаров в документ!'.$invoice_sql;
    }
   } else {
   $errors.='Не удалось создать документ '.$doc_id;
   }
  } else {
  $errors.='Не удалось создать документ! Возможно, документ с номером '.$doc_id.' уже существует! '.$sql_doc.' '.$db->sql_error();
  }
// echo $sql.'<pre>'.print_r($val,true).'</pre>';
 }