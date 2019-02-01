<?php
require_once(__DIR__ . "/funcs.php");
require_once(__DIR__ . "/config.php");
$response = file_get_contents('php://input');
$data = json_decode($response, true);
$dump = print_r($data, true);

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
    $dbConnection = buildDatabaseConnection($config);
    sendChatAction($chatId, 'typing');
    $forceReply = array(
      'force_reply' => true
    );
    $feedbackMessageId = sendMessage($chatId, "Ich erstelle die Umfrage <i>$text</i>.", '', json_encode($forceReply))['message_id'];
    createPoll($senderUserId, $messageId, $feedbackMessageId, $text);
  }

  $command = strtolower($command);

  switch ($command) {
    case '/start':
      sendMessage($chatId, '<b>Hi</b>
Ich bin der Stammtisch Bot. Durch mich kannst du Registrationen für Meetups oder Stammtische erstellen.
Schreibe mir einfach den Titel deiner Registration, dann können wir los legen.');
      break;
  }
}

if (isset($text) && isset($repliedToMessageId)) {

}