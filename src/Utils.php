<?php

namespace wm\b24tools;

use wm\admin\models\B24ConnectSettings;
use yii\base\BaseObject;
use Yii;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use yii\helpers\ArrayHelper;
use yii\web\HttpException;

class Utils extends \yii\base\BaseObject {

    public static function getNumbersFromFieldPhoneData($b24PhonesFieldValue)
    {
        $phones = ArrayHelper::getColumn($b24PhonesFieldValue, 'VALUE');
        $replacePhones = [];
        foreach ($phones as $phone) {
            $replacePhones[] = preg_replace(['/^(\+7|8)/', '/[^\d]/'], ['7'], $phone);
        }
        return array_unique($replacePhones);
    }


}
