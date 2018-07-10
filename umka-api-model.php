<?php


class umkaApiModel { 
	
# рабочая касса
# url кассы
var $kktUrl = 'http://192.168.0.2:8088';
# логин и пароль кассира (по-умолчанию в умке логины от 1 до 99, пароль совпадает с логином). Юзер 99 - администратор
# Особенность. При смене паролей выяснилось, что умка принимает только цифровые пароли не более 8 символов
var $kktLogin = '1';
var $kktPassword = '1';
# используется при формировании sessionid. У каждого магазина должен быть уникальнм от нуля до 9999
# для разработки используем id > 9000
var $shopId = 1;
# в последствии выяснилось, что данный реквизит не обязателен (умка его берёт из данных регистрации кассы)
var $shopINN = 000000000000;

# тестовая касса
//var $kktUrl = 'http://office.armax.ru:58088';
//var $kktLogin = '1';
//var $kktPassword = '1';
//var $shopId = 1;
//var $shopINN = 000000000000;

var $debug = false;

var $kktHttpAuth;
# 0 - каcса не отвечает, 1 - отвечает. смена закрыта, 2 - отвечает и смена открыта
var $kktStatus = 0;
var $kktStatusDetail;

	

   //конструктор выполнится при инициализации 
   function __construct() { 
      $this->kktHttpAuth = $this->kktLogin.":".$this->kktPassword;
      $this->kktUrl = rtrim($this->kktUrl, '/');
   } 
   
   function init() {
      $status = $this->getKktStatus();
      if($status == 1) $this->cycleopen();
   }
   
   function getKktStatus() {
      $status = $this->cashboxstatus(); 
      if(is_array($status)) {
          $this->kktStatusDetail = $status;
          if($status['cashboxStatus']['fsStatus']['cycleIsOpen'] == 1) $result = 2;
          else $result = 1;
      } else {
          $result = 0;
      }
      
      $this->kktStatus = $result;
      return $result;
   }
   
   function cashboxstatus() {
      return $this->kktRequest('cashboxstatus.json');
   }

   function cycleopen() {
        return $this->kktRequest('cycleopen.json?print=1');
   }
   
   function cycleclose() {
        return $this->kktRequest('cycleclose.json?print=1');
   }
   
   function makeSessionId($checkNumber) {
        return str_pad($this->shopId, 4, '0', STR_PAD_LEFT).str_pad($checkNumber, 16, '0', STR_PAD_LEFT);
   }
   
   function makeFiscprop($data, $tag=false) {
        foreach ($data as $item) $result['fiscprops'][] = $item;
        if($tag) $result['tag'] = $tag;
        return $result;
   }
   
   function makeFiscpropPosition($position) {
        # признак способа расчёта 
        # 1 - Полная предварительная оплата до момента передачи предмета расчета «ПРЕДОПЛАТА 100%»
        # 2 - Частичная предварительная оплата до момента передачи предмета расчета «ПРЕДОПЛАТА»
        # 3 - аванс «АВАНС»
        # 4 - Полная оплата, в том числе с учетом аванса (предварительной оплаты) в момент передачи предмета расчета «ПОЛНЫЙ РАСЧЕТ»
        # есть ещё 5, 6, 7 (см. таблицу 28 в спецификации ФФД)
        $data[] = array('tag' => 1214, 'value' => 1);
        # признак предмета расчёта 
        # 1 - товар, 3 - работа, 4 - услуга (таблица 29)
        $data[] = array('tag' => 1212, 'value' => 4);
        # наименование предмета расчета (текст. до 128 символов)
        if ($position['Comment'] != "") $name = substr($position['NameShort'].' ('.$position['Comment'].')', 0, 128);
        else $name = substr($position['NameShort'], 0, 128);
        $data[] = array('tag' => 1030, 'value' => $name);
        # цена за единицу предмета расчета с учетом скидок и наценок
        # Передавать обязательно. В копейках
        $summPos = ($position['Summ']/$position['Amount'])*100;
        $data[] = array('tag' => 1079, 'value' => $summPos);
        # количество предмета расчета
        # Передавать обязательно. Строкой с 3 знаками после запятой.
        $data[] = array('tag' => 1023, 'value' => number_format($position['Amount'], 3, '.', ''));
        # ставка НДС  ( таблица 24) 
        # 1 - «НДС 18%», 2 - «НДС 10%», 3 - «НДС 18/118», 4 - «НДС 10/110», 5 - «НДС 0%», 6 - НДС не облагается
        $data[] = array('tag' => 1199, 'value' => 6);
        # стоимость предмета расчета с учетом скидок и наценок
        # Передавать не обязательно.
//        $data[] = array('tag' => 1043, 'value' => $position['Summ']*100);
        # единица измерения предмета расчета (текст , до 16 символов)
        $data[] = array('tag' => 1197, 'value' => $position['Measure']);
        
        # тег признака "Предмет расчёта" - 1059
        $result = $this->makeFiscprop($data, 1059);
        
        return $result;
   }
   
   function fiscalcheck($invoice, $positions) {
       
        $sessionId = $this->makeSessionId($invoice['ID']);
        
        # Уникальное ИД сессии (генерируется самостоятельно и должно быть уникальным для каждого чека)
        $request['document']['sessionId'] = $sessionId;
        # Флаг необходимости печати чека
        $request['document']['print'] = 1;
        $request['document']['data']['docName'] = 'Бланк строгой отчетности';
        # Тип документа (1. Продажа, 2.Возврат продажи, 4. Покупка, 5. Возврат покупки, 7. Коррекция прихода, 9. Коррекция расхода)
        $request['document']['data']['type'] = 1;
        # ТИП ОПЛАТЫ (1. Наличным, 2. Электронными, 3. Предоплата, 4. Постоплата, 5. Встречное предоставление)
        $request['document']['data']['moneyType'] = 2;
        # Сумма закрытия чека (может быть 0, если без сдачи) в копейках
        $request['document']['data']['sum'] = $invoice['Summ']*100;
        $request['document']['result'] = 0;
        
        # применяемая система налогообложения (применяется битовое значение) См. (номер бита - значение)
        # (0 - 1) - ОСН, (1 - 2) - УСН доход, (2 - 4) - УСН доход - расход, (3 - 8) - ЕНВД, (4 - 16) - ЕСН, (5 - 32) - Патент
        $request['document']['data']['fiscprops'][] = array('tag' => 1055, 'value' => 2);
        # регистрационный номер ККТ (20 символов, до установленной длины дополняется пробелами справа)
        # Берется из регистрационных данных в ФН. Если передавать в чеке, то чек будет оформлен 
        # только при совпадении переданного РНМ и РНМ, с которым касса зарегистрирована
//        $request['document']['data']['fiscprops'][] = array('tag' => 1037, 'value' => str_pad($this->kktStatusDetail['cashboxStatus']['regNumber'], 20, ' ', STR_PAD_RIGHT));
        # сумма по чеку (БСО) электронными 
        # Величина с учетом копеек, печатается в виде числа с фиксированной точкой (2 цифры после точки) в рублях - налоговая 
        # Обязательно передавать только при использовании нескольких типов оплат. Передается в копейках. - касса 
//        $request['document']['data']['fiscprops'][] = array('tag' => 1081, 'value' => $invoice['Summ']);
        # ИНН пользователя
        # Берется из регистрационных данных в ФН. Если передавать в чеке, то чек будет
        # оформлен только при совпадении переданного инн и инн, с которым касса зарегистрирована
//        $request['document']['data']['fiscprops'][] = array('tag' => 1018, 'value' => $this->shopINN);
        # признак расчета. 1 - <ПРИХОД>, 3 - <РАСХОД>, 2 - <ВОЗВРАТ ПРИХОДА>, 4 - <ВОЗВРАТ РАСХОДА>
        $request['document']['data']['fiscprops'][] = array('tag' => 1054, 'value' => 1);
        # телефон или электронный адрес покупателя
        $request['document']['data']['fiscprops'][] = array('tag' => 1008, 'value' => $invoice['Email']);
        # адрес сайта ФНС
        $request['document']['data']['fiscprops'][] = array('tag' => 1060, 'value' => $this->kktStatusDetail['cashboxStatus']['fnsSite']);
        # email отправителя чека
        # Передавать не нужно. Берется из регистрационных данных в ФН.
//        $request['document']['data']['fiscprops'][] = array('tag' => 1117, 'value' => $this->kktStatusDetail['cashboxStatus']['email']);
        
        
//        # наименование дополнительного реквизита пользователя
//        $data[] = array('tag' => 1085, 'value' => 'Служба поддержки');
//        # значение дополнительного реквизита пользователя
//        $data[] = array('tag' => 1086, 'value' => '8 800 77 55 771');
//        # упаковка фискального свойства с тегом 
//        # дополнительный реквизит пользователя (тег 1084)
//        $request['document']['data']['fiscprops'][] = $this->makeFiscprop($data, 1084);
//        unset($data);
        
        
        foreach($positions as $position) {
            $request['document']['data']['fiscprops'][] = $this->makeFiscpropPosition($position);
        }
        

      $request = json_encode($request, JSON_UNESCAPED_UNICODE);
      
      $answer = $this->kktRequest('fiscalcheck.json', $request);
      
  
      return $answer;
   }
   
   function kktRequest($request, $post=false) {
      $answer = $this->getUrl($this->kktUrl.'/'.$request, $post);
      $this->debugvar($answer);
      
      if(strlen($answer) > 10) {
          $answer = json_decode($answer, true);
          $this->debugvar($answer);
      }
      
      if(is_array($answer) && count($answer)> 0) return $answer;
      else {
          $this->error("kktRequest :: $request");
          return false;
      }
   }
   
   function debugvar($var) {
       if($this->debug) {
           echo "<br>\n debugvar umkaApiModel\n<br>\n";
           if(is_object($var) || is_array($var)) var_dump($var);
           else print_r($var."<br>\n<br>\n");
           
           flush();
       }
       return;
   }
   
   function error($info) {
       
       return;
   }
   
   
    function getUrl($url, $post_string=false) {
        $ch=curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if($post_string != "") { 
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string); 
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
                'Content-Type: application/json',                                                                                
                'Content-Length: ' . strlen($post_string))                                                                       
            );
        }
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->kktHttpAuth); 
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 5.1; rv:12.0) Gecko/20100101 Firefox/24.0");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        


        $content = curl_exec ($ch);
        curl_close ($ch);
        
        $this->debugvar($url);
        $this->debugvar($post_string);
        
        return $content;
    }
   

}
 
