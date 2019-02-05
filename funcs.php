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

function getPoll($userId, $feedbackMessageId, $inlineQueryMessageId = '') {
  global $dbConnection, $config;

  if (empty($inlineQueryMessageId)) {
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
      false,
      false
    ];
  } else {
    try {
      $sql = "SELECT id, status, title FROM polls INNER JOIN messages m on polls.id = m.poll_id WHERE m.inline_message_id = $inlineQueryMessageId";
      $stmt = $dbConnection->prepare('SELECT id, status, title FROM polls INNER JOIN messages m on polls.id = m.poll_id WHERE m.inline_message_id = :inlineQueryMessageId');
      $stmt->bindParam(':inlineQueryMessageId', $inlineQueryMessageId);
      $stmt->execute();
      if ($stmt->rowCount() > 0) {
        return $stmt->fetch();
      }
    } catch (PDOException $e) {
      notifyOnException('Database Select', $config, $sql, $e);
    }
    return [
      false,
      false,
      false
    ];
  }
}

function answerInlineQuery($inlineQueryId, $results) {
  global $config;
  //$response = file_get_contents($config['url'] . "answerInlineQuery?inline_query_id=$inlineQueryId&results=$results&is_personal=true");
  $url = $config['url'] . "answerInlineQuery";

  $data = array(
    'inline_query_id' => $inlineQueryId,
    'results' => $results,
    'is_personal' => true
  );
  // use key 'http' even if you send the request to https://...
  $options = array(
    'http' => array(
      'header' => "Content-type: application/json\r\n",
      'method' => 'POST',
      'content' => json_encode($data)
    )
  );
  $context = stream_context_create($options);
  $result = file_get_contents($url, false, $context);
}

function getAllPolls($userId, $search = '') {
  global $dbConnection, $config;
  if (empty($search)) {
    try {
      //$sql = "SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = $userId GROUP BY attendees.poll_id";
      //$stmt = $dbConnection->prepare('SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = :userId GROUP BY attendees.poll_id');
      $sql = "SELECT id, title, text FROM polls WHERE user_id = $userId AND status = 1";
      $stmt = $dbConnection->prepare('SELECT id, title, text FROM polls WHERE user_id = :userId AND status = 1 ORDER BY id DESC LIMIT 50');
      $stmt->bindParam(':userId', $userId);
      $stmt->execute();
      return $stmt->fetchAll();
    } catch (PDOException $e) {
      notifyOnException('Database Select', $config, $sql, $e);
    }
    return false;
  } else {
    $search = '%' . $search . '%';
    try {
      //$sql = "SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = $userId GROUP BY attendees.poll_id";
      //$stmt = $dbConnection->prepare('SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = :userId GROUP BY attendees.poll_id');
      $sql = "SELECT id, title, text FROM polls WHERE user_id = $userId AND status = 1 AND title LIKE '$search'";
      $stmt = $dbConnection->prepare('SELECT id, title, text FROM polls WHERE user_id = :userId AND status = 1 AND title LIKE :search ORDER BY id DESC LIMIT 50');
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

function getPollAttendees($pollId) {
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

function buildPollAttendees($pollId, $yes, $maybe, $no) {
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
    foreach ($rows as $row) {
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
    foreach ($rows as $row) {
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
    foreach ($rows as $row) {
      $return .= $row['nickname'] . '
';
    }
    return $return;
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  return false;
}

function setPollContent($userId, $feedbackMessageId, $text) {
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

function newPollPost($inlineQueryMessageId, $pollId) {
  global $dbConnection, $config;

  try {
    $sql = "INSERT INTO messages(inline_message_id, poll_id) VALUES ('$inlineQueryMessageId', '$pollId')";
    $stmt = $dbConnection->prepare('INSERT INTO messages(inline_message_id, poll_id) VALUES (:inlineQueryMessageId, :pollId)');
    $stmt->bindParam(':inlineQueryMessageId', $inlineQueryMessageId);
    $stmt->bindParam(':pollId', $pollId);
    $stmt->execute();
    return true;
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  return false;
}

function closePoll($pollId) {
  global $dbConnection, $config;

  try {
    $sql = "UPDATE polls SET status = 0 WHERE id = $pollId";
    $stmt = $dbConnection->prepare('UPDATE polls SET status = 0 WHERE id = :pollId');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->execute();
    return true;
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  return false;
}

function setAttendanceStatus($pollId, $userId, $nickname, $status) {
  global $dbConnection, $config;

  try {
    $sql = "SELECT id FROM attendees WHERE poll_id = $pollId AND user_id = $userId";
    $stmt = $dbConnection->prepare('SELECT id FROM attendees WHERE poll_id = :pollId AND user_id = :userId');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->bindParam(':userId', $userId);
    $stmt->execute();
    $stmt->fetch();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  if ($stmt->rowCount() > 0) {
    //Update
    try {
      $sql = "UPDATE attendees SET status = $status, nickname = $nickname WHERE poll_id = $pollId AND user_id = $userId";
      $stmt = $dbConnection->prepare('UPDATE attendees SET status = :status, nickname = :nickname WHERE poll_id = :pollId AND user_id = :userId');
      $stmt->bindParam(':status', $status);
      $stmt->bindParam(':nickname', $nickname);
      $stmt->bindParam(':pollId', $pollId);
      $stmt->bindParam(':userId', $userId);
      $stmt->execute();
    } catch (PDOException $e) {
      notifyOnException('Database Update', $config, $sql, $e);
    }
  } else {
    //Insert
    try {
      $sql = "INSERT INTO attendees(poll_id, user_id, nickname, status) VALUES ($pollId, $userId, $nickname, $status)";
      $stmt = $dbConnection->prepare('INSERT INTO attendees(poll_id, user_id, nickname, status) VALUES (:pollId, :userId, :nickname, :status)');
      $stmt->bindParam(':pollId', $pollId);
      $stmt->bindParam(':userId', $userId);
      $stmt->bindParam(':nickname', $nickname);
      $stmt->bindParam(':status', $status);
      $stmt->execute();
    } catch (PDOException $e) {
      notifyOnException('Database Insert', $config, $sql, $e);
    }
  }
}

function updatePoll($pollId, $close = false) {
  global $dbConnection, $config;

  try {
    $sql = "SELECT inline_message_id, text FROM messages INNER JOIN polls p on messages.poll_id = p.id WHERE poll_id = $pollId";
    $stmt = $dbConnection->prepare('SELECT inline_message_id, text FROM messages INNER JOIN polls p on messages.poll_id = p.id WHERE poll_id = :pollId');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->execute();
    $rows = $stmt->fetchAll();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  foreach ($rows as $row) {
    $pollText = $row['text'];
    list($attendeesYes, $attendeesMaybe, $attendeesNo) = getPollAttendees($pollId);
    $text = $pollText . buildPollAttendees($pollId, $attendeesYes, $attendeesMaybe, $attendeesNo);
    if (!$close) {
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
              'text' => 'Abmeldung - ' . $attendeesNo,
              'callback_data' => 'vote|0|3|0'
            )
          )
        )
      );
    } else {
      $replyMarkup = '';
    }

    editMessageText($row['inline_message_id'], $text, $replyMarkup);
  }
}

function editMessageText($inlineMessageId, $text, $replyMarkup) {
  global $config;
  //$response = file_get_contents($config['url'] . "answerInlineQuery?inline_query_id=$inlineQueryId&results=$results&is_personal=true");
  $url = $config['url'] . "editMessageText";

  $data = array(
    'inline_message_id' => $inlineMessageId,
    'text' => $text,
    'parse_mode' => 'html',
    'disable_web_page_preview' => true,
    'reply_markup' => $replyMarkup
  );
  // use key 'http' even if you send the request to https://...
  $options = array(
    'http' => array(
      'header' => "Content-type: application/json\r\n",
      'method' => 'POST',
      'content' => json_encode($data)
    )
  );
  $context = stream_context_create($options);
  $result = file_get_contents($url, false, $context);
}