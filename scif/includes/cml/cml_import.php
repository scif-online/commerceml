<?php
// CommerceML Импорт
defined('CML_INCLUDE_FOLDER') or die('Access denied');

 if (!is_uploaded_file($_FILES['file_import']['tmp_name']) OR !$_FILES['file_import']['size']) {
 echo error('Не удалось загрузить файл '.$_FILES['file_import']['name'].' (код ошибки '.$_FILES['file_import']['error'].')');
 return;
 }

$errors='';
$obj=simplexml_load_file($_FILES['file_import']['tmp_name']);

//echo '<pre>'.print_r($obj,true).'</pre>';

// ============= Группы товаров, Товары или Предложения (цены) ==============
if (!empty($obj->Каталог) OR !empty($obj->ИзмененияПакетаПредложений)) {
require CML_INCLUDE_FOLDER.'cml_import_offers.php';
}

// ================= Заказы =======================================
if (!empty($obj->Документ)) {
require CML_INCLUDE_FOLDER.'cml_import_orders.php';
}

if ($errors) {
echo '<div class="alert alert-danger">'.$errors.'</div>';
} else { // ошибок не было, сохраняем время последнего импорта
last_sync_cml(array('import'=>$now));
}