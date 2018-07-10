<?php
  include_once 'umka-api-model.php';

  $umka = new umkaApiModel();
  $umka->init();
  

      
      $invoice = unserialize(file_get_contents('serialize-inv.txt'));
      
     var_dump($invoice);
      
       $positions = unserialize(file_get_contents('serialize-pos.txt'));
       
      var_dump($positions);
       
       $umka->fiscalcheck($invoice, $positions);
  
  
      
  
  
