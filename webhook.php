<?php
require_once(__DIR__ . "/funcs.php");
require_once(__DIR__ . "/config.php");
require_once('/var/libraries/composer/vendor/autoload.php');
//^ guzzlehttp
$response = file_get_contents('php://input');
$data = json_decode($response, true);
$dump = print_r($data, true);

if (file_exists($config['timeoutsave'])) {
  $timeouts = json_decode(file_get_contents($config['timeoutsave']), true);
  //$timeouts = json_decode('{}', true);
} else {
  file_put_contents($config['timeoutsave'], '{}');
  $timeouts = json_decode(file_get_contents($config['timeoutsave']), true);
}

$dbConnection = buildDatabaseConnection($config);
if (isset($data['callback_query'])) {
  if (isset($data['callback_query']['message'])) {
    $chatId = $data['callback_query']['message']['chat']['id'];
    $messageId = $data['callback_query']['message']['message_id'];
    $chatType = $data['callback_query']['message']['chat']['type'];
  } else {
    $chatId = $messageId = $chatType = '';
  }
  $callbackData = $data['callback_query']['data'];
  $senderUserId = $data['callback_query']['from']['id'];
  $queryId = $data['callback_query']['id'];
  $senderName = $data['callback_query']['from']['first_name'];
  if (isset($data['callback_query']['from']['last_name'])) {
    $senderName .= ' ' . $data['callback_query']['from']['last_name'];
  }

  //$senderName = filterSymbols($senderName);
  $senderName = urldecode(str_replace('%E2%80%8F', '', urlencode($senderName)));

  if (stripos($callbackData, '|') !== false) {
    list($method, $feedbackMessageId, $confirm, $time) = explode('|', $callbackData);
    if ($method === 'vote') {
      $timeouts = checkLastExecute($timeouts, 'vote', $chatType, $senderUserId);
      if ($timeouts === false) {
        answerCallbackQuery($queryId, 'Nicht so schnell.');
        die();
      }
      file_put_contents($config['timeoutsave'], json_encode($timeouts));
      $inlineQueryMessageId = $data['callback_query']['inline_message_id'];
      list($pollId, $status, $title, $pollText, $max) = getPoll('', '', $inlineQueryMessageId);
      if ($status === 1 && ($max === 0 || ($max > getPollAttendees($pollId)['yes'] || $confirm != 1))) {
        if (setAttendanceStatus($pollId, $senderUserId, $senderName, $confirm)) {
          //Only update text if status changed
          updatePoll($pollId);
        }
      } else {
          answerCallbackQuery($queryId, 'Maximale Anzahl erreicht oder geschlossen.');
      }
      answerCallbackQuery($queryId);
    } else if ($method === 'close') {
      $poll = getPoll($senderUserId, $feedbackMessageId);
      $pollId = $poll['id'];
      $pollText = $poll['text'];
      if ($confirm == 1 && $time + 10 >= time()) {
        if (closePoll($pollId)) {
          answerCallbackQuery($queryId);
          updatePoll($pollId, true);
          list($attendeesYes, $attendeesMaybe, $attendeesNo) = getPollAttendees($pollId);
          $attendees = buildPollAttendees($pollId, $attendeesYes, $attendeesMaybe, $attendeesNo, true);
          editMessageText($chatId, $messageId, "<b>Umfrage geschlossen.</b>");
          sendMessage($chatId, $attendees);
        } else {
          answerCallbackQuery($queryId, 'Fehler');
        }
      } else {
        $replyMarkup = array(
          'inline_keyboard' => array(
            array(
              array(
                'text' => 'Ja',
                'callback_data' => 'close|' . $feedbackMessageId . '|1|' . time()
              ),
              array(
                'text' => 'Nein',
                'callback_data' => 'no'
              )
            )
          )
        );
        answerCallbackQuery($queryId, 'Sicher?');
        editMessageText($chatId, $messageId, 'Willst du die Umfrage wirklich schließen?', $replyMarkup);
      }
    } else if ($method === 'update') {
      $pollId = getPoll($senderUserId, $feedbackMessageId)['id'];
      if (updatePollText($pollId)) {
        updatePoll($pollId);
      }
      answerCallbackQuery($queryId);
      editMessageText($chatId, $messageId, 'Text wurde aktualisiert.');
    }
  } else {
    answerCallbackQuery($queryId);
  }
  die();
} else if (isset($data['inline_query'])) {
  $inlineQueryId = $data['inline_query']['id'];
  $senderUserId = $data['inline_query']['from']['id'];
  $search = $data['inline_query']['query'];
  $newOffset = $data['inline_query']['offset'];

  if ($newOffset === '' || $newOffset === 0) {
    $offset = 0;
  } else {
    $offset = $newOffset;
  }

  $results = array();

  $polls = getAllPolls($senderUserId, $search, $offset);
  foreach ($polls as $poll) {
    $pollId = $poll['id'];
    $pollTitle = $poll['title'];
    $pollText = $poll['text'];
    list($attendeesYes, $attendeesMaybe, $attendeesNo) = getPollAttendees($pollId);
    $replyMarkup = array(
      'inline_keyboard' => array(
        array(
          array(
            'text' => 'Anmeldung - ' . $attendeesYes,
            'callback_data' => 'vote|0|1|0'
          )
        ),
        array(
          array(
            'text' => 'Vielleicht - ' . $attendeesMaybe,
            'callback_data' => 'vote|0|2|0'
          )
        ),
        array(
          array(
            /*'text' => 'Abmeldung - ' . $attendeesNo,*/
            'text' => 'Abmeldung',
            'callback_data' => 'vote|0|3|0'
          )
        )
      )
    );
    $messageText = $pollText . buildPollAttendees($pollId, $attendeesYes, $attendeesMaybe, $attendeesNo, true);
    if (mb_strlen($messageText) > 4000) {
      $messageText = $pollText . buildPollAttendees($pollId, $attendeesYes, $attendeesMaybe, $attendeesNo);
      if (mb_strlen($messageText) > 4000) {
        $messageText = $pollText;
      }
    }
    $results[] = array(
      'type' => 'article',
      'id' => $pollId,
      'title' => $pollTitle,
      'input_message_content' => array(
        'message_text' => $messageText,
        'parse_mode' => 'html',
        'disable_web_page_preview' => true
      ),
      'reply_markup' => $replyMarkup,
      'description' => $attendeesYes + $attendeesMaybe + $attendeesNo . ' Teilnehmer'
    );
  }
  answerInlineQuery($inlineQueryId, $results, $offset);
  die();
} else if (isset($data['chosen_inline_result'])) {
  $inlineQueryMessageId = $data['chosen_inline_result']['inline_message_id'];
  $senderUserId = $data['chosen_inline_result']['from']['id'];
  $pollId = $data['chosen_inline_result']['result_id'];
  newPollPost($inlineQueryMessageId, $pollId);
  die();
}

$chatId = $data['message']['chat']['id'];
$chatType = $data['message']['chat']['type'];
$senderUserId = $data['message']['from']['id'];
if (isset($data['message']['text'])) {
  $text = $data['message']['text'];
  if (isset($data['message']['entities'])) {
    $additionalOffset = 0;
    foreach ($data['message']['entities'] as $entity) {
      $offset = $entity['offset'] + $additionalOffset;
      //<i> = 3 long. </i> 4 long. 3+4=7 additonal offset on every entity
      if ($entity['type'] === 'italic') {
        $text = mb_substr_replace($text, '<i>', $offset, NULL);
        $text = mb_substr_replace($text, '</i>', $offset + 3 + $entity['length'], NULL);
        $additionalOffset += 7;
      } else if ($entity['type'] === 'bold') {
        $text = mb_substr_replace($text, '<b>', $offset, NULL);
        $text = mb_substr_replace($text, '</b>', $offset + 3 + $entity['length'], NULL);
        $additionalOffset += 7;
      } else if ($entity['type'] === 'code') {
        $text = mb_substr_replace($text, '<code>', $offset, NULL);
        $text = mb_substr_replace($text, '</code>', $offset + 6 + $entity['length'], NULL);
        $additionalOffset += 13;
      }
      //+ 3 and 6 because length of tags ads up
    }
  }
}
if (isset($data['message']['reply_to_message'])) {
  $replyToMessage = $data['message']['reply_to_message'];
  $repliedToMessageId = $replyToMessage['message_id'];
}
$messageId = $data['message']['message_id'];
$userName = $data['message']['from']['first_name'];
if (isset($data['message']['from']['last_name'])) {
  $userName .= ' ' . $data['message']['from']['last_name'];
}

if (isset($data['message']['from']['username'])) {
  $userName = $data['message']['from']['username'];
}

if (isset($text) && !isset($repliedToMessageId)) {
  if (substr($text, '0', '1') == '/') {
    $messageArr = explode(' ', $text);
    $command = explode('@', $messageArr[0])[0];
    if ($messageArr[0] == '/start' && isset($messageArr[1])) {
      list($pollId, $status, $title, $pollText, $max) = getPoll('', '', $messageArr[1]);
      sendMessage($chatId, $pollText);
      die();
    }
  } else {
    sendChatAction($chatId, 'typing');
    $text = filterSymbols($text, true);
    if (strlen($text) <= 70) {
      $forceReply = array(
        'force_reply' => true
      );
      $feedbackMessageId = sendMessage($chatId, "Ich erstelle die Umfrage \"$text\".
Sende mir nun den Inhalt/die Beschreibung der Umfrage.
Um dies nachträglich zu ändern, antworte einfach auf diese Nachricht.", '', json_encode($forceReply))['message_id'];
      createPoll($senderUserId, $userName, $messageId, $feedbackMessageId, $text);
      die();
    } else {
      sendMessage($chatId, 'Der Titel der Umfrage darf nicht länger als 70 Zeichen sein. 
Der eigentliche Umfrage-Text folgt erst, nach dem du einen Titel gewählt hast.');
    }
  }

  $command = strtolower($command);

  switch ($command) {
    case '/start':
      if ($messageArr) {
        sendMessage($chatId, '<b>Hallo!</b>

Ich bin der Gästebuch Bot.
Durch mich kannst du ein Gästebuch für Meetups oder Stammtische erstellen!

<b>Um anzufangen, sende mir einfach den Titel deines Gästebuchs, dann können wir los legen.</b>
Vergiss aber nicht, dass Nachrichten nicht länger als <i>4000 Zeichen</i> lang sein dürfen.

Falls du etwas nicht verstehst, kannst du hier eine Demonstration des Bots sehen: https://img.kieran.de/mO2zvp1.mp4

<b>Wichtig</b>: Wenn du Formatierungen (Fett, Kursiv, etc) mit Emojis nutzen möchtest, tue dies bitte manuell, so wie <a href="https://img.kieran.de/VnsEH79.png">hier</a>!
Erklärung: /emojis');
      }
      break;
    case '/test':
      mail($config['mail'], 'Dump', $dump);
      sendMessage($chatId, $text);
      break;
    case '/emojis':
      sendMessage($chatId, 'Leider habe ich noch keinen Weg um das Emoji-Problem gefunden. Laut Telegram ist ein Emoji nämlich zwei Zeichen lang.

Der Bot denkt jedoch, dass ein Emoji nur ein Zeichen lang ist. Daher würde er aus "[Emoji]<b>fett</b>normal", ein "[Emoji]<b>fettn</b>ormal" entstehen.');
  }
} else if (isset($text) && isset($repliedToMessageId)) {
  sendChatAction($chatId, 'typing');
  list($pollId, $status, $title, $pollText, $max) = getPoll($senderUserId, $repliedToMessageId);
  if ($pollId === false) {
    sendMessage($chatId, 'Error oder Umfrage nicht gefunden. Bitte erstelle eine neue Umfrage oder kontaktiere @DatDraggy.');
    die();
  }

  if (substr($text, 0, 4) === '/max') {
      $max = (int) substr($text, 5);
      if ($max >= 0) {
          updateMax($pollId, $max);
      }
      sendMessage($chatId, 'Anzahl Aktualisiert.');
      die();
  }

  if (mb_strlen($text) > 4000) {
    sendMessage($chatId, 'Leider darf der Text nicht länger als 4000 Zeichen sein.');
    die();
  }
  if ($pollText === NULL) {
    setPollContent($senderUserId, $repliedToMessageId, $text);
    $replyMarkup = array(
      'inline_keyboard' => array(
        array(
          array(
            'text' => 'Schließen',
            'callback_data' => "close|$repliedToMessageId|0|" . time()
          )
        )
      )
    );
    sendMessage($chatId, "Fertig. Du kannst die Umfrage nun, in dem du '@gaestebuch_bot $title' in deine Textzeile schreibst, in Gruppen teilen.
Falls du die Umfrage beenden möchtest, drücke einfach den \"Schließen\" Knopf.", '', json_encode($replyMarkup));
  } else if ($status === 1) {
    setPollNewContent($senderUserId, $repliedToMessageId, $text);
    $replyMarkup = array(
      'inline_keyboard' => array(
        array(
          array(
            'text' => 'Ja',
            'callback_data' => "update|$repliedToMessageId|0|" . time()
          ),
          array(
            'text' => 'Nein',
            'callback_data' => 'no'
          )
        )
      )
    );
    sendMessage($chatId, "$text

Okay. Willst du den Umfragetext ändern?", '', json_encode($replyMarkup));
  }
}
