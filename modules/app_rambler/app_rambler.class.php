<?php
#[AllowDynamicProperties]
class app_rambler extends module {
	function __construct() {
		$this->name="app_rambler";
		$this->title="Модуль Рамблер";
		$this->module_category="<#LANG_SECTION_APPLICATIONS#>";
		$this->version="1.0 beta";
		$this->id = isset($this->id) ? $this->id : '';
		$this->view_mode = isset($this->view_mode) ? $this->view_mode : '';
		$this->edit_mode = isset($this->edit_mode) ? $this->edit_mode : '';
		$this->mode = isset($this->mode) ? $this->mode : '';
		$this->tab = isset($this->tab) ? $this->tab : '';
		$this->ajax = isset($this->ajax) ? $this->ajax : 0;
		$this->checkInstalled();
	}

	function saveParams($data=1) {
		$p=array();
		if (IsSet($this->id)) {
			$p["id"]=$this->id;
		}
		if (IsSet($this->view_mode)) {
			$p["view_mode"]=$this->view_mode;
		}
		if (IsSet($this->edit_mode)) {
			$p["edit_mode"]=$this->edit_mode;
		}
		if (IsSet($this->tab)) {
			$p["tab"]=$this->tab;
		}
		
		return parent::saveParams($p);
	}

	function getParams() {
		global $id;
		global $mode;
		global $view_mode;
		global $edit_mode;
		global $tab;
		global $ajax;
		
		if (isset($id)) {
			$this->id=$id;
		}
		if (isset($mode)) {
			$this->mode=$mode;
		}
		if (isset($view_mode)) {
			$this->view_mode=$view_mode;
		}
		if (isset($edit_mode)) {
			$this->edit_mode=$edit_mode;
		}
		if (isset($tab)) {
			$this->tab=$tab;
		}
		if (isset($ajax)) {
			$this->ajax=$ajax;
		}
	}

	function run() {
		global $session;
		$out=array();
		
		if (isset($this->action) && $this->action=='admin') {
			$this->admin($out);
		} else {
			$this->usual($out);
		}
		if (IsSet($this->owner->action)) {
			$out['PARENT_ACTION']=$this->owner->action;
		}
		if (IsSet($this->owner->name)) {
			$out['PARENT_NAME']=$this->owner->name;
		}
		$out['VIEW_MODE']=$this->view_mode;
		$out['EDIT_MODE']=$this->edit_mode;
		$out['ID']=$this->id;
		$out['MODE']=$this->mode;
		$out['ACTION']=isset($this->action) ? $this->action : '';
		$this->data=$out;
		$p=new parser(DIR_TEMPLATES.$this->name."/".$this->name.".html", $this->data, $this);
		$this->result=$p->result;
	}
	
	
   

    function admin(&$out) {
		if(empty($this->view_mode)) {
			//Выгружаем список добавленых городов и отдаем в шаблон
			$out['CITY_ALL'] = SQLSelect("
				SELECT c.*,
					MAX(CASE WHEN v.TITLE = 'current_weather.icon' THEN v.VALUE ELSE '' END) AS CURRENT_WEATHER_ICON,
					MAX(CASE WHEN v.TITLE = 'current_weather.temperature' THEN v.VALUE ELSE '' END) AS CURRENT_WEATHER_TEMPERATURE,
					MAX(CASE WHEN v.TITLE = 'current_weather.temperature' THEN v.UPDATE ELSE '' END) AS CURRENT_WEATHER_UPDATE
				FROM rambler_weather_city c
				LEFT JOIN rambler_weather_value v
					ON v.CITY_ID = c.ID
					AND v.TITLE IN ('current_weather.icon', 'current_weather.temperature')
				GROUP BY c.ID
				ORDER BY c.ID
			");
			foreach($out['CITY_ALL'] as $key => $value) {
				if(!empty($value['CURRENT_WEATHER_UPDATE'])) {
					$out['CITY_ALL'][$key]['UPDATE'] = date('d.m.Y H:i', $value['CURRENT_WEATHER_UPDATE']);
				}
			}
		}

	    if($this->view_mode == 'citysearch' && !empty($this->id)) {
			//Действия после поиска и передачи города
			$data = $this->callAPI('https://weather.rambler.ru/api/v3/map_towns/?url_path='.$this->id);
			$data = json_decode($data, TRUE);
			if (empty($data['current_town']['url_path']) || empty($data['current_town']['name'])) {
				$this->redirect('?');
			}
			
			$ifExist = SQLSelectOne("SELECT ID FROM rambler_weather_city WHERE URL_PATH = '".DBSafe($data['current_town']["url_path"])."'");
			
			if(!$ifExist) {
				$rec['TITLE'] = $data['current_town']["name"];
				$rec['URL_PATH'] = $data['current_town']["url_path"];
				//$rec['GEO_CODE'] = $data['current_town']["geo_location"]["lat"].', '.$data['current_town']["geo_location"]["lng"];
				$rec['ADD'] = time();
				
				SQLInsert('rambler_weather_city', $rec);
				
				$this->loadWeatherNow($rec['URL_PATH']);
			}
			$this->redirect('?');
	    }
	
		if($this->view_mode == 'loaddata' && !empty($this->id)) {
			if(empty($this->mode)) $this->mode = 'current_weather';
			//Действия при входе в город, тут выгружаем все значения
			$city_info = SQLSelectOne("SELECT * FROM rambler_weather_city WHERE ID = '".DBSafe($this->id)."'");
			if (!$city_info || !is_array($city_info) || empty($city_info['ID'])) {
				$this->redirect('?');
			}
			$out['CITY_TITLE'] = $city_info['TITLE'];
			$out['CITY_URL_PATH'] = $city_info['URL_PATH'];
			$arrayInDB = SQLSelect("SELECT * FROM rambler_weather_value WHERE CITY_ID = '".DBSafe($this->id)."' AND TITLE LIKE '".DBSafe($this->mode).".%' ORDER BY ID");
			$arrayReady = [];
			$arrayOut = [];
			
			foreach($arrayInDB as $key => $value) {
				$searchType = explode('.', $value["TITLE"]);
				
				if(mb_strtoupper($this->mode) != mb_strtoupper($searchType[0])) {
					unset($arrayInDB[$key]);
					continue;
				}
				
				$arrayInDB[$key]["TITLE"] = $searchType[1];
				$arrayInDB[$key]["CONTENT_TYPE"] = mb_strtoupper($searchType[0]);
				$arrayInDB[$key]["UPDATE_HUMAN"] = date('d.m.Y H:i', $value["UPDATE"]);
				
				$arrayReady[] = mb_strtoupper($searchType[0]);
			}
			
			$arrayReady = array_unique($arrayReady);
			$arrayReady = array_values($arrayReady);
			
			foreach($arrayInDB as $key => $value) {
				$arrayOut['DATA'][] = $arrayInDB[$key];
			}
			
			$out['CITY_DATA'] = $arrayOut;
	    }
		
		if($this->view_mode == 'savelink') {
			//Действия при связке со свойствами, меняем в БД валуе и привязываем, далее редирект обратно
			global $id;
			global $mode;
			
			if(!$id || !$mode) $this->redirect('?');
			
			$skills = SQLSelect("SELECT * FROM `rambler_weather_value` WHERE CITY_ID = '" . DBSafe($id) . "' AND TITLE LIKE '".DBSafe($mode).".%' ORDER BY ID");
			
			$total = count($skills);

			for ($i = 0; $i < $total; $i++) {
				$old_linked_object = $skills[$i]['LINKED_OBJECT'];
                $old_linked_property = $skills[$i]['LINKED_PROPERTY'];
                $old_linked_method = $skills[$i]['LINKED_METHOD'];
				
				global ${'linked_object' . $skills[$i]['ID']};
                $skills[$i]['LINKED_OBJECT'] = trim(${'linked_object' . $skills[$i]['ID']});
                global ${'linked_property' . $skills[$i]['ID']};
                $skills[$i]['LINKED_PROPERTY'] = trim(${'linked_property' . $skills[$i]['ID']});
                global ${'linked_method' . $skills[$i]['ID']};
                $skills[$i]['LINKED_METHOD'] = trim(${'linked_method' . $skills[$i]['ID']});

				SQLUpdate('rambler_weather_value', $skills[$i]);
				
				if ($old_linked_object != $skills[$i]['LINKED_OBJECT'] || $old_linked_property != $skills[$i]['LINKED_PROPERTY']) {
                    removeLinkedProperty($old_linked_object, $old_linked_property, $this->name);
                }
                if ($skills[$i]['LINKED_OBJECT'] && $skills[$i]['LINKED_PROPERTY']) {
                    addLinkedProperty($skills[$i]['LINKED_OBJECT'], $skills[$i]['LINKED_PROPERTY'], $this->name);
					//Запишем сразу значение
					if($skills[$i]['VALUE'] != gg($skills[$i]['LINKED_OBJECT'].'.'.$skills[$i]['LINKED_PROPERTY'])) {
						sg($skills[$i]['LINKED_OBJECT'].'.'.$skills[$i]['LINKED_PROPERTY'], $skills[$i]['VALUE']);
					}
                }
			}
			
			$this->redirect('?view_mode=loaddata&id='.$id.'&mode='.$mode);
	    }
		
		if($this->view_mode == 'loadweather' && !empty($this->id)) {
			//Действия при ручном обновлении
			
			$this->loadWeatherNow($this->id);
			//echo '<pre>';
			//var_dump($this->loadWeatherNow($this->id));
			//die();
			$this->redirect('?');
	    }
		
		if($this->view_mode == 'deletecity' && !empty($this->id)) {
			//Действия при ручном обновлении
			$this->DeleteLinkedProperties($this->id);
			
			SQLExec("DELETE FROM rambler_weather_value WHERE CITY_ID = '".DBSafe($this->id)."'");
			SQLExec("DELETE FROM rambler_weather_city WHERE ID = '".DBSafe($this->id)."'");
			
			$this->redirect('?');
	    }
		
		if($this->view_mode == 'autolink' && !empty($this->id) && !empty($this->mode) && !empty($this->tab)) {
			$this->autoLinkProp($this->mode, $this->id, $this->tab);
			$this->redirect('?view_mode=loaddata&id='.$this->id);
	    }

		$out['VERSION_MODULE'] = $this->version;
	}
	
	function moonPhaseText($deg) {
		$moon=array(
			"1" => "Новолуние",
			"2" => "Молодая луна",
			"3" => "Правая четверть",
			"4" => "Прибывающая луна",
			"5" => "Полнолуние",
			"6" => "Убывающая луна",
			"7" => "Последняя четверть",
			"8" => "Старая луна",
		);
		$i = ceil($deg/45);
		if($i == 0) $i=1;
		return isset($moon[$i]) ? $moon[$i] : $moon[8];
	}
	
	function getWindDirectionText($text){
		$wind=array(
		  "N" => "Северный",
		  "NE" => "Северо-восточный",
		  "E" => "Восточный",
		  "SE" => "Юго-восточный",
		  "S" => "Южный",
		  "SW" => "Юго-западный",
		  "W" => "Западный",
		  "NW" => "Северо-западный",
		  "C" => "Штиль",
		);
	
		return isset($wind[$text]) ? $wind[$text] : $text;
	  }
		
	function magneticText($num) {
		if($num == "") $num = 10;
		$magnetic = array(
			"0" => "Спокойное магнитное поле",
			"1" => "Неустойчивое магнитное поле",
			"2" => "Слабо возмущённое магнитное поле",
			"3" => "Возмущённое магнитное поле",
			"4" => "Магнитная буря",
			"5" => "Большая магнитная буря",
			"10" => "Информация отсутствует",
		);

		return isset($magnetic[$num]) ? $magnetic[$num] : $magnetic[10];
	}
	
	function uvText($num){
		$uv=array(
			"0" => "Низкий",
			"1" => "Низкий",
			"2" => "Низкий",
			"3" => "Средний",
			"4" => "Средний",
			"5" => "Средний",
			"6" => "Высокий",
			"7" => "Высокий",
			"8" => "Очень высокий",
			"9" => "Очень высокий",
			"10" => "Экстремальный",
			"11" => "Экстремальный",
		);
		
		if(empty($uv[$num])) $num = 11;

		return $uv[$num];
	}
	
	function iconText($text){
		$icon=array(
			"clear" => "Ясно",
			"clear-night" => "Ясно",
			"cloudy" => "Облачно",
			"partly-cloudy" => "Переменная облачность",
			"partly-cloudy-night" => "Переменная облачность",
			"fog" => "Туман",
			"light-rain" => "Слабый дождь",
			"occ-rain" => "Временами дождь",
			"light-rain-night" => "Временами дождь",
			"occ-snow" => "Временами снег",
			"light-snow-night" => "Временами снег",
			"rain" => "Дождь",
			"rain-night" => "Дождь",
			"snow" => "Снег",
			"snow-night" => "Снег",
			"sleet" => "Снег с дождем",
			"thunder" => "Гроза",
		);

		return isset($icon[$text]) ? $icon[$text] : $text;
	}

	function saveWeatherValue($rec, $cycleupdate = 0, $city_id = '', $city_title = '') {
		$this->saveWeatherValues(array($rec), $cycleupdate, $city_id, $city_title);
	}

	function saveWeatherValues($records, $cycleupdate = 0, $city_id = '', $city_title = '') {
		if (empty($records) || !is_array($records)) {
			return;
		}

		$prepared = array();
		$preparedMap = array();
		$titlesByCity = array();

		foreach ($records as $rec) {
			if (empty($rec['TITLE']) || !isset($rec['CITY_ID'])) {
				continue;
			}
			unset($rec['ID']);
			$cityKey = (string)$rec['CITY_ID'];
			$titleKey = (string)$rec['TITLE'];
			$mapKey = $cityKey.'|'.$titleKey;
			$preparedMap[$mapKey] = $rec;
			$titlesByCity[$cityKey][$titleKey] = $titleKey;
		}

		if (empty($preparedMap)) {
			return;
		}
		$prepared = array_values($preparedMap);

		$existing = array();
		foreach ($titlesByCity as $cityKey => $titles) {
			$titleSql = array();
			foreach ($titles as $title) {
				$titleSql[] = "'".DBSafe($title)."'";
			}
			$rows = SQLSelect("SELECT * FROM rambler_weather_value WHERE CITY_ID = '".DBSafe($cityKey)."' AND TITLE IN (".implode(',', $titleSql).") ORDER BY ID");
			foreach ($rows as $row) {
				if (empty($row['TITLE'])) {
					continue;
				}
				$mapKey = (string)$row['CITY_ID'].'|'.(string)$row['TITLE'];
				if (!isset($existing[$mapKey])) {
					$existing[$mapKey] = $row;
				}
			}
		}

		foreach ($prepared as $rec) {
			$mapKey = (string)$rec['CITY_ID'].'|'.(string)$rec['TITLE'];
			$ifExist = isset($existing[$mapKey]) ? $existing[$mapKey] : array();

			if (empty($ifExist['ID'])) {
				SQLInsert('rambler_weather_value', $rec);
				continue;
			}

			$this->setPropByNewValue(
				isset($ifExist['LINKED_OBJECT']) ? $ifExist['LINKED_OBJECT'] : '',
				isset($ifExist['LINKED_PROPERTY']) ? $ifExist['LINKED_PROPERTY'] : '',
				isset($ifExist['LINKED_METHOD']) ? $ifExist['LINKED_METHOD'] : '',
				isset($rec['VALUE']) ? $rec['VALUE'] : '',
				isset($ifExist['VALUE']) ? $ifExist['VALUE'] : '',
				$city_id,
				$city_title
			);

			if ($cycleupdate != 0
				&& empty($ifExist['LINKED_OBJECT'])
				&& empty($ifExist['LINKED_PROPERTY'])
				&& empty($ifExist['LINKED_METHOD'])) {
				continue;
			}

			$rec['ID'] = $ifExist['ID'];
			SQLUpdate('rambler_weather_value', $rec);
		}
	}
	
	function autoLinkProp($url_path, $city_id, $type) {
		//$url_path_obj = substr($url_path, 2);
		$url_path_obj = str_replace('-','_',$url_path);
		addClass('app_rambler');
		addClassObject('app_rambler', $url_path_obj);
		
		$loadAllProp = SQLSelect("SELECT * FROM rambler_weather_value WHERE CITY_ID = '".DBSafe($city_id)."' AND TITLE LIKE '".DBSafe($type).".%' AND LINKED_OBJECT = '' AND LINKED_PROPERTY = ''");
		
		$obj = getObject($url_path_obj);

		foreach($loadAllProp as $key => $value) {
			if(empty($value['TITLE'])) continue;
			
			$exValue = explode('.', $value['TITLE']);
			$obj->setProperty($exValue[1], $value['VALUE']);
			
			addLinkedProperty($url_path_obj, $exValue[1], $this->name);
			
			SQLExec("UPDATE rambler_weather_value SET LINKED_OBJECT = '".DBSafe($url_path_obj)."', LINKED_PROPERTY = '".DBSafe($exValue[1])."' WHERE CITY_ID = '".DBSafe($city_id)."' AND TITLE = '".DBSafe($value['TITLE'])."'");
		
		}
	}
	
	function serverIP($id, $cycleupdate = 0) {
		$data = $this->callAPI('https://kraken.rambler.ru/userip');
		$data = trim($data);
		if ($data === '') {
			return;
		}
		
		$rec['TITLE'] = 'userip.userip';
		$rec['VALUE'] = $data;
		$rec['CITY_ID'] = $id;
		$rec['UPDATE'] = time();
		$this->saveWeatherValue($rec, 0, $id);
	}
	
	function loadCurrenciesNow($url_path = '', $cycleupdate = 0) {
		if($url_path != '') {
			$getAllCity = SQLSelect("SELECT * FROM rambler_weather_city WHERE URL_PATH = '".DBSafe($url_path)."'");
		} else {
			$getAllCity = SQLSelect("SELECT * FROM rambler_weather_city");
		}
		
		foreach($getAllCity as $key => $value) {
			$data = $this->callAPI('https://www.rambler.ru/api/v4/header', $value['GEO_CODE']);
			$data = json_decode($data, TRUE);
			$records = array();
			
			//Отправим в функцию для получения пробок
			//$this->loadTraffic($data["traffic"], $value['ID']);
			
			if(isset($data["currencies"]) and is_array($data["currencies"])){
				foreach($data["currencies"] as $keycurrencies => $valuecurrencies) {
					if (empty($valuecurrencies['code']) || !isset($valuecurrencies['value'])) continue;
					$records[] = array(
						'TITLE' => 'currencies.'.$valuecurrencies['code'],
						'VALUE' => $valuecurrencies['value'],
						'CITY_ID' => $value['ID'],
						'UPDATE' => time()
					);
					if (isset($valuecurrencies['delta'])) {
						$records[] = array(
							'TITLE' => 'currencies.'.$valuecurrencies['code'].'_delta',
							'VALUE' => $valuecurrencies['delta'],
							'CITY_ID' => $value['ID'],
							'UPDATE' => time()
						);
					}
					if (!empty($valuecurrencies['url'])) {
						$records[] = array(
							'TITLE' => 'currencies.'.$valuecurrencies['code'].'_url',
							'VALUE' => $valuecurrencies['url'],
							'CITY_ID' => $value['ID'],
							'UPDATE' => time()
						);
					}
				}
			}
			$this->saveWeatherValues($records, $cycleupdate, $value['ID'], $value['TITLE']);
		}
		
	}
	
	//Загрузка раз в час
	function loadDataCycle() {
		$getUniqCityID = SQLSelect("
			SELECT DISTINCT c.ID, c.URL_PATH
			FROM rambler_weather_city c
			INNER JOIN rambler_weather_value v ON v.CITY_ID = c.ID
			WHERE v.LINKED_OBJECT != '' OR v.LINKED_PROPERTY != '' OR v.LINKED_METHOD != ''
		");
		
		foreach($getUniqCityID as $value) {
			if (empty($value['URL_PATH'])) continue;
			$this->loadWeatherNow($value['URL_PATH'], 1);
		}
	}
	
	function loadTraffic($data, $id, $cycleupdate = 0) {
		if(empty($data)) return;
		
		$records = array();
		foreach($data as $key => $value) {
			$records[] = array(
				'TITLE' => 'traffic.'.$key,
				'VALUE' => $value,
				'CITY_ID' => $id,
				'UPDATE' => time()
			);
		}
		$this->saveWeatherValues($records, $cycleupdate, $id);
	}
	
	function inday_weather($data, $id, $cycleupdate = 0) {
		if(empty($data['table_data']) || !is_array($data['table_data'])) return;

		$arrayKeyInDay = ['00_00', '03_00', '06_00', '09_00', '12_00', '15_00', '18_00', '21_00', '24_00'];
		$records = array();
		
		foreach($data['table_data'] as $key => $value) {
			unset($value['date']);
			foreach($value as $key2 => $value2) {
				if (!is_array($value2)) continue;
				foreach($value2 as $key3 => $value3) {
					if (!isset($arrayKeyInDay[$key3])) continue;
					$rec = array(
						'TITLE' => 'inday_weather.'.$key2.'_'.$arrayKeyInDay[$key3],
						'VALUE' => $value3,
						'CITY_ID' => $id,
						'UPDATE' => time()
					);
					
					if(($key2 == 'temperature' || $key2 == 'temp_feels' || $key2 == 'temp_water' ) && $rec['VALUE'] > 0) {
						$rec['VALUE'] = '+'.$rec['VALUE'];
					}
					$records[] = $rec;
					
				}
			}
		}
		$this->saveWeatherValues($records, $cycleupdate, $id);
	}
	
	
	function loadWeatherNow($url_path = '', $cycleupdate = 0) {
		if($url_path != '') {
			$getAllCity = SQLSelect("SELECT * FROM rambler_weather_city WHERE URL_PATH = '".DBSafe($url_path)."'");
		} else {
			$getAllCity = SQLSelect("SELECT * FROM rambler_weather_city");
		}
		
		foreach($getAllCity as $key => $value) {
			$data = $this->callAPI('https://weather.rambler.ru/api/v3/today/?all_data=0&url_path='.$value['URL_PATH']);
			$data = json_decode($data, TRUE);
			if (empty($data['date_weather']) || !is_array($data['date_weather'])) {
				continue;
			}
			
			//Добавим расчет фазы луны
			$data["date_weather"]["moon_phase_text"] = $this->moonPhaseText(isset($data["date_weather"]["moon_phase"]) ? $data["date_weather"]["moon_phase"] : 0);
			$data["date_weather"]["wind_direction_text"] = $this->getWindDirectionText(isset($data["date_weather"]["wind_direction"]) ? $data["date_weather"]["wind_direction"] : '');
			$data["date_weather"]["geomagnetic_text"] = $this->magneticText(isset($data["date_weather"]["geomagnetic"]) ? $data["date_weather"]["geomagnetic"] : '');
			$data["date_weather"]["uv_text"] = $this->uvText(isset($data["date_weather"]["uv"]) ? $data["date_weather"]["uv"] : 11);
			$data["date_weather"]["icon_text"] = $this->iconText(isset($data["date_weather"]["icon"]) ? $data["date_weather"]["icon"] : '');
			$data["date_weather"]["roadway_visibility_points"] = isset($data["date_weather"]["roadway_visibility"]["points"]) ? $data["date_weather"]["roadway_visibility"]["points"] : '';
			$data["date_weather"]["roadway_visibility_description"] = isset($data["date_weather"]["roadway_visibility"]["description"]) ? $data["date_weather"]["roadway_visibility"]["description"] : '';
			$data["date_weather"]["sunset"] = !empty($data["date_weather"]["sunset"]) ? date('H:i:s', strtotime($data["date_weather"]["sunset"])) : '';
			$data["date_weather"]["sunrise"] = !empty($data["date_weather"]["sunrise"]) ? date('H:i:s', strtotime($data["date_weather"]["sunrise"])) : '';
			$daylightsec = isset($data['date_weather']['daylight']) ? (int)$data['date_weather']['daylight'] : 0;
			$data["date_weather"]["daylight_H_i"] = date_format(new DateTime("@$daylightsec"),'H:i');
			//добавим название города
			$data["date_weather"]["name_town"] = isset($data["town"]["name"]) ? $data["town"]["name"] : '';
			
			
			
			unset($data["date_weather"]["alert_text_short"]);
			unset($data["date_weather"]["date"]);
			$records = array();
			
			foreach($data["date_weather"] as $weatherNowKey => $weatherNowValue) {
				if(!is_array($weatherNowValue)) {
					$rec = array(
						'TITLE' => 'current_weather.'.$weatherNowKey,
						'VALUE' => $weatherNowValue,
						'CITY_ID' => $value['ID'],
						'UPDATE' => time()
					);
					
					if(($weatherNowKey == 'temperature' || $weatherNowKey == 'temp_feels' || $weatherNowKey == 'temp_water' ) && $weatherNowValue > 0) {
						$rec['VALUE'] = '+'.$weatherNowValue;
					}
					$records[] = $rec;
					
					if(isset($data["town"]["geo_id"]) && (!isset($value['GEO_CODE']) || $value['GEO_CODE'] != $data["town"]["geo_id"])) {
						//Обновим GEO ID он нам понадобится дальше
						SQLExec("UPDATE rambler_weather_city SET GEO_CODE = '".DBSafe($data["town"]["geo_id"])."' WHERE ID = '".DBSafe($value['ID'])."'");
					}
				}
			} 
			$this->saveWeatherValues($records, $cycleupdate, $value['ID'], $value['TITLE']);
			
			//Валюту выгрузим
			$this->loadCurrenciesNow($value['URL_PATH'], $cycleupdate);
			//IP получим
			$this->serverIP($value['ID'], $cycleupdate);
			//Получим прогноз на день
			$this->inday_weather($data, $value['ID'], $cycleupdate);
			//Получим прогноз на 10 дней
			$this->loadWeatherforecast10($value['URL_PATH'], $cycleupdate);
			//Получим гороскоп
			$this->goroskop($value['ID'], $cycleupdate);
		}
	}
	
	function loadWeatherforecast10($url_path = '', $cycleupdate = 0) {
		if($url_path != '') {
			$getAllCity = SQLSelect("SELECT * FROM rambler_weather_city WHERE URL_PATH = '".DBSafe($url_path)."'");
		} else {
			$getAllCity = SQLSelect("SELECT * FROM rambler_weather_city");
		}
		
		foreach($getAllCity as $keyy => $valuee) {
			$data = $this->callAPI('https://weather.rambler.ru/api/v3/ndays/?n=10&url_path='.$valuee['URL_PATH']);
			$data = json_decode($data, TRUE);
			if (empty($data['range_weather']) || !is_array($data['range_weather'])) {
				continue;
			}
			$id=$valuee['ID'];
			
			$arr=array();
		
			foreach ($data['range_weather'] as $key => $value) {
				foreach($value as $name => $val) {
					if (!is_array($val)) {
						
						switch ($name) { 
						case 'date': 
								// Команды
								$val = date("d.m.Y", strtotime($val));
								break;     //выход чтобы дальше не искал, если нашел и выполнил 
						case 'sunset': 
								$val = date('d.m.Y H:i:s', strtotime($val));
								break; 
						case 'sunrise':
								$val = date('d.m.Y H:i:s', strtotime($val));
								break;
						case 'uv':
								$val = $this->uvText($val);
								break;
						case 'daylight':
								$val = date_format(new DateTime("@$val"),'H:i');
								break;
						case 'moon_phase':
								$val = $this->moonPhaseText($val);
								break;			
						case 'geomagnetic':
								$val = $this->magneticText($val);
								break;		
															
						}
						
					$arr[] = array('TITLE' => 'forecast.'.$key.'_'.$name, 'VALUE' => $val, 'CITY_ID' => $id, 'UPDATE' => time());
					}
					else if ($name == 'forecast') {
						foreach ($val as $partOfDay => $value2) {
							if (!is_array($value2)) continue;
							foreach ($value2 as $valuename => $itogValue) {
							
								switch ($valuename) { 
									case 'temperature': 
											// Команды
											if($itogValue > 0) {
											$itogValue = '+'.$itogValue;
											}				
											break;     //выход чтобы дальше не искал, если нашел и выполнил 
									case 'wind_direction': 
											$itogValue = $this->getWindDirectionText($itogValue);
											break; 
									case 'icon':
											$arr[] = array('TITLE' => 'forecast.'.$key.'_'.$partOfDay.'_'.$valuename.'_text', 'VALUE' => $this->iconText($itogValue), 'CITY_ID' => $id, 'UPDATE' => time());
											break;
											
								}
								
								$arr[] = array('TITLE' => 'forecast.'.$key.'_'.$partOfDay.'_'.$valuename, 'VALUE' => $itogValue, 'CITY_ID' => $id, 'UPDATE' => time());
							}
						}
					}
				}
				
			}
					
			$this->saveWeatherValues($arr, $cycleupdate, $id);
		}
	}


	function goroskop($id, $cycleupdate = 0) {
			
		$horo=array(
		  "aries" => "Овен",
		  "taurus" => "Телец",
		  "gemini" => "Близнецы",
		  "cancer" => "Рак",
		  "leo" => "Лев",
		  "virgo" => "Дева",
		  "libra" => "Весы",
		  "scorpio" => "Скорпион",
		  "sagittarius" => "Стрелец",
		  "capricorn" => "Козерог",
		  "aquarius" => "Водолей",
		  "pisces" => "Рыбы",);
	
	
		$arr=array();
		foreach($horo as $znak => $znak_rus) {
			$data = $this->callAPI('https://horoscopes.rambler.ru/api/front/v3/horoscope/general/'.$znak.'/tomorrow/');
			$data = json_decode($data, TRUE);
			if (empty($data['content']['text']) || !is_array($data['content']['text'])) {
				continue;
			}
			
			foreach ($data['content']['text'] as $key => $value) {
				$arr[] = array('TITLE' => 'goroskop.'.$znak.'_tomorrow', 'VALUE' => $value["content"], 'CITY_ID' => $id, 'UPDATE' => time());
			}
		}
		foreach($horo as $znak => $znak_rus) {
			$data = $this->callAPI('https://horoscopes.rambler.ru/api/front/v3/horoscope/general/'.$znak.'/today/');
			$data = json_decode($data, TRUE);
			if (empty($data['content']['text']) || !is_array($data['content']['text'])) {
				continue;
			}
			
			foreach ($data['content']['text'] as $key => $value) {
				$arr[] = array('TITLE' => 'goroskop.'.$znak.'_today', 'VALUE' => $value["content"], 'CITY_ID' => $id, 'UPDATE' => time());
			}
		}
		$this->saveWeatherValues($arr, $cycleupdate, $id);
				
			
	}
	

	function setPropByNewValue($object = '', $property = '', $method = '', $newvalue = '', $oldvalue = '', $city_id = '', $city_title = '') {
		if(!empty($object) && !empty($property) && ($newvalue != $oldvalue || $newvalue != gg($object.'.'.$property))) {
			sg($object.'.'.$property, $newvalue);
		}
		if(!empty($object) && !empty($method) && $newvalue != $oldvalue) {
			cm($object.'.'.$method, array(
				'NEW_VALUE' => $newvalue,
				'OLD_VALUE' => $oldvalue,
				'CITY_ID' => $city_id,
				'CITY_NAME' => $city_title,
			));
		}
	}
	
	function usual(&$out) {
		//Обработка AJAX 
		global $request;
		global $q;
		
		if($this->ajax == 1 && $request == 'whereiam') {
			$data = $this->whereiam();
			$data = json_decode($data, TRUE);
			if (empty($data['name'])) {
				echo json_encode(array('items' => array()));
				die();
			}
			
			$data = $this->callAPI('https://weather.rambler.ru/api/v3/suggest/?query='.urlencode($data['name']).'&count=1');
			
			echo $data;
			die();
    	}
		
		if($this->ajax == 1 && $request == 'findcity' && !empty($q)) {
			$data = $this->callAPI('https://weather.rambler.ru/api/v3/suggest/?query='.urlencode($q).'&count=10');
	
			echo $data;
			die();
    	}
		
		global $url_path;

		if(isset($url_path)) {
			$city = SQLSelectOne('SELECT * FROM rambler_weather_city WHERE URL_PATH = "'.DBSafe($url_path).'"');
			if (!$city || !is_array($city) || empty($city['ID'])) {
				return;
			}
			$data = SQLSelectOne('SELECT * FROM rambler_weather_value WHERE CITY_ID = "'.DBSafe($city['ID']).'" AND LINKED_OBJECT != "" AND LINKED_PROPERTY != ""');
			if (!$data || !is_array($data)) {
				$data = array();
			}
			
			foreach($city as $key => $value) {
				$city['CITY_'.$key] = $value;
				unset($city[$key]);
			}
			
			$count = 0;
			
			foreach($data as $key => $value) {
				if($key == 'TITLE' && $value == 'current_weather.temperature') {
					$city['DATA_TEMP'] = $data['VALUE'];
					$count++;
				} else if($key == 'TITLE' && $value == 'current_weather.roadway_visibility_description') {
					$city['DATA_READDESC'] = $data['VALUE'];
					$count++;
				} else if($key == 'TITLE' && $value == 'current_weather.icon') {
					$city['DATA_ICON'] = $data['VALUE'];
					$count++;
				} else if($key == 'TITLE' && $value == 'current_weather.icon_text') {
					$city['DATA_ICON_TEXT'] = $data['VALUE'];
					$count++;
				} else if($key == 'TITLE' && $value == 'current_weather.temp_feels') {
					$city['DATA_TEMP_FEELS'] = $data['VALUE'];
					$count++;
				} else if($key == 'TITLE' && $value == 'current_weather.wind_direction_text') {
					$city['DATA_WIND_DER'] = $data['VALUE'];
					$count++;
				} else if($key == 'TITLE' && $value == 'current_weather.wind_speed') {
					$city['DATA_WIND'] = $data['VALUE'];
					$count++;
				} else if($key == 'TITLE' && $value == 'current_weather.uv') {
					$city['DATA_UV'] = $data['VALUE'];
					$count++;
				} else if($key == 'TITLE' && $value == 'current_weather.pressure_mm') {
					$city['DATA_PRESS'] = $data['VALUE'];
					$count++;
				} else if($key == 'TITLE' && $value == 'current_weather.moon_phase_text') {
					$city['DATA_MOON_PH'] = $data['VALUE'];
					$count++;
				} else if($key == 'TITLE' && $value == 'current_weather.geomagnetic_text') {
					$city['DATA_GEOMAG'] = $data['VALUE'];
					$count++;
				}
			}
			
			echo '<pre>';
			var_dump($city);
		}
	}
	
	function callAPI($url, $geocode = 0) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		
		if($geocode != 0) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Cookie: geoid='.$geocode,
				'Accept: application/json'
			));
			//curl_setopt($ch, CURLOPT_COOKIEFILE, '/var/www/html/cms/cached/rambler.txt');
		} else {
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Accept: application/json'
			));
		}
		
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		$html = curl_exec($ch);
		curl_close($ch);
		 
		return $html;
	}


	function whereiam() {
		$data = $this->callAPI('https://weather.rambler.ru/location/autodetect');
		//$data = json_decode($data, TRUE);
		return $data;
	}

	function DeleteLinkedProperties($city_id = 0) {
		if($city_id != 0) {
			$properties = SQLSelect("SELECT * FROM rambler_weather_value WHERE CITY_ID = '".DBSafe($city_id)."' AND LINKED_OBJECT != '' AND LINKED_PROPERTY != ''");
		} else {
			$properties = SQLSelect("SELECT * FROM rambler_weather_value WHERE LINKED_OBJECT != '' AND LINKED_PROPERTY != ''");
		}
		
		if (!empty($properties)) {
			foreach ($properties as $prop) {
				removeLinkedProperty($prop['LINKED_OBJECT'], $prop['LINKED_PROPERTY'], $this->name);
			}
		}
	}

	function createIndexIfMissing($table, $indexName, $indexSql) {
		$exists = SQLSelectOne("SHOW INDEX FROM `".$table."` WHERE Key_name = '".DBSafe($indexName)."'");
		if (!$exists || !is_array($exists) || empty($exists['Key_name'])) {
			SQLExec("ALTER TABLE `".$table."` ADD ".$indexSql);
		}
	}

	function tableExists($table) {
		$exists = SQLSelectOne("SHOW TABLES LIKE '".DBSafe($table)."'");
		return ($exists && is_array($exists));
	}

	function upgradeSchema() {
		if ($this->tableExists('rambler_weather_city')) {
			$this->createIndexIfMissing('rambler_weather_city', 'IDX_URL_PATH', "INDEX `IDX_URL_PATH` (`URL_PATH`(191))");
		}
		if ($this->tableExists('rambler_weather_value')) {
			SQLExec("ALTER TABLE `rambler_weather_value` MODIFY `VALUE` TEXT");
			$this->createIndexIfMissing('rambler_weather_value', 'IDX_CITY_TITLE', "INDEX `IDX_CITY_TITLE` (`CITY_ID`, `TITLE`(191))");
			$this->createIndexIfMissing('rambler_weather_value', 'IDX_TITLE_CITY', "INDEX `IDX_TITLE_CITY` (`TITLE`(191), `CITY_ID`)");
			$this->createIndexIfMissing('rambler_weather_value', 'IDX_LINKED', "INDEX `IDX_LINKED` (`LINKED_OBJECT`(80), `LINKED_PROPERTY`(80), `LINKED_METHOD`(80))");
		}
	}
	
	function processSubscription($event, $details='') {
		if($event=='MINUTELY' && date('i', time()) % 20 == 0) {
			$this->loadDataCycle();
		}
	}
	
	function install($data='') {
		subscribeToEvent($this->name, 'MINUTELY');
		
		parent::install();
		$this->upgradeSchema();
	}
	
	function uninstall() {
		unsubscribeFromEvent($this->name, 'MINUTELY');
		
		$this->DeleteLinkedProperties();

		// Удаляем таблицы модуля из БД.
		echo date('H:i:s') . ' Delete DB tables.<br>';
		SQLExec('DROP TABLE IF EXISTS rambler_weather_city');
		SQLExec('DROP TABLE IF EXISTS rambler_weather_value');
		parent::uninstall();
	}
	
	function dbInstall($data = '') {
      $data = <<<EOD
        rambler_weather_city: ID int(15) unsigned NOT NULL auto_increment
        rambler_weather_city: TITLE varchar(255) NOT NULL DEFAULT ''
        rambler_weather_city: URL_PATH varchar(255) NOT NULL DEFAULT ''
        rambler_weather_city: GEO_CODE varchar(255) NOT NULL DEFAULT ''
        rambler_weather_city: ADD int(15) unsigned NOT NULL DEFAULT 0


		rambler_weather_value: ID int(15) unsigned NOT NULL auto_increment
        rambler_weather_value: CITY_ID int(15) unsigned NOT NULL DEFAULT 0
        rambler_weather_value: TITLE varchar(255) NOT NULL DEFAULT ''
        rambler_weather_value: VALUE text
        rambler_weather_value: UPDATE int(15) unsigned NOT NULL DEFAULT 0
        rambler_weather_value: LINKED_OBJECT varchar(100) NOT NULL DEFAULT ''
        rambler_weather_value: LINKED_PROPERTY varchar(100) NOT NULL DEFAULT ''
        rambler_weather_value: LINKED_METHOD varchar(100) NOT NULL DEFAULT ''
EOD;
		parent::dbInstall($data);
		$this->upgradeSchema();
   }
}
