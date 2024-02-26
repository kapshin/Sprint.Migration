use Bitrix\Main\Loader;
use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Highloadblock as HL;
use Bitrix\Main\Entity;
use Bitrix\Main\Config\Option;

function CheckCEventsAndAddToHL() {
    if (!Loader::includeModule('highloadblock') || !Loader::includeModule('calendar') || !Loader::includeModule('im')) {
        return "CheckCEventsAndAddToHL();";
    }
	$hlblockId = Option::get("sprint.migration", "SPRINT_MIGRATION_HL_ID");
	$hlblock = HL\HighloadBlockTable::getById($hlblockId)->fetch();
	$entity = HL\HighloadBlockTable::compileEntity($hlblock);
	$entity_data_class = $entity->getDataClass();
	
	$query = new Entity\Query(EventTable::class);
	$query->setSelect([
		'ID',
		'NAME',
		'OWNER_ID',
	]);
	$query->setFilter(array(
		"HL_EVENT_TABLE.UF_EVENT_ID" => null
	));
	$query->registerRuntimeField('HL_EVENT_TABLE', array(
		'data_type' => $entity,
		'reference' => array('=this.ID' => 'ref.UF_EVENT_ID'),
		'join_type' => 'LEFT',
	));
	
	$result = $query->exec();


	$createCount = 0;
	$errorCount = 0;
	while ($row = $result->fetch()) {
		$data = [
            'UF_USER_ID' => $row['OWNER_ID'],
            'UF_EVENT_ID' => $row['ID'],
        ];

        $add_result = $entity_data_class::add($data);

		if (!$add_result->isSuccess()) {
			$errorCount++;
		}else{
			$createCount++;
		}
	}

	$message = "Агент модуля sprint_migration успешно отработал. Добавлено {$createCount} элементов. Неудалось добавить {$errorCount} элементов";
	$arFields = array(
        "TO_USER_ID" => 1,
        "NOTIFY_TYPE" => IM_NOTIFY_SYSTEM,
        "NOTIFY_MODULE" => "sprint_migration",
        "NOTIFY_MESSAGE" => $message,
    );

   	CIMNotify::Add($arFields);
	return "CheckCEventsAndAddToHL();";
}

show_(CheckCEventsAndAddToHL());
