<?php

const API_KEY = 'AIzaSyByjQCWlKAH_uKFlnN0fCUYduP8sXnjQLo';
const APP_ID = '156070991586244';
const APP_SECRET = '242b83d9eefedcf3e996e8c505e43366';
const ACCESS_TOKEN = 'EAACN8hwDY8QBAEcLkz9b9FZB2QXVgr92ZBduX8cEU1rfZBR7kOtzurRUtiWkZCan496HmhLyiWLnk86RAKsfMSiYKxZBdnIC6KftcZBy7EODHgPBERWpjFZCgqvPYWGUQyutGc76VccANwiCvrPxa9BCO7f3jnbTs2jXjZCzXk06OgZDZD';
require './interpretirajZahtjev.php';

// handle user message
//	$input = ['entry'=>[['messaging'=>[['sender'=>['id'=>'1532028376807777'], 'message'=>['text'=>'Zainteresiran sam za kupnju Logitechovog miša G203']]]]]];	// for debugging purposes
$input = json_decode(file_get_contents('php://input'), true, 512, JSON_BIGINT_AS_STRING);
$senderId = $input['entry'][0]['messaging'][0]['sender']['id'];
$response = null;
$command = '';

if ($messageInfo = $input['entry'][0]['messaging'][0]) {
	$conn = pg_connect('postgres://gsnnkdcbycpcyq:ba69093c4619187587610e80e188d4f812627530798ef14d3133bd3541b00290@ec2-54-228-235-185.eu-west-1.compute.amazonaws.com:5432/dedt0mj008catq');
	$result = pg_query("INSERT INTO user_account VALUES ('$senderId');");	// this is performed whether the user id is already in database or not - all the other table attributes are nullable, so their values don't need to be explicitly set
	if ($result && pg_affected_rows($result) === 1) {
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL => 'https://graph.facebook.com/v2.8/' . $senderId . '?fields=first_name,last_name&app_secret=' . APP_SECRET . '&access_token=' . ACCESS_TOKEN,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				'content-type: application/json'
			)
		));
		$result = json_decode(curl_exec($ch), true);
		curl_close($ch);
		$ime = $result['first_name'];
		$prezime = $result['last_name'];
		pg_query("UPDATE user_account SET first_name='$ime', last_name='$prezime' WHERE id='$senderId';");
		replyBackWithSimpleText("Poštovanje $ime $prezime,\nRazgovarate s virtualnim asistentom koji će Vas voditi kroz kupovinu. Dovoljno je navesti u slobodnom formatu (po mogućnosti u službenom hrvatskom jeziku) što tražite, bilo naziv proizvođača, marke, vrste komponente i/ili cjenovni raspon traženog proizvoda. Kao rezultat se vraćaju stavke dostupne iz Linskovog web-shopa koje je onda moguće jednostavno naručiti.", false);
		$korisnikUpravoDeklariran = true;
	}
	$result = pg_query("SELECT * FROM user_account u LEFT JOIN address a ON u.address=a.id WHERE u.id='$senderId' LIMIT 1;");
	$userInfo = pg_fetch_array($result, null, PGSQL_ASSOC);
	pg_free_result($result);
	if (!empty($messageInfo['message']['quick_reply']['payload'])) {
		$command = $messageInfo['message']['quick_reply']['payload'];
		if (empty($userInfo['currently_edited_attribute'])) {
			$commandParts = explode(' ', $command);
			$linkProizovada = $commandParts[0];
			unset($commandParts[0]);
			$action = implode(' ', $commandParts);	// lokacije Zagreb Trešnjevka, Zagreb Dubrava i Slavonski Brod se sastoje od više riječi
			$delivery = ($action === 'dostava');
			$closestStore = $action;
			$desiredProducts = [ 'https://www.links.hr' . $linkProizovada => 1 ];
			changeTypingIndicator(true);
			require 'naruciRobu.php';

			if (!empty($ordererOutput)) {
				$ordererOutput = explode("\n", $ordererOutput);
				$price = floatval(str_replace(array('.', ','), array('', '.'), explode(' ', $ordererOutput[0])[0]));
				$placeName = mb_convert_case($city, MB_CASE_TITLE);
				$numOfOutputRows = count($ordererOutput);
				$orderedItems = [];
				for ($i=2; $i<$numOfOutputRows; $i+=2) {
					$productName = $ordererOutput[$i-1];
					$productImageUrl = $ordererOutput[$i];
					extractTitleAndSubtitle($productName, $title, $subtitle);
					$orderedItems[] = ['title'=>$title, 'subtitle'=>$subtitle,'quantity'=>1,'price'=>$price,'currency'=>'HRK','image_url'=>$productImageUrl];
				}
				$answer = [
					'type'=>'template',
					'payload'=>[
						'template_type'=>'receipt',
						'recipient_name'=>"$firstName $lastName",
						'order_number'=>'123456',
						'currency'=>'HRK',
						'payment_method'=>'Plaćanje pouzećem',
						'address'=>['street_1'=>$address,'city'=>$placeName,'postal_code'=>$postCode,'state'=>'Hrvatska','country'=>'CRO'],
						'summary'=>['subtotal'=>0,'shipping_cost'=>0,'total_tax'=>0,'total_cost'=>$price],
						'elements'=> $orderedItems
					]
				];
				
				changeTypingIndicator(false);
				replyBackSpecificObject([ 'attachment' => $answer ]);
			}
			else {
				changeTypingIndicator(false);
				replyBackWithSimpleText($answer);
			}
		}
		else {
			switch ($command) {
				case 'first_name':
					pg_query("UPDATE user_account SET currently_edited_attribute='last_name' WHERE id='$senderId';");
					posaljiZahtjevZaOdabirom('last_name');
					break;
				case 'last_name':
					pg_query("UPDATE user_account SET currently_edited_attribute='address' WHERE id='$senderId';");
					posaljiZahtjevZaOdabirom('address', false, 'Uspješno ste registrirali svoje stvarno puno ime!');
					break;
				case 'address':
					pg_query("UPDATE user_account SET currently_edited_attribute='email' WHERE id='$senderId';");
					posaljiZahtjevZaOdabirom('email');
					break;
				case 'email':
					pg_query("UPDATE user_account SET currently_edited_attribute='phone' WHERE id='$senderId';");
					posaljiZahtjevZaOdabirom('phone');
					break;
				case 'phone':
					pg_query("UPDATE user_account SET currently_edited_attribute=NULL WHERE id='$senderId';");
					replyBackWithSimpleText('Možete dalje nastaviti normalno koristiti pogodnosti chatbota!');
					break;
				default:	// full_name da/ne
					if (substr($command, strpos($command, ' ')+1) === 'da') {
						pg_query("UPDATE user_account SET currently_edited_attribute='address' WHERE id='$senderId';");
						posaljiZahtjevZaOdabirom('address');
					}
					else {
						pg_query("UPDATE user_account SET currently_edited_attribute='first_name' WHERE id='$senderId';");
						posaljiZahtjevZaOdabirom('first_name');
					}
			}
		}
	}
	else if (!empty($messageInfo['message']['text'])) {
		$command = $messageInfo['message']['text'];

		if (!empty($userInfo['currently_edited_attribute'])) {
			switch ($userInfo['currently_edited_attribute']) {
				case 'first_name':
					$firstName = trim($command, ". \t\n\r\0\x0B");
					if (!empty($firstName)) {
						pg_query_params("UPDATE user_account SET first_name=$1, currently_edited_attribute='last_name' WHERE id='$senderId';", array($firstName));
						posaljiZahtjevZaOdabirom('last_name');
					}
					else {
						posaljiZahtjevZaOdabirom('first_name', true, 'Ime ne može biti neprazno jer je očito nestvarno!');
					}
					break;
				case 'last_name':
					$lastName = trim($command, ". \t\n\r\0\x0B");
					if (!empty($lastName)) {
						pg_query_params("UPDATE user_account SET last_name=$1, currently_edited_attribute='address' WHERE id='$senderId';", array($lastName));
						posaljiZahtjevZaOdabirom('address', false, 'Uspješno ste registrirali svoje stvarno puno ime!');
					}
					else {
						posaljiZahtjevZaOdabirom('last_name', true, 'Prezime ne može biti neprazno jer je očito nestvarno!');
					}
					break;
				case 'address':
					$command = urlencode($command);
					$ch = curl_init();
					curl_setopt_array($ch, array(
						CURLOPT_URL => "https://maps.googleapis.com/maps/api/geocode/json?address=$command&key=" . API_KEY,
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_CUSTOMREQUEST => 'GET',
						CURLOPT_HTTPHEADER => array(
							'content-type: application/json'
						)
					));
					$result = json_decode(curl_exec($ch), true);
					curl_close($ch);
					if ($result['status'] === 'OK') {
						if (count($result['results']) === 1) {
							foreach ($result['results'][0]['address_components'] as $comp) {
								if (in_array('street_number', $comp['types'])) {
									$streetNum = $comp['short_name'];
								}
								else if (in_array('route', $comp['types'])) {
									$route = $comp['long_name'];
								}
								else if (in_array('postal_code', $comp['types'])) {
									$postalCode = $comp['short_name'];
								}
							}
							if (isset($streetNum) && isset($route) && isset($postalCode)) {
								pg_query("UPDATE user_account SET address=get_address_id('$streetNum', '$route', '$postalCode'), currently_edited_attribute='email' WHERE id='$senderId';");
								posaljiZahtjevZaOdabirom('email', false, 'Uspješno ste registrirali adresu uz Vaš korisnički račun!');
							}
							else {
								posaljiZahtjevZaOdabirom('address', true, 'Molimo Vas da navedete sve komponente adrese koje su nam od značaja poput naziva ulice i kućnog broja te naziva poštanskog mjesta ili njegovog pripadajućeg broja.');
							}
						}
						else {
							posaljiZahtjevZaOdabirom('address', true, 'Molimo Vas da precizirate adresu! Naime, ne može se pouzdano otkriti o kojem je točno mjestu riječ.');
						}
					}
					else {
						posaljiZahtjevZaOdabirom('address', true, 'Molimo Vas da precizirate adresu! Naime, nije pronađeno nijedno mjesto koje odgovara na navedeni opis.');
					}
					break;
				case 'email':
					if (preg_match('/\S*@\S*\.\S*/', $command, $matches)) {
						$email = trim($matches[0], ':.,-;?!');
						pg_query_params("UPDATE user_account SET email=$1, currently_edited_attribute='phone' WHERE id='$senderId';", array($email));	// protection against potential attacks like sql-injection
						posaljiZahtjevZaOdabirom('phone', false, 'Uspješno ste registrirali e-mail adresu uz Vaš korisnički račun!');
					}
					else {
						posaljiZahtjevZaOdabirom('email', true, 'Niste naveli e-mail adresu ili ona koju ste naveli nije važećeg formata!');
					}
					break;
				case 'phone':
					if (preg_match_all('/(?:\+\s*)?\d+(?:(?:\s|\/|\-)+\d+)*/', $command, $matches)) {
						foreach ($matches[0] as $numWithSeparators) {
							$number = preg_replace('(-|/|\s)', '', $numWithSeparators);
							if (strlen($number) > 7) {
								pg_query("UPDATE user_account SET phone='$number', currently_edited_attribute=NULL WHERE id='$senderId';");
								replyBackWithSimpleText("Uspješno ste registrirali telefonski broj uz Vaš korisnički račun!\nSada možete započeti s pretragom i naručivanjem artikala.");
							}
							else {
								posaljiZahtjevZaOdabirom('phone', true, 'Niste naveli važeći telefonski broj!');
							}
						}
					}
					else {
						posaljiZahtjevZaOdabirom('phone', true, 'Niste naveli važeći telefonski broj!');
					}
					break;
				default:
					posaljiZahtjevZaOdabirom($userInfo['currently_edited_attribute'], true);
			}
		}
	}
	// When bot receives button click from user
	else if (!empty($messageInfo['postback'])) {
		if (empty($userInfo['phone'])) {	// if this attribute is not defined, then user still hasn't finished registration process
			pg_query("UPDATE user_account SET currently_edited_attribute='full_name' WHERE id='$senderId';");
			posaljiZahtjevZaOdabirom('full_name');
		}
		else if (!empty($userInfo['currently_edited_attribute'])) {
			posaljiZahtjevZaOdabirom($userInfo['currently_edited_attribute'], true);
		}
		else {
			$command = $messageInfo['postback']['payload'];
			if(strpos($command, '/hr/') === 0){
				$linkProizovada = $command;
				require './provjeraDostupnosti.php';
				$answer = [ 'text' => $replyContent ];
				if (!empty($quickReplies)) {
					$answer['quick_replies'] = $quickReplies;
				}
				replyBackSpecificObject($answer);
			}
		}
	}
}

foreach( json_decode(file_get_contents("./intents.json"),true) as $k => $v){
	if (mb_stripos($command, $k) !== false) {
		if ($k === 'radno vrijeme') {
			require 'radnoVrijeme.php';
			if ($exactOpeningHours) {
				$v = $exactOpeningHours;
			}
		}
		replyBackWithSimpleText($v);
	}
}

$translatedInput = translateInput(prilagodiZahtjev(mb_strtoupper($command)), 'hr', 'en');	// when translating from bosnian language to english (instead from croatian), currency names/symbols are preserved (i have no idea why)
if($translatedInput['status'] === 'OK'){
	$nlpText = NLPtext($translatedInput['translate']);
}else{
	replyBackWithSimpleText('Unesena je narudžba na krivom jeziku!');
}

$translatedOutput = translateInput($nlpText['tekst'], 'en', 'hr');

if($translatedOutput['status'] === 'OK'){
	$translatedOutputText = $translatedOutput['translate'];
}else{
	replyBackWithSimpleText('Došlo je do pogreške!');
}

$nlpText['tekst'] = urediIzlaz($translatedOutputText);

$datum = new DateTime();
$datumString = $datum->format('Y-m-d H:i:s');


require './traziRobu.php';

if(!empty($obj)){
	$buttons = array();
	$itemsNum = min(10, count($obj));
	for($i=0; $i<$itemsNum; $i++){
		extractTitleAndSubtitle($obj[$i]->naziv, $title, $subtitle, $obj[$i]->cijena);
		array_push($buttons, array(
			'title' => htmlspecialchars_decode($title, ENT_QUOTES),
			'image_url' => $obj[$i]->slika,
			'subtitle' => 
				htmlspecialchars_decode($subtitle, ENT_QUOTES),
			'default_action' => [
				'type' => 'web_url',
				'url' => 'https://www.links.hr' . $obj[$i]->link . '#quickTabs',
				'messenger_extensions' => true,
				'webview_height_ratio'=> 'TALL'
			],
			'buttons' => array(
				array(
					'type' => 'postback',
					'payload' => $obj[$i]->link,
					'title' => 'Naruči proizvod'
					)
				)
			)
		);
	}

	$answer = [
		'type'=>'template',
		'payload'=>[
			'template_type'=>'generic',
			'elements'=> $buttons
		]
	];

	replyBackSpecificObject([ 'attachment' => $answer ]);
}else{
	if (!isset($korisnikUpravoDeklariran)) {
		replyBackWithSimpleText('Nisu nađeni proizvodi koji odgovaraju zadanim kriterijima');
	}
	else {
		pg_close();
	}
}


function replyBackSpecificObject($answer, $zavrsi=true) {
	global $senderId;
	if ($zavrsi) {
		pg_close();
	}
	$response = [
		'messaging_type' => 'RESPONSE',
		'recipient' => [ 'id' => $senderId ],
		'message' => $answer
	];
	$ch = curl_init('https://graph.facebook.com/v2.6/me/messages?access_token=' . ACCESS_TOKEN);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($response));
	curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
	$result = curl_exec($ch);
	curl_close($ch);
	if ($zavrsi) {
		exit();
	}
}

function replyBackWithSimpleText($text, $zavrsi=true) {
	replyBackSpecificObject([ 'text' => $text ], $zavrsi);
}

function changeTypingIndicator($turnOn) {
	global $senderId;
	$ch = curl_init('https://graph.facebook.com/v2.6/me/messages?access_token=' . ACCESS_TOKEN);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
		[
			'recipient' => [ 'id' => $senderId ],
			'sender_action' => $turnOn ? 'typing_on' : 'typing_off'
		]
	));
	curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
	$result = curl_exec($ch);
	curl_close($ch);
}

function posaljiZahtjevZaOdabirom($atribut, $ponavljanje=false, $prefiks='') {
	global $userInfo;
	if (!empty($prefiks)) {
		$replyContent = $prefiks . "\n";
	}
	else {
		$replyContent = '';
	}
	$quickReplies = [];
	switch ($atribut) {
		case 'full_name':
			if ($ponavljanje) {
				$replyContent .= "Potrebno je odabrati jednu od ponuđenih opcija! Ponavljamo, da li je '$userInfo[first_name] $userInfo[last_name]' Vaše pravo ime?";
			}
			else {
				if (empty($userInfo['phone'])) {
					$replyContent .= "Za daljnje korištenje aplikacije potrebno se je registrirati. Za početak, odgovorite da li je '$userInfo[first_name] $userInfo[last_name]' Vaše puno ime.";
				}
				else {
					$replyContent .= "Da li je '$userInfo[first_name] $userInfo[last_name]' Vaše puno ime?";
				}
			}
			array_push($quickReplies, array('content_type'=>'text', 'title'=>'Da', 'payload' => 'full_name da'));
			array_push($quickReplies, array('content_type'=>'text', 'title'=>'Ne', 'payload' => 'full_name ne'));
			break;
		case 'first_name':
			if ($ponavljanje) {
				$replyContent .= 'Ponavljamo, napišite Vaše ime ili odaberite da se zadrži dosadašnje.';
			}
			else {
				$replyContent .= 'Navedite Vaše ime:';
			}
			if (!empty($userInfo['first_name'])) {
				array_push($quickReplies, array('content_type'=>'text', 'title'=>"zadrži '$userInfo[first_name]'", 'payload' => "first_name"));
			}
			break;
		case 'last_name':
			if ($ponavljanje) {
				$replyContent .= 'Ponavljamo, napišite Vaše prezime ili odaberite da se zadrži dosadašnje.';
			}
			else {
				$replyContent .= 'Navedite Vaše prezime:';
			}
			if (!empty($userInfo['last_name'])) {
				array_push($quickReplies, array('content_type'=>'text', 'title'=>"zadrži '$userInfo[last_name]'", 'payload' => "last_name"));
			}
			break;
		case 'address':
			if ($ponavljanje) {
				if (empty($userInfo['address'])) {
					$replyContent .= 'Ponavljamo, napišite Vašu adresu stanovanja ili dostavljanja.';
				}
				else {
					$replyContent .= 'Ponavljamo, napišite Vašu adresu stanovanja ili dostavljanja, ili pak odaberite da se zadrži dosadašnja.';
				}
			}
			else {
				$replyContent .= 'Navedite Vašu adresu stanovanja ili adresu na koju želite da Vam se dostavi roba:';
			}
			if (!empty($userInfo['address'])) {
				array_push($quickReplies, array('content_type'=>'text', 'title'=>"zadrži '$userInfo[address]'", 'payload' => "address"));
			}
			break;
		case 'email':
			if ($ponavljanje) {
				if (empty($userInfo['email'])) {
					$replyContent .= 'Ponavljamo, napišite Vašu e-mail adresu.';
				}
				else {
					$replyContent .= 'Ponavljamo, napišite Vašu e-mail adresu ili odaberite da se zadrži dosadašnja.';
				}
			}
			else {
				$replyContent .= 'Navedite Vašu e-mail adresu na koju ćete biti u mogućnosti kontaktirani:';
			}
			if (!empty($userInfo['email'])) {
				array_push($quickReplies, array('content_type'=>'text', 'title'=>"zadrži '$userInfo[email]'", 'payload' => "email"));
			}
			break;
		case 'phone':
			if ($ponavljanje) {
				if (empty($userInfo['phone'])) {
					$replyContent .= 'Ponavljamo, napišite Vaš telefonski broj.';
				}
				else {
					$replyContent .= 'Ponavljamo, napišite Vaš telefonski broj ili odaberite da se zadrži dosadašnji.';
				}
			}
			else {
				$replyContent .= 'Navedite Vaš telefonski broj na koji ćete biti u mogućnosti kontaktirani:';
			}
			if (!empty($userInfo['phone'])) {
				array_push($quickReplies, array('content_type'=>'text', 'title'=>"zadrži '$userInfo[phone]'", 'payload' => "phone"));
			}
			break;

	}
	$answer = [ 'text' => $replyContent ];
	if (!empty($quickReplies)) {
		$answer['quick_replies'] = $quickReplies;
	}
	replyBackSpecificObject($answer);
}

function extractTitleAndSubtitle($productName, &$title, &$subtitle, $price=null) {
	if ($price === null) {
		$title = $productName;
		$subtitle = '';
	}
	else {
		$title = preg_replace('/,\s*cijena:.+?(,|$)/i', '\1', $productName, 1);
		$subtitle = 'Cijena: ' . $price . " kn\n";
	}
	$titleLength = strlen($title);
	if ($titleLength > 80) {
		if ( ($titleLimit = strrpos($title, ',', 80-$titleLength))!==false || ($titleLimit = strrpos($title, ' ', 80-$titleLength))!==false ) {
			$subtitle .= substr($title, $titleLimit+1);
			$title = substr($title, 0, $titleLimit);
		}
	}
}