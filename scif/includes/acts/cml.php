<?php
// CommerceML
$page_title='Обмен данными CommerceML';
$view_menu=true;
$help='<a href="https://www.webnice.biz/catalog/product/commerceml/" target="_blank">Страница модуля в каталоге расширений</a>';

if (!my_access('act',$act)) { return; }

define('CML_INCLUDE_FOLDER',dirname(dirname(__FILE__)).'/cml/');
require CML_INCLUDE_FOLDER.'cml_functions.php';

$meta='
<style>
.ui-tabs-panel { border: 1px solid #9CB8E2 !important; }
</style>
<script>
$(document).ready(function() {
$("#tabs").tabs();
});
</script>';


echo '<fieldset>Данная процедура позволяет импортировать и экспортировать данные в формате CommerceML.</fieldset><br>';

$update=(!empty($_REQUEST['update'])?true:false);
$insert=(!empty($_REQUEST['insert'])?true:false);
 if (!$update AND !$insert) {
 $update=$insert=true;
 }

$last_sync_cml=last_sync_cml();
$now=time();

if (!empty($_POST['submit_sync'])) { // синхронизация
require CML_INCLUDE_FOLDER.'cml_sync.php';
} elseif (!empty($_POST['submit_export'])) { // экспорт в файл
$params=$_POST;
require CML_INCLUDE_FOLDER.'cml_export.php';
} elseif (!empty($_POST['submit_import']) AND !empty($_FILES['file_import']['name'])) { // импорт файла
require CML_INCLUDE_FOLDER.'cml_import.php';
}

$time_import=(!empty($last_sync_cml['import'])?$last_sync_cml['import']:0);
$time_export=(!empty($last_sync_cml['export'])?$last_sync_cml['export']:0);
$time_sync=max($time_import,$time_export);

 // файл лога отсутствует, синхронизация еще не запускалась, выведем SQL для добавления полей
 if (!empty($last_sync_cml['structure'])) {
 echo '<div class="gap2"><div class="tips">Создайте поля в
 <a href="'.WN_HOME.'admin/phpmyadmin/'.(!empty($wn_dbname)?'?db='.$wn_dbname:'').'" target="_blank">базе данных</a> (при необходимости, замените тип поля VARCHAR(36) на нужный,
 если ваш магазин использует другой формат для идентификатора)</div>
 <textarea class="width100" rows="5">'.$last_sync_cml['structure'].'</textarea>
 </div>';
 }

 // сообщение синхронизации
 if (!empty($last_sync_cml['message'])) {
 echo '<div class="alert alert-'.(empty($last_sync_cml['error'])?'success':'danger').'">
 Последняя синхронизация '.date('d.m.Y H:i',$time_sync).'. '.$last_sync_cml['message'].'</div>';
 }

echo '<form id="formData" method="post" class="form-controls" enctype="multipart/form-data">
<div id="tabs" style="border:0">
 <ul>';
  if ($time_import OR $time_export) {
  echo '<li><a href="#tabs-sync"><i class="si si_refresh"></i> Синхронизация</a></li>';
  }
  echo '
  <li><a href="#tabs-import"><i class="si si_store_plus"></i> Импорт</a></li>
  <li><a href="#tabs-export"><i class="si si_store_minus"></i> Экспорт</a></li>
 </ul>';

 if ($time_sync) {
 echo '
 <div id="tabs-sync">
 <div class="margin tips">Синхронизировать данные СКИФ с интернет-магазином.
 Время последней синхронизации '.date('d.m.Y H:i',$time_sync)
 .'</div>
 <div class="margin">import - Классификатор (товары и группы) - '.(!empty($cml['exchange_params']['import'])?'ДА':'НЕТ').'<br>
   offers - Пакет предложений (остатки и цены) - '.(!empty($cml['exchange_params']['offers'])?'ДА':'НЕТ').'<br>
   Передавать только изменения - '.(!empty($cml['exchange_params']['only_changes'])?'ДА':'НЕТ')
 .'</div>
 <div style="margin-top:20px"><input type="submit" name="submit_sync" value="Синхронизировать" class="button"></div>
 </div>';
 }

 echo '
 <div id="tabs-import">
 <div class="margin tips">Загрузить данные в СКИФ из интернет-магазина или другой системы.'
 .($time_import?' Время последнего импорта '.date('d.m.Y H:i',$time_import):'')
 .'</div>
  <div class="margin">
  <input type="file" name="file_import">
  </div>
  <label><input type="checkbox" name="update"'.($update?' checked':'').'> - обновлять существующие</label><br>
  <label><input type="checkbox" name="insert"'.($insert?' checked':'').'> - добавлять новые</label>
  <div style="margin-top:20px"><input type="submit" name="submit_import" value="Импортировать" class="button"></div>
 </div>

 <div id="tabs-export">
 <div class="margin tips">Выгрузить данные из СКИФ для импорта в интернет-магазине.'
 .($time_export?' Время последнего экспорта '.date('d.m.Y H:i',$time_export):'')
 .'</div>
  <div class="margin">
  <label><input type="checkbox" name="import" checked> - товары</label><br>
  <label><input type="checkbox" name="offers" checked> - предложения (остатки и цены)</label>
  </div>';
  if ($time_export) {
  echo '
  <div class="margin">
  <label><input type="radio" name="only_changes" value="1" checked> - только изменения с '.date('d.m.Y H:i',$time_export).'</label><br>
  <label><input type="radio" name="only_changes" value="0"> - полностью</label>
  </div>';
  }
  echo '
  <div style="margin-top:20px"><input type="submit" name="submit_export" value="Экспортировать" class="button"></div>
 </div>

</div>
</form>';