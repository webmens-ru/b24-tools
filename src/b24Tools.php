<?php

namespace wm\b24tools;

use Bitrix24\Bitrix24;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use wm\admin\models\User;
use Yii;
use yii\base\BaseObject;
use yii\helpers\ArrayHelper;
use yii\web\HttpException;

/**
 * Description of b24connector
 *
 * @author Админ
 */
class b24Tools extends \yii\base\BaseObject
{

    /**
     * @var
     */
    private $b24PortalTable;
    /**
     * @var
     */
    private $arAccessParams;
    /**
     * @var string
     */
    private $b24_error = '';
    /**
     * @var
     */
    private $arB24App;
    /**
     * @var
     */
    private $arScope;
    /**
     * @var
     */
    private $applicationId;
    /**
     * @var
     */
    private $applicationSecret;

    /**
     * @param Bitrix24 $obB24
     * @param string $newPortalName
     */
    public static function setOnPortalRenamed($obB24, $newPortalName)
    {
        if ($obB24->getDomain() != $newPortalName) {
            Yii::$app->db
                ->createCommand()
                ->update(Yii::$app->params['b24PortalTable'],
                    [
                        'PORTAL' => $newPortalName
                    ],
                    [
                        'PORTAL' => $obB24->getDomain(),
                        'MEMBER_ID' => $obB24->getMemberId(),
                    ]
                )
                ->execute();
        }
    }

    /**
     * @param $domain
     * @return array|false|\yii\db\DataReader
     * @throws \yii\db\Exception
     */
    private function getAuthFromDB($domain)
    {
        $res = Yii::$app->db
            ->createCommand("SELECT * FROM " . $this->b24PortalTable . " WHERE PORTAL = '" . $domain . "'")
            ->queryOne();
        return $res;
    }

    /**
     * Добавление авторизационных данных в таблибу БД
     * @param $tableName
     * @param $auth
     * @return int
     * @throws \yii\db\Exception
     */
    public function addAuthToDB($tableName, $auth)
    {
        //TODO: Подумать над использованием моделей
        $item = (new \yii\db\Query())
            ->select('PORTAL')
            ->from($tableName)
            ->where([
                'PORTAL' => $auth['domain']
            ])
            ->one();

        if ($item) {
            $res = Yii::$app->db
                ->createCommand()
                ->update($tableName, [
                    'ACCESS_TOKEN' => $auth['access_token'],
                    'REFRESH_TOKEN' => $auth['refresh_token'],
                    'MEMBER_ID' => $auth['member_id'],
                    'DATE' => date("Y-m-d"),
                ],
                    ['PORTAL' => $auth['domain']]
                )
                ->execute();
        } else {
            $res = Yii::$app->db
                ->createCommand()
                ->insert($tableName, [
                        'PORTAL' => $auth['domain'],
                        'ACCESS_TOKEN' => $auth['access_token'],
                        'REFRESH_TOKEN' => $auth['refresh_token'],
                        'MEMBER_ID' => $auth['member_id'],
                        'DATE' => date("Y-m-d"),
                    ]
                )
                ->execute();
        }
        return $res;
    }

    /**
     * Обновление авторизационных данных в БД
     * @param $auth
     * @return int
     * @throws \yii\db\Exception
     */
    public function updateAuthToDB($auth)
    {
        if ($this->b24PortalTable) {
            $res = Yii::$app->db
                ->createCommand()
                ->update($this->b24PortalTable, [
                    'ACCESS_TOKEN' => $auth['access_token'],
                    'REFRESH_TOKEN' => $auth['refresh_token'],
                    'DATE' => date("Y-m-d"),
                ], ['PORTAL' => $auth['domain'],
                        'MEMBER_ID' => $auth['member_id'],]
                )
                ->execute();
            return $res;
        }
    }

    /**
     * @param $arAccessParams
     * @return array
     */
    private function prepareFromDB($arAccessParams)
    {
        $arResult = array();
        $arResult['domain'] = $arAccessParams['PORTAL'];
        $arResult['member_id'] = $arAccessParams['MEMBER_ID'];
        $arResult['refresh_token'] = $arAccessParams['REFRESH_TOKEN'];
        $arResult['access_token'] = $arAccessParams['ACCESS_TOKEN'];
        return $arResult;
    }

    /**
     * Разбор данных полученных от Битрикс24 в виде AJAX запроса
     * @param $arRequest
     * @return array
     */
    public function prepareFromAjaxRequest($arRequest)
    {
        $arResult = array();
        $arResult['domain'] = $arRequest['domain'];
        $arResult['member_id'] = $arRequest['member_id'];
        $arResult['refresh_token'] = $arRequest['refresh_token'];
        $arResult['access_token'] = $arRequest['access_token'];
        $this->arAccessParams = $arResult;
        return $arResult;
    }

    /**
     * Разбор данных полученных от Битрикс24
     * @param $arRequest
     * @return array
     */
    public function prepareFromHandlerRequest($arRequest)
    {
        $arResult = array();
        $arResult['domain'] = $arRequest['domain'];
        $arResult['member_id'] = $arRequest['member_id'];
        $arResult['access_token'] = $arRequest['access_token'];
        $arResult['refresh_token'] = ' ';
        $this->arAccessParams = $arResult;
        return $arResult;
    }

    /**
     * @param null $arRequestPost
     * @param null $arRequestGet
     * @return array
     */
    public function prepareFromRequest($arRequestPost = null, $arRequestGet = null)
    {
        if (!$arRequestPost or !$arRequestGet) {
            return array();
        }
        $arResult = array();
        $arResult['domain'] = $arRequestGet['DOMAIN'];
        $arResult['member_id'] = $arRequestPost['member_id'];
        $arResult['refresh_token'] = $arRequestPost['REFRESH_ID'];
        $arResult['access_token'] = $arRequestPost['AUTH_ID'];
        $this->arAccessParams = $arResult;
        return $arResult;
    }

    /**
     * Проверка Авторизационных данных
     * @param array $arScope
     * @return bool
     * @throws \yii\db\Exception
     */
    public function checkB24Auth($arScope = array())
    {

        if (!is_array($arScope)) {
            $arScope = array();
        }
        if (!in_array('user', $arScope)) {
            $arScope[] = 'user';
        }

        // проверяем актуальность доступа
        $isTokenRefreshed = false;

        $this->arB24App = $this->getBitrix24(
            $this->arAccessParams,
            $isTokenRefreshed,
            $this->b24_error, $arScope,
            [
                '\wm\b24tools\b24Tools',
                'setOnPortalRenamed'
            ]
        );
        if ($isTokenRefreshed and $this->b24PortalTable) {
            $this->updateAuthToDB($this->arAccessParams);
        }
        return $this->b24_error === true;
    }

    /**
     * @param $arAccessData
     * @param $btokenRefreshed
     * @param $errorMessage
     * @param array $arScope
     * @return \Bitrix24\Bitrix24
     * @throws \Bitrix24\Exceptions\Bitrix24Exception
     * @throws \yii\db\Exception
     */
    private function getBitrix24(
        &$arAccessData,
        &$btokenRefreshed,
        &$errorMessage,
        $arScope = array(),
        $onPortalRenamed = null
    )
    {
        $log = new Logger('bitrix24');
        if (Yii::$app->params['logPath']) {
            $baseLogPath = Yii::$app->params['logPath'];
        } else {
            $baseLogPath = '../log/';
            Yii::error('В params не найден параметр logPath');
        }
        $loggerStream = $baseLogPath . date('Y_m_d') . '/' . $arAccessData['domain'] . '.log';
        $isLoggingInfo = in_array($arAccessData['domain'], Yii::$app->params['extendedLoggerPortal']);
        $loggerLevel = ($isLoggingInfo) ? Logger::INFO : Logger::DEBUG;
        $log->pushHandler(new StreamHandler($loggerStream, $loggerLevel));

        $btokenRefreshed = null;

        $obB24App = new \Bitrix24\Bitrix24(false, $log);
        if (!is_array($arScope)) {
            $arScope = array();
        }
        if (!in_array('user', $arScope)) {
            $arScope[] = 'user';
        }
        $obB24App->setApplicationScope($arScope);
        $obB24App->setApplicationId($this->applicationId);
        $obB24App->setApplicationSecret($this->applicationSecret);
        $obB24App->setOnPortalRenamed(['\wm\b24tools\b24Tools', 'setOnPortalRenamed']);

        // set user-specific settings
        $obB24App->setDomain($arAccessData['domain']);
        $obB24App->setMemberId($arAccessData['member_id']);
        $obB24App->setRefreshToken($arAccessData['refresh_token']);
        $obB24App->setAccessToken($arAccessData['access_token']);
        $obB24App->setOnExpiredToken(
            function ($objB24) {
                $objB24->setRedirectUri('https://oauth.bitrix.info/rest/');
                try {
                    $result = $objB24->getNewAccessToken();
                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();
                    Yii::warning('getNewAccessToken exception error: ' . $errorMessage, 'b24Tools');
                }

                if (ArrayHelper::getValue($result, 'access_token')) {
                    $arAccessData['refresh_token'] = $result['refresh_token'];
                    $arAccessData['access_token'] = $result['access_token'];
                    $objB24->setRefreshToken($arAccessData['refresh_token']);
                    $objB24->setAccessToken($arAccessData['access_token']);
                    $this->updateAuthToDB($this->arAccessParams);
                    return true;
                }
                return false;
            }
        );
        try {
            $resExpire = $obB24App->isAccessTokenExpire();
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Yii::warning('Access-expired exception error: ' . $errorMessage, 'b24Tools');
        }
        if ($resExpire) {
            Yii::warning('Access - expired', 'b24Tools');
            $obB24App->setRedirectUri('https://oauth.bitrix.info/rest/');

            try {
                $result = $obB24App->getNewAccessToken();
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                Yii::warning('getNewAccessToken exception error: ' . $errorMessage, 'b24Tools');
            }

            if ($result === false) {
                $errorMessage = 'access denied';
            } elseif (is_array($result) && array_key_exists('access_token', $result) && !empty($result['access_token'])) {
                $arAccessData['refresh_token'] = $result['refresh_token'];
                $arAccessData['access_token'] = $result['access_token'];
                $obB24App->setRefreshToken($arAccessData['refresh_token']);
                $obB24App->setAccessToken($arAccessData['access_token']);
                // \cnLog::Add('Access - refreshed');
                $this->updateAuthToDB($this->arAccessParams);
                //Yii::warning('Access - refreshed', 'b24Tools');
                $btokenRefreshed = true;
            } else {
                $btokenRefreshed = false;
            }
        } else {
            $btokenRefreshed = false;
        }
        return $obB24App;
    }

    /**
     * Соединение с Битрикс24
     * @param $applicationId
     * @param $applicationSecret
     * @param string $tableName
     * @param null $domain
     * @param array $arScope
     * @param null $autch
     * @return false|\Bitrix24\Bitrix24
     * @throws \yii\db\Exception
     */
    public function connect($applicationId, $applicationSecret, $tableName = '', $domain = null, $arScope = array(), $autch = null)
    {
        $this->applicationId = $applicationId;
        $this->applicationSecret = $applicationSecret;
        $this->b24PortalTable = $tableName;
        if ($autch === null) {
            $res = $this->getAuthFromDB($domain); //Нужно добавить проверку res             
            if (!$res) {
                Yii::error('getAuthFromDB(' . $domain . ')=false');
                return false;
            }

            $this->arAccessParams = $this->prepareFromDB($res);
        } else {
            $this->arAccessParams = $autch;
        }
        $this->b24_error = $this->checkB24Auth($arScope);
        if ($this->b24_error != '') {
            Yii::error('DB auth error: ' . $this->b24_error);
            return false;
        }
        return $this->arB24App;
    }

    /**
     * @param $auth
     * @return false|\Bitrix24\Bitrix24
     * @throws \yii\db\Exception
     */
    public function connectFromUser($auth)
    {
        $b24App = $this->connect(
            Yii::$app->params['applicationId'],
            Yii::$app->params['applicationSecret'],
            null,
            $auth['domain'],
            null, $auth
        );
        return $b24App;
    }


    /**
     * @param string $portalName
     * @return false|\Bitrix24\Bitrix24
     */
    public function connectFromAdmin($portalName = '')
    {
        if (!$portalName && !$portalName = Yii::$app->params['portalName']) {
            $userId = \Yii::$app->user->id;
            $portalName = User::getPortalName($userId);
        }
        $b24App = $this->connect(
            Yii::$app->params['applicationId'],
            Yii::$app->params['applicationSecret'],
            Yii::$app->params['b24PortalTable'],
            $portalName);
        return $b24App;
    }

    /**
     * @param $data
     * @return string
     */
    public static function toBool($data)
    {
        return $data ? 'Y' : 'N';
    }

    /**
     * @param $data
     * @return bool
     * @throws HttpException
     */
    public static function isEventOnline($data)
    {
        if (!ArrayHelper::keyExists('offline', $data, false)) {
            throw new HttpException(404, 'Data "offline" not found');
        }
        return !(bool)ArrayHelper::getValue($data, 'offline');
    }

    /**
     * @param $data
     * @return bool
     * @throws HttpException
     */
    public static function isEventOffline($data)
    {
        if (!ArrayHelper::keyExists('offline', $data, false)) {
            throw new HttpException(404, 'Data "offline" not found');
        }
        return (bool)ArrayHelper::getValue($data, 'offline');
    }

}
