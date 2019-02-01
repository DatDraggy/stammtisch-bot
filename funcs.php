<?php
function buildDatabaseConnection($config) {
  //Connect to DB only here to save response time on other commands
  try {
    $dbConnection = new PDO('mysql:dbname=' . $config['dbname'] . ';host=' . $config['dbserver'] . ';charset=utf8mb4', $config['dbuser'], $config['dbpassword'], array(PDO::ATTR_TIMEOUT => 25));
    $dbConnection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $dbConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  } catch (PDOException $e) {
    notifyOnException('Database Connection', $config, '', $e);
  }
  return $dbConnection;
}

function notifyOnException($subject, $config, $sql = '', $e = '') {
  global $chatId;
  sendMessage(175933892, 'Bruv, sometin in da database is ded, innit? Check it out G. ' . $e);
  $to = $config['mail'];
  $txt = __FILE__ . ' ' . $sql . ' Error: ' . $e;
  $headers = 'From: ' . $config['mail'];
  mail($to, $subject, $txt, $headers);
  http_response_code(200);
  die();
}

function sendMessage($chatId, $text, $replyTo = '', $replyMarkup = '') {
  global $config;
  $response = file_get_contents($config['url'] . "sendMessage?disable_web_page_preview=true&parse_mode=html&chat_id=$chatId&text=" . urlencode($text) . "&reply_to_message_id=$replyTo&reply_markup=$replyMarkup");
  //Might use http_build_query in the future
  return json_decode($response, true)['result'];
}

function answerCallbackQuery($queryId, $text = '') {
  global $config;
  $response = file_get_contents($config['url'] . "answerCallbackQuery?callback_query_id=$queryId&text=" . urlencode($text));
  //Might use http_build_query in the future
  return json_decode($response, true)['result'];
}

function sendChatAction($chatId, $action){
  $actionList = array("typing", "upload_photo","record_video","upload_video","record_audio","upload_audio","upload_document","find_location","record_video_note","upload_video_note");
  if(in_array($action, $actionList)) {
    global $config;
    $response = file_get_contents($config['url'] . "sendChatAction?chat_id=$chatId&action=$action");
    /*$user = json_decode($response, true)['result']['user'];
    return $user;*/
  }
}

function createPoll($userId, $userMessageId, $feedbackMessageId, $text){
  global $dbConnection, $config;

  try {
    $sql = '';
    $stmt = $dbConnection->prepare('');
    $stmt->bindParam();

  }catch (PDOException $e){
    notifyOnException('Database Insert', $config, $sql, $e);
  }
}