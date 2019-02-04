<?php
require_once(__DIR__ . "/funcs.php");
require_once(__DIR__ . "/config.php");
$response = file_get_contents('php://input');
$data = json_decode($response, true);
$dump = print_r($data, true);

$dbConnection = buildDatabaseConnection($config);
if (isset($data['callback_query'])) {
  $queryId = $data['callback_query']['id'];
  mail($config['mail'], 'Test', print_r($data, true));
  answerCallbackQuery($queryId, 'Gespeichert.');
} else if (isset($data['inline_query'])) {
  $inlineQueryId = $data['inline_query']['id'];
  $senderUserId = $data['inline_query']['from']['id'];
  $search = $data['inline_query']['query'];

  $results = array();
  //Return all polls from $senderUserId
  $polls = getAllPolls($senderUserId, $search);
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
            'callback_data' => 'Yes'
          )
        ),
        array(
          array(
            'text' => 'Vielleicht - ' . $attendeesMaybe,
            'callback_data' => 'Maybe'
          )
        ),
        array(
          array(
            'text' => 'Abmeldung - ' . $attendeesNo,
            'callback_data' => 'No'
          )
        )
      )
    );
    $results[] = array(
      'type' => 'article',
      'id' => $pollId,
      'title' => $pollTitle,
      'input_message_content' => array(
        'message_text' => $pollText . buildPollAttendees($pollId, $attendeesYes, $attendeesMaybe, $attendeesNo),
        'parse_mode' => 'html',
        'disable_web_page_preview' => true
      ),
      'reply_markup' => $replyMarkup,
      'description' => $attendeesYes + $attendeesMaybe + $attendeesNo . ' Teilnehmer'
    );
    //ToDo: Use Post not GET
  }
  answerInlineQuery($inlineQueryId, $results);
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
}
if (isset($data['message']['reply_to_message'])) {
  $replyToMessage = $data['message']['reply_to_message'];
  $repliedToMessageId = $replyToMessage['message_id'];
}
$messageId = $data['message']['message_id'];

if (isset($text) && !isset($repliedToMessageId)) {

  if (substr($text, '0', '1') == '/') {
    $messageArr = explode(' ', $text);
    $command = explode('@', $messageArr[0])[0];
    if ($messageArr[0] == '/start' && isset($messageArr[1])) {
      $command = '/' . $messageArr[1];
    }
  } else {
    sendChatAction($chatId, 'typing');
    $forceReply = array(
      'force_reply' => true
    );
    $feedbackMessageId = sendMessage($chatId, "Ich erstelle die Umfrage <i>$text</i>.
Sende mir nun den Inhalt/die Beschreibung der Umfrage.", '', json_encode($forceReply))['message_id'];
    createPoll($senderUserId, $messageId, $feedbackMessageId, $text);
    die();
  }

  $command = strtolower($command);

  switch ($command) {
    case '/start':
      sendMessage($chatId, 'Hallo!
Ich bin der Stammtisch Bot. Durch mich kannst du Registrationen für Meetups oder Stammtische erstellen.
Um anzufangen, sende mir einfach den Titel deiner Registration, dann können wir los legen.');
      break;
  }
} else if (isset($text) && isset($repliedToMessageId)) {
  sendChatAction($chatId, 'typing');
  list($pollId, $status, $title) = getPoll($senderUserId, $repliedToMessageId);
  if ($pollId === false) {
    sendMessage($chatId, 'Error oder nicht gefunden');
    die();
  }
  setPollContent($senderUserId, $repliedToMessageId, $text);
  $replyMarkup = array(
    'inline_keyboard' => array(
      array(
        array(
          'text' => 'Schließen',
          'callback_data' => 'close'
        )
      )
    )
  );
  sendMessage($chatId, "Fertig. Du kannst die Umfrage nun mit <code>@stammtischanmeldung_bot $title</code> in Gruppen teilen.", json_encode($replyMarkup));
}
