<?php
/* Обмен данными с интернет-магазином по формату CommerceML
https://v8.1c.ru/tekhnologii/obmen-dannymi-i-integratsiya/standarty-i-formaty/protokol-obmena-s-saytom/#2

1. выгрузка на сайт торговых предложений (каталогов продукции), данных об остатках и ценах
2. получение с сайта информации о заказах

1. Начало сеанса. Отправляем http-запрос следующего вида:
http://<сайт>/<путь>/?type=catalog&mode=checkauth или type=sale
В ответ система управления сайтом передает три строки (используется разделитель строк «\n»):
 слово «success»;
 имя Cookie;
 значение Cookie.
Все последующие запросы к системе управления сайтом должны содержать в заголовке запроса имя и значение Cookie.
2. Запрос параметров от сайта. Отправляем запрос следующего вида:
http://<сайт>/<путь>/?type=catalog&mode=init
В ответ система управления сайтом передает две строки:
 zip=yes, если сервер поддерживает обмен в zip-формате — в этом случае на следующем шаге файлы должны быть упакованы в zip-формате
 или
 zip=no — в этом случае на следующем шаге файлы не упаковываются и передаются каждый по отдельности.
 file_limit=<число>, где <число> — максимально допустимый размер файла в байтах для передачи за один запрос. Если системе «1С: Предприятие» понадобится передать файл большего размера, его следует разделить на фрагменты.
3. Выгрузка на сайт файлов обмена. Отправляем запросы с параметрами вида
http://<сайт>/<путь>/?type=catalog&mode=file&filename=<имя файла>
выгружает на сайт файлы обмена в формате CommerceML 2, посылая содержимое файла или его части в виде POST.
В случае успешной записи файла система управления сайтом выдает строку «success».
4. Пошаговая загрузка данных. Производится пошаговая загрузка данных по запросу с параметрами вида http://<сайт>/<путь>/?type=catalog&mode=import&filename=<имя файла>
Во время загрузки система управления сайтом может отвечать в одном из следующих вариантов.
 Если в первой строке содержится слово «progress» — это означает необходимость послать тот же запрос еще раз. В этом случае во второй строке будет возвращен текущий статус обработки, объем  загруженных данных, статус импорта и т. д.
 Если в ответ передается строка со словом «success», то это будет означать сообщение об успешном окончании обработки файла.
Примечание. Если в ходе какого-либо запроса произошла ошибка, то в первой строке ответа системы управления сайтом будет содержаться слово «failure», а в следующих строках — описание ошибки, произошедшей в процессе обработки запроса. Если произошла необрабатываемая ошибка уровня ядра продукта или sql-запроса, то будет возвращен html-код.
*/

 if (empty($cml['exchange_url'])) {
 die('Не задан адрес обмена $cml["exchange_url"]');
 }
$cml['exchange_url'].=(strpos($cml['exchange_url'],'?')?'&':'?');

$errors='';
$cml_exchange_cookie=false;
$is_cml_sync=true; // укажем скрипту экспорта, что возвращать нужно не файл на скачивание, а xml

// запросы к сайту
function cml_exchange_query($url,$method='GET',$content=false) {
global $cml_exchange_cookie;
$opts=array('http'=>array('method'=>$method,'header'=>$cml_exchange_cookie));
 if ($content) {
 $opts['http']['header'].="Content-Type: application/x-www-form-urlencoded\r\n";
 $opts['http']['content']=$content;
 }
return file_get_contents($url,false,stream_context_create($opts));
}

// инициализация обмена данными с сайтом type=catalog или sale
function cml_exchange_init($type='sale') {
global $cml, $errors, $cml_exchange_cookie;
 try {
 // Шаг 1. CMS должна вернуть три строки через разделитель \n: слово success; имя Cookie; значение Cookie.
 $url=$cml['exchange_url'].'type='.$type.'&mode=checkauth';
  if (!empty($cml['username']) AND !empty($cml['password'])) {
  $page=file_get_contents($url,false,stream_context_create(array('http'=>array('header'=>'Authorization: Basic '.base64_encode($cml['username'].':'.$cml['password'])))));
  } else {
  $page=file_get_contents($url);
  }
  if (!$page) { throw new Exception('Не получен ответ от сайта по адресу '.$url); }
 $arr_page=explode("\n",$page);
  if (empty($arr_page[0]) OR trim($arr_page[0])!='success' OR empty($arr_page[1]) OR empty($arr_page[2])) {
  throw new Exception('От сайта на запрос '.$url.' не получен корректный ответ. Ожидается: success; имя Cookie; значение Cookie.
  Получено: '.PHP_EOL.print_r($arr_page,true));
  }
 $cml_exchange_cookie="Cookie: ".trim($arr_page[1])."=".trim($arr_page[2])."\r\n";
 /* Запрос параметров от сайта
 $url=$cml['exchange_url'].'type='.$type.'&mode=init';
 $page=file_get_contents($url,false,stream_context_create(array('http'=>array('method'=>"GET",'header'=>$cml_exchange_cookie))));
  if (!$page) { throw new Exception('Не получены параметры обмена от сайта по адресу '.$url); }
 */
 return true;
 } catch (Exception $e) {
 $errors.=$e->getMessage();
 return false;
 }
}

// ====== Обмен данных о заказах (импортируем с сайта, передаем на сайт изменение статусов) =====
$cml_imported_docs=array();
$cml_docs=0;
 if (cml_exchange_init('sale')) {
 $url=$cml['exchange_url'].'type=sale&mode=query';
 $page=cml_exchange_query($url,'GET');
 // debug
 file_put_contents(WN_PATH.'cache/cml_'.date('YmdHis').'.inc',$page);
  if ($page) {
  $obj=simplexml_load_string($page);
   if (!empty($obj->Документ)) {
   $cml_docs=count($obj->Документ);
   require CML_INCLUDE_FOLDER.'cml_import_orders.php';
   }
   // если не было ошибок, отправляем сайту уведомление об успешном завершении
   if (!$errors) {
   $url=$cml['exchange_url'].'type=sale&mode=success';
   $page=cml_exchange_query($url,'GET');
   }
  // TODO отправка сайту файла обмена (информация об изменении статуса заказа)
  // $url=$cml['exchange_url'].'type=sale&mode=file&filename=<имя файла>';
  $last_sync_cml['import']=$now;
  } else {
  $errors.='Не получен XML-файл по запросу '.$url.'<br>';
  }
 }

// ===== Передаем на сайт номенклатуру import, остатки и цены offers =======
$params=$cml['exchange_params']; // что выгружаем (здесь из настроек массива, в интерфейсе из формы)
 if (!empty($params['import']) OR !empty($params['offers'])) { // Классификатор или Предложения
  if (cml_exchange_init('catalog')) {
  require CML_INCLUDE_FOLDER.'cml_export.php';
  $filename.='_'.date('dmyHis').'.xml';
   if (!empty($debug)) {
   echo '<textarea style="width:100%">'.$xml.'</textarea>';
   }
  $url=$cml['exchange_url'].'type=catalog&mode=file&filename='.$filename;
  $page=cml_exchange_query($url,'POST',$xml);
   if ($page AND mb_substr(trim($page),0,7)=='success') {
   // файл успешно сохранен на сайте, отправляем команды его обработать
   $limit=10;
   $page='progress';
   $url=$cml['exchange_url'].'type=catalog&mode=import&filename='.$filename;
    while (mb_substr(trim($page),0,8)=='progress') {
    $page=cml_exchange_query($url);
    //echo '<div>'.date('H:i:s').': '.iconv('windows-1251','utf-8',$page).'</div>';
    // echo $page; Возвращает Complete. Imported elements: 1
    sleep(1);
    $limit--;
     if (!$limit) {
     break;
     }
    }
   $last_sync_cml['export']=$now;
   }
  }
 }

 if ($errors) {
 $last_sync_cml['error']=1;
 $last_sync_cml['message']='Ошибки: '.$errors;
 } else { // без ошибок
 $last_sync_cml['error']=0;
 $last_sync_cml['message']='Заказов в файле: '.$cml_docs.'. Импортировано: '.count($cml_imported_docs);
 }
last_sync_cml($last_sync_cml);

cml_sync_after();