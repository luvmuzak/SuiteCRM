<?php

$app->group('/V9',function() use ($app){
    $app->get('/server_info','SuiteCRM\Api\V9\Controller\UtilityController:getServerInfo');
    $app->get('/login','SuiteCRM\Api\V9\Controller\UtilityController:login');
});