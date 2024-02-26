<?
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Entity\Base;
use Bitrix\Main\Localization\Loc;
use Bitrix\Highloadblock\HighloadBlockTable as HLBT;
use Bitrix\Main\UserTable;
use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\GroupTable;

Loc::loadMessages(__FILE__);

class sprint_migration extends CModule {
    
    private $MODULE_PATH;
    private $USERS_DATA = [
        ['login' => 'user1', 'name' => 'User One', 'email' => 'user1@example.com'],
        ['login' => 'user2', 'name' => 'User Two', 'email' => 'user2@example.com'],
        ['login' => 'user3', 'name' => 'User Three', 'email' => 'user3@example.com'],
    ];

    /**
     * sprint_migration constructor.
     */
    public function __construct() {
        $path = str_replace("\\", "/", __FILE__);
        $path = substr($path, 0, strlen($path) - strlen("/index.php"));
        $arModuleVersion = array();
        include($path. "/version.php");
        if (is_array($arModuleVersion) && array_key_exists("VERSION", $arModuleVersion))
        {
            $this->MODULE_ID = 'sprint.migration';
            $this->MODULE_VERSION = $arModuleVersion["VERSION"];
            $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
            $this->MODULE_NAME = Loc::getMessage("SPRINT_MIGRATION_MODULE_NAME");
            $this->MODULE_DESCRIPTION = Loc::getMessage("SPRINT_MIGRATION_MODULE_DESC");
            $this->MODULE_PATH = $path;
        }
    }

    /**
     * Установка модуля
     */
    public function DoInstall() {
        global $APPLICATION;

        if (!$this->CheckDependencies()) {
            $APPLICATION->ThrowException("Не выполнены требования к зависимостям модуля.");
            return false;
        }

        $createHL = $this->createHighloadBlock();
        if (!empty($createHL['error'])) {
            $APPLICATION->ThrowException("Ошибка при создании Highload-блока. {$createHL['error']}");
            return false;
        }

        $createUsers = $this->createUsers($this->USERS_DATA);
        if (!empty($createUsers['error'])) {
            $APPLICATION->ThrowException("Ошибка при создании пользователей. {$createUsers['error']}");
            return false;
        }

        $createCEvents = $this->createCalendarEvents($createUsers['result']);
        if (!empty($createCEvents['error'])) {
            $APPLICATION->ThrowException("Ошибка при создании событий календаря. {$createCEvents['error']}");
            return false;
        }
        
        ModuleManager::registerModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile("Установка модуля {$this->MODULE_ID}", $this->MODULE_PATH."/step.php");
    }

    /**
     * Удаление модуля
     */
    public function DoUninstall() {
        global $APPLICATION;

        $hlblockId = Option::get("sprint.migration", "SPRINT_MIGRATION_HL_ID");

        if (!Loader::includeModule('highloadblock')) {
            $APPLICATION->ThrowException("Модуль highloadblock не установлен.");
            return;
        }

        // Удаление Хайлод-блока
        if (!HLBT::delete($hlblockId)) {
            $APPLICATION->ThrowException("Не удалось удалить Highload-блок.");
            return;
        }

        // Удаление ID Хайлод-блока из b_option
        Option::delete("sprint.migration", array(
            "name" => "SPRINT_MIGRATION_HL_ID",
            "site_id" => ""
        ));

        foreach ($this->USERS_DATA as $userData) {
            $user = \Bitrix\Main\UserTable::getList([
                'filter' => ['=LOGIN' => $userData['login']],
                'select' => ['ID', 'LOGIN']
            ])->fetch();

            if ($user) {
                $userDeleteResult = \CUser::Delete($user['ID']);
                if (!$userDeleteResult) {
                    $APPLICATION->ThrowException("Ошибка при удалении пользователя с ID: " . $user['ID']);
                    return;
                }
            }
        }

        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    /**
     * Создание Highload-блока
     *
     * @return array
     */
    private function createHighloadBlock() {
        if (!Loader::includeModule('highloadblock')) {
            return ['error' => 'Модуль Highloadblock не установлен'];
        }

        $resultData = [
            'error' => '',
            'result' => 0,
        ];

        // Создание Highload-блока
        $data = array(
            'NAME' => 'Events',
            'TABLE_NAME' => 'hl_events',
        );

        $result = HLBT::add($data);
        if (!$result->isSuccess()) {
            $resultData['error'] = implode("; ", $result->getErrorMessages());
            return $resultData;
        }

        $highloadBlockId = $result->getId();

        Option::set("sprint.migration", "SPRINT_MIGRATION_HL_ID", $highloadBlockId);

        $resultData['result'] = $highloadBlockId;

        // Создание полей Highload-блока
        $oUserTypeEntity = new CUserTypeEntity();

        $userFieldResult = $this->addUserField($oUserTypeEntity, $highloadBlockId, 'UF_USER_ID', 'ID пользователя');
        if (!$userFieldResult) {
            $resultData['error'] = 'Ошибка при создании поля USER_ID';
            return $resultData;
        }

        $eventFieldResult = $this->addUserField($oUserTypeEntity, $highloadBlockId, 'UF_EVENT_ID', 'ID события');
        if (!$eventFieldResult) {
            $resultData['error'] = 'Ошибка при создании поля EVENT_ID';
            return $resultData;
        }

        return $resultData;
    }

    /**
     * Создание пользовательских полей для Highload-блока
     *
     * @param $oUserTypeEntity
     * @param $highloadBlockId
     * @param $fieldName
     * @param $labelRu
     * @return bool
     */
    private function addUserField($oUserTypeEntity, $highloadBlockId, $fieldName, $labelRu) {
        $aUserField = array(
            'ENTITY_ID'         => 'HLBLOCK_' . $highloadBlockId,
            'FIELD_NAME'        => $fieldName,
            'USER_TYPE_ID'      => 'integer',
            'XML_ID'            => 'XML_ID_' . $fieldName,
            'SORT'              => 100,
            'MULTIPLE'          => 'N',
            'MANDATORY'         => 'N',
            'SHOW_FILTER'       => 'N',
            'SHOW_IN_LIST'      => '',
            'EDIT_IN_LIST'      => '',
            'IS_SEARCHABLE'     => 'N',
            'SETTINGS'          => array(
                'DEFAULT_VALUE' => '',
            ),
            'EDIT_FORM_LABEL'   => array(
                'ru'    => $labelRu,
            ),
            'LIST_COLUMN_LABEL' => array(
                'ru'    => $labelRu,
            ),
            'LIST_FILTER_LABEL' => array(
                'ru'    => $labelRu,
            ),
            'ERROR_MESSAGE'     => array(
                'ru'    => 'Ошибка при заполнении пользовательского свойства ' . $labelRu,
            ),
            'HELP_MESSAGE'      => array(
                'ru'    => '',
            ),
        );

        $result = $oUserTypeEntity->Add($aUserField);
        return $result !== false;
    }

    /**
     * Создание пользователей
     *
     * @param $usersInfo
     * @return array
     */
    private function createUsers($usersInfo) {

        // Получение необходимых ID групп
        $groupData = GroupTable::getList([
            'select' => ['ID', 'NAME'],
            'filter' => ['%STRING_ID' => ['EMPLOYEES', 'RATING_VOTE']]
        ])->fetchAll();
        $groupIds = [];
        foreach ($groupData as $groupFields){
            $groupIds[] = $groupFields['ID'];
        }

        $result = array();

        foreach ($usersInfo as $userInfo) {
            $user = new CUser;
            $arFields = array(
                "NAME"              => $userInfo['name'],
                "EMAIL"             => $userInfo['email'],
                "LOGIN"             => $userInfo['login'],
                "LID"               => "ru",
                "ACTIVE"            => "Y",
                "GROUP_ID"          => $groupIds,
                "PASSWORD"          => "123456",
                "CONFIRM_PASSWORD"  => "123456",
            );

            $ID = $user->Add($arFields);
            if (intval($ID) <= 0) {
                $result["error"] = "Не удалось добавить пользователя {$userInfo['login']}. Ошибка: " . $user->LAST_ERROR;
            } else {
                $result["result"][] = $ID;
            }
        }
        return $result;
    }

    /**
     * Создание событий календаря
     *
     * @param $users
     * @return array
     */
    private function createCalendarEvents($users) {
        $result = [];
        if (!Loader::includeModule('calendar')){
            return ['error' => 'Модуль calendar не установлен'];
        }
        for ($i = 1; $i <= 5; $i++) {
            $randomKey = array_rand($users);
            $userId = $users[$randomKey];

            $createEvent = CCalendar::SaveEvent(array(
                'arFields' => array(
                    "CAL_TYPE" => 'user',
                    "OWNER_ID" => $userId,
                    "NAME" => "Событие №$i для пользователя с ID равным {$userId}",
                    "DT_FROM" => date("d.m.Y H:i:s", strtotime("+$i day")),
                    "DT_TO" => date("d.m.Y H:i:s", strtotime("+".($i+1)." day")),
                    "SKIP_TIME" => "N",
                )
            ));
            if ($createEvent === false) {
                return ['error' => "Не удалось создать событие $i для пользователя с ID {$userId}."];
            } else {
                $result["result"][] = true;
            }
        }
        return $result;
    }

    /**
     * Проверка модулей
     *
     * @return bool
     */
    private function CheckDependencies() {
        if (!IsModuleInstalled('main')) {
            return false;
        }
        if (!IsModuleInstalled('calendar')) {
            return false;
        }
        if (!IsModuleInstalled('highloadblock')) {
            return false;
        }

        return true;
    }
}
?>