<?php
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
namespace wm\b24tools;

use Yii;
use yii\base\BootstrapInterface;

class Bootstrap implements BootstrapInterface
{

    //Метод, который вызывается автоматически при каждом запросе
    public function bootstrap($app)
    {
        $app->setComponents(['b24Tools' => ['class' => 'app\components\b24Tools',]]);
    }
}
