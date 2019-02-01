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

function sendChatAction($chatId, $action) {
  $actionList = array(
    "typing",
    "upload_photo",
    "record_video",
    "upload_video",
    "record_audio",
    "upload_audio",
    "upload_document",
    "find_location",
    "record_video_note",
    "upload_video_note"
  );
  if (in_array($action, $actionList)) {
    global $config;
    $response = file_get_contents($config['url'] . "sendChatAction?chat_id=$chatId&action=$action");
    /*$user = json_decode($response, true)['result']['user'];
    return $user;*/
  }
}

function createPoll($userId, $userMessageId, $feedbackMessageId, $title) {
  global $dbConnection, $config;

  try {
    $sql = "INSERT INTO polls(user_id, user_message_id, feedback_message_id, title) VALUES ('$userId', '$userMessageId', '$feedbackMessageId', $title)";
    $stmt = $dbConnection->prepare('INSERT INTO polls(user_id, user_message_id, feedback_message_id, title) VALUES (:userId, :userMessageId, :feedbackMessageId, :title)');
    $stmt->bindParam(':userId', $userId);
    $stmt->bindParam(':userMessageId', $userMessageId);
    $stmt->bindParam(':feedbackMessageId', $feedbackMessageId);
    $stmt->bindParam(':title', $title);
    $stmt->execute();
  } catch (PDOException $e) {
    notifyOnException('Database Insert', $config, $sql, $e);
  }
}

function getPoll($userId, $feedbackMessageId) {
  global $dbConnection, $config;

  try {
    $sql = "SELECT id, status, title FROM polls WHERE user_id = $userId AND feedback_message_id = $feedbackMessageId";
    $stmt = $dbConnection->prepare('SELECT id, status, title FROM polls WHERE user_id = :userId AND feedback_message_id = :feedbackMessageId');
    $stmt->bindParam(':userId', $userId);
    $stmt->bindParam(':feedbackMessageId', $feedbackMessageId);
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
      return $stmt->fetch();
    }
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  return [
    false,
    false
  ];
}

function answerInlineQuery($inlineQueryId, $results) {
  global $config;

  mail($config['mail'], 'Test', $config['url'] . "answerInlineQuery?inline_query_id=$inlineQueryId&results=$results&is_personal=true");
  $response = file_get_contents($config['url'] . "answerInlineQuery?inline_query_id=$inlineQueryId&results=$results&is_personal=true");
  //Might use http_build_query in the future
}

function getAllPolls($userId, $search = '') {
  global $dbConnection, $config;
  if (empty($search)) {
    try {
      //$sql = "SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = $userId GROUP BY attendees.poll_id";
      //$stmt = $dbConnection->prepare('SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = :userId GROUP BY attendees.poll_id');
      $sql = "SELECT id, title, text FROM polls WHERE user_id = $userId AND status = 1";
      $stmt = $dbConnection->prepare('SELECT id, title, text FROM polls WHERE user_id = :userId AND status = 1');
      $stmt->bindParam(':userId', $userId);
      $stmt->execute();
      return $stmt->fetchAll();
    } catch (PDOException $e) {
      notifyOnException('Database Select', $config, $sql, $e);
    }
    return false;
  } else {
    $search = '%'.$search.'%';
    try {
      //$sql = "SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = $userId GROUP BY attendees.poll_id";
      //$stmt = $dbConnection->prepare('SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = :userId GROUP BY attendees.poll_id');
      $sql = "SELECT id, title, text FROM polls WHERE user_id = $userId AND status = 1 AND title LIKE '$search'";
      $stmt = $dbConnection->prepare('SELECT id, title, text FROM polls WHERE user_id = :userId AND status = 1 AND title LIKE :search');
      $stmt->bindParam(':userId', $userId);
      $stmt->bindParam(':search', $search);
      $stmt->execute();
      return $stmt->fetchAll();
    } catch (PDOException $e) {
      notifyOnException('Database Select', $config, $sql, $e);
    }
    return false;
  }
}

function getPollAttendees($pollId){
  global $dbConnection, $config;

  try {
    //$sql = "SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = $userId GROUP BY attendees.poll_id";
    //$stmt = $dbConnection->prepare('SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = :userId GROUP BY attendees.poll_id');
    $sql = "SELECT (SELECT count(user_id) FROM attendees WHERE poll_id = $pollId AND status = 1) as yes, (SELECT count(user_id) FROM attendees WHERE poll_id = $pollId AND status = 2) as maybe, (SELECT count(user_id) FROM attendees WHERE poll_id = $pollId AND status = 3) as no";
    $stmt = $dbConnection->prepare('SELECT (SELECT count(user_id) FROM attendees WHERE poll_id = :pollId AND status = 1) as yes, (SELECT count(user_id) FROM attendees WHERE poll_id = :pollId2 AND status = 2) as maybe, (SELECT count(user_id) FROM attendees WHERE poll_id = :pollId3 AND status = 3) as no');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->bindParam(':pollId2', $pollId);
    $stmt->bindParam(':pollId3', $pollId);
    $stmt->execute();
    return $stmt->fetch();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  return false;
}

function buildPollAttendees($pollId, $yes, $maybe, $no){
  global $dbConnection, $config;
  $return = "

<b>Anmeldung - [$yes]</b>
";

  try {
    //$sql = "SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = $userId GROUP BY attendees.poll_id";
    //$stmt = $dbConnection->prepare('SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = :userId GROUP BY attendees.poll_id');
    $sql = "SELECT nickname FROM attendees WHERE poll_id = $pollId AND status = 1";
    $stmt = $dbConnection->prepare('SELECT nickname FROM attendees WHERE poll_id = :pollId AND status = 1');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as $row){
      $return .= $row['nickname'] . '
';
    }

    $return .= "
<b>Vielleicht - [$maybe]</b>
";

    $sql = "SELECT nickname FROM attendees WHERE poll_id = $pollId AND status = 2";
    $stmt = $dbConnection->prepare('SELECT nickname FROM attendees WHERE poll_id = :pollId AND status = 2');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as $row){
      $return .= $row['nickname'] . '
';
    }

    $return .= "
<b>Abmeldung - [$no]</b>
";

    $sql = "SELECT nickname FROM attendees WHERE poll_id = $pollId AND status = 3";
    $stmt = $dbConnection->prepare('SELECT nickname FROM attendees WHERE poll_id = :pollId AND status = 3');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as $row){
      $return .= $row['nickname'] . '
';
    }
    return $return;
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  return false;
}

function setPollContent($userId, $feedbackMessageId, $text){
  global $dbConnection, $config;

  try {
    $sql = "UPDATE polls SET text = $text WHERE user_id = $userId AND feedback_message_id = $feedbackMessageId";
    $stmt = $dbConnection->prepare('UPDATE polls SET text = :text, status = 1 WHERE user_id = :userId AND feedback_message_id = :feedbackMessageId');
    $stmt->bindParam(':text', $text);
    $stmt->bindParam(':userId', $userId);
    $stmt->bindParam(':feedbackMessageId', $feedbackMessageId);
    $stmt->execute();
    return true;
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  return false;
}