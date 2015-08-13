<?php
/**
 * Компонент для работы с платежным сервисом ROBOKASSA.
 * 
 * Использование:
 * 
 * $pay = new Robokassa([
 *     'login' => 'Ваш логин',		//обязательный
 *     'password1' => 'Пароль 1',	//обязательный
 *     'password2' => 'Пароль 2',	//обязательный
 *	   'test' => true,				//Для тестового сервера
 *     'invoice' => <Номер заказа>,
 *	   'sum' => <Сумма платежа>,
 *	   'shp' => [
 *			'user' => 37,
 *			//...
 *			//Набор пользовательских параметров...
 *	   ]
 * ]);
 * 
 * $pay->formFields(); //Получает набор полей формы <Имя поля> => <Значение>
 * $pay->formAction(); //Получает строку запроса
 * 
 * //ResultURL
 * $post = Yii::$app->request->post();
 * if ($pay->load($post) && $pay->validate())
 * {
 *		//Ответ корректный, обработка
 *		//...
 *		return $pay->getReply();
 * }
 * 
 */

namespace app\components;
 
use Yii;
use yii\base\Component;
use yii\helpers\ArrayHelper;
//use common\models\Log;
use yii\base\InvalidConfigException;
 
class Robokassa extends Component {

	/**
	 * URL использующийся для тестовых операций
	 */
	const ACTION_TEST = 'http://test.robokassa.ru/Index.aspx';
	
	/**
	 * URL живого сервера приема оплаты
	 */
	const ACTION_LIVE = 'https://merchant.roboxchange.com/Index.aspx';
	
	/**
	 * Логин продавца (Задаётся в настройках магазина)
	 * 
	 * @var string 
	 */
	public $login = null;
	
	/**
	 * Пароль для проведения платежных операций (Задаётся в настройках магазина)
	 * 
	 * @var string
	 */
	public $password1 = null;

	/**
	 * Пароль для проверки ответа (Задаётся в настройках магазина)
	 * 
	 * @var string 
	 */
	public $password2 = null;
	
	/**
	 * Уникальный номер транзакции. Если не задан, то робокасса присвоит свой
	 * 
	 * @var integer 
	 */
	public $invoice = 0;
	
	/**
	 * Описание платежа, которое увидит пользователь на сайте агрегатора
	 * 
	 * @var string
	 */
	public $description = '';
	
	/**
	 * Идентификатор валюты в которой будет проведён платеж. Если не задан, то
	 * берется из настроек магазина
	 * 
	 * @var string 
	 */
	public $currency = '';
	
	/**
	 * Язык сообщений для пользователя <ru|en|de>
	 * 
	 * @var string
	 */
	public $culture = 'ru';
	
	/**
	 * Кодировка пользователя (По умолчанию cp-1251)
	 * 
	 * @var string 
	 */
	public $encoding = 'utf-8';
	
	/**
	 * Пользовательские параметры передаваемые агрегатору
	 * 
	 * @var array
	 */
	public $shp = [];
	
	/**
	 * Контрольная сумма ответа от магазина
	 * 
	 * @var string 
	 */
	public $signature = null;
	
	/**
	 * TRUE - использовать тестовый сервер, FALSE - живой сервер
	 * 
	 * @var bool 
	 */
	public $test = false;
	
	/**
	 * Сумма сделки. FALSE - не задана. 
	 * 
	 * @var mixed
	 */
	public $sum = false;
	
	/**
	 * Автоматическая отправка формыю Параметр для формы пополнения.
	 * 
	 * @var integer 
	 */
	public $autoSubmit = 0;

	/**
	 * Карта связей полей формы робокассы и членов класса.
	 * 
	 * @var array
	 */
	private $map = [
			'MrchLogin' => 'login',
			'InvId' => 'invoice',
			'Desc' => 'description',
			'IncCurrLabel' => 'currency',
			'Culture' => 'culture',
			'Encoding' => 'encoding',
			'OutSum' => 'sum',
			'SignatureValue' => 'signature',
			'AutoSubmit' => 'autoSubmit',
		];
	
	/**
	 * Костыль, который какбэ намекает, что обращение происходит на живой сервер
	 * но для тестового платежа.
	 * 
	 * @var bool
	 */
	public $IsTest = false;
	
	/**
	 * Возвращает строку action в зависимости от параметра тестирования или
	 * false в случае формы с произвольной суммой.
	 * 
	 * @return mixed
	 */
	public function formAction()
	{
		if ($this->sum === false) return false;
		return (($this->test) ? self::ACTION_TEST : self::ACTION_LIVE);
	}
	
	/**
	 * Возвращает поля формы для отправки на сервер. Если не указана, какая 
	 * именно сумма оплачивается, то формируется кастомная форма с полем ввода
	 * суммы. После сабмита этой формы, происходит переход в тот же контроллер
	 * только уже с указаной суммой и параметром AutoSubmit. Виджет добавляет
	 * код автоматической отправки вновь сформированой формы.
	 * 
	 * @return array
	 */
	public function formFields()
	{
		//Поля формы произвольной суммы
		if ($this->sum === false) return [
			'OutSum' => [0, true],
			'AutoSubmit' => 1
		];
		
		//Поля готовой формы для отправки
		return [
			'MrchLogin' => $this->login,
			'InvId' => $this->invoice,
			'Desc' => $this->description,
			'IncCurrLabel' => $this->currency,
			'Culture' => $this->culture,
			'Encoding' => $this->encoding,
			'OutSum' => $this->sum,
			'SignatureValue' => $this->getCRC(),
			'AutoSubmit' => $this->autoSubmit,
		] + $this->getSHP(true)
		//Отголоски костыля Робокассы, для тестовых платежей после одобрения
		//магазина.
		+ (($this->IsTest) ? ['IsTest' => 1] : []);
	}
	
	/**
	 * Загрузка членов класса из массива. Используется в ReaultURL для загрузки
	 * ответа сервера.
	 * 
	 * @param array $array
	 * @return boolean
	 */
	public function load($array) 
	{
		//Раскидываются параметры
		foreach ($this->map as $key => $member) 
		{
			$this->$member = ArrayHelper::getValue($array, $key, $this->$member);
		};
		
		//Загружаются пользовательские значения
		foreach ($array as $key => $value) 
		{
			if (substr($key, 0, 3) != 'shp') continue;
			$name = explode('_', $key);
			$this->shp[$name[1]] = $value;
		};
		
		return true;
	}
	
	/**
	 * Валидация ответа сервера робокассы
	 * 
	 * @return boolean
	 */
	public function validate() 
	{
		if (strtoupper($this->signature) != strtoupper($this->getResponseCRC())) 
			return false;
		return true;
	}
	
	/**
	 * Строка ответа для робокассы, подтверждающая проведение платежа
	 * 
	 * @return string
	 */
	public function getReply() 
	{
		return "ОК".$this->invoice;
	}
	
	/**
	 * Подсчет контрольной суммы для проверки ответа робокассы
	 * 
	 * @return string
	 */
	private function getResponseCRC() 
	{
		return md5( implode(':', [
			$this->sum,
			$this->invoice,
			$this->password2,
			implode(':', $this->getSHP())
		]));
	}
	
	/**
	 * Подсчет контролькой суммы для оптравки на сервер
	 * 
	 * @return type
	 */
	private function getCRC()
	{
		return md5( implode(':', [
			$this->login,
			$this->sum,
			$this->invoice,
			$this->password1,
			implode(':', $this->getSHP())
		]));
	}
	
	/**
	 * Возращает набор пользовательских параметров в виде массива. Если 
	 * delimener = false, то к значению параметра прибавляется его имя (для CRC)
	 * Если delimeter = true, то формируется массив для формы. 
	 * 
	 * @param boolean $delimeter
	 * @return array
	 */
	private function getSHP($delimeter = false)
	{
		if (count($this->shp) == 0) return [];
		ksort($this->shp);
		$result = [];
		foreach ($this->shp as $name => $value) 
		{
			$result['shp_'.$name] = ($delimeter) ? $value : 'shp_'.$name.'='.$value;
		}
		return $result;
	}
}

