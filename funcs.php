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
  if (mb_strlen($text) > 4096) {
    sendMessage($chatId, substr($text, 0, 4096), $replyTo, $replyMarkup);
    return sendMessage($chatId, substr($text, 4096), $replyTo, $replyMarkup);
  } else {
    $data = array(
      'disable_web_page_preview' => true,
      'parse_mode' => 'html',
      'chat_id' => $chatId,
      'text' => $text,
      'reply_to_message_id' => $replyTo,
      'reply_markup' => $replyMarkup
    );
    return makeApiRequest('sendMessage', $data);
  }
}

function answerCallbackQuery($queryId, $text = '') {
  $data = array(
    'callback_query_id' => $queryId,
    'text' => $text
  );
  return makeApiRequest('answerCallbackQuery', $data);
}

function makeApiRequest($method, $data) {
  global $config, $client;
  if (!($client instanceof \GuzzleHttp\Client)) {
    $client = new \GuzzleHttp\Client(['base_uri' => $config['url']]);
  }
  try {
    $response = $client->request('POST', $method, array('json' => $data));
  } catch (\GuzzleHttp\Exception\BadResponseException $e) {
    $body = $e->getResponse()->getBody();
    mail($config['mail'], 'Error', print_r($body->getContents(), true) . "\n" . print_r($data, true) . "\n" . __FILE__);
    return false;
  }
  return json_decode($response->getBody(), true)['result'];
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
    $data = array(
      'chat_id' => $chatId,
      'action' => $action
    );
    return makeApiRequest('sendChatAction', $data);
  }
}

function createPoll($userId, $userName, $userMessageId, $feedbackMessageId, $title) {
  global $dbConnection, $config;

  try {
    $sql = "INSERT INTO polls(user_id, user_name, user_message_id, feedback_message_id, title) VALUES ('$userId', '$userName', '$userMessageId', '$feedbackMessageId', $title)";
    $stmt = $dbConnection->prepare('INSERT INTO polls(user_id, user_name, user_message_id, feedback_message_id, title) VALUES (:userId, :userName, :userMessageId, :feedbackMessageId, :title)');
    $stmt->bindParam(':userId', $userId);
    $stmt->bindParam(':userName', $userName);
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
      $sql = "SELECT id, status, title, text, max FROM polls WHERE user_id = $userId AND feedback_message_id = $feedbackMessageId";
      $stmt = $dbConnection->prepare('SELECT id, status, title, text, max FROM polls WHERE user_id = :userId AND feedback_message_id = :feedbackMessageId');
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
      false,
      false,
      false
    ];
  } else {
    try {
      $sql = "SELECT id, status, title, text, max FROM polls INNER JOIN messages m on polls.id = m.poll_id WHERE m.inline_message_id = $inlineQueryMessageId";
      $stmt = $dbConnection->prepare('SELECT id, status, title, text, max FROM polls INNER JOIN messages m on polls.id = m.poll_id WHERE m.inline_message_id = :inlineQueryMessageId');
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
      false,
      false,
      false
    ];
  }
}

function answerInlineQuery($inlineQueryId, $results, $offset) {
  $data = array(
    'inline_query_id' => $inlineQueryId,
    'results' => $results,
    'cache_time' => 10,
    'is_personal' => true,
    'next_offset' => $offset + 50
  );
  return makeApiRequest('answerInlineQuery', $data);
}

function getAllPolls($userId, $search = '', $offset = 0) {
  global $dbConnection, $config;
  if (empty($search)) {
    try {
      //$sql = "SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = $userId GROUP BY attendees.poll_id";
      //$stmt = $dbConnection->prepare('SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = :userId GROUP BY attendees.poll_id');
      $sql = "SELECT id, title, text FROM polls WHERE user_id = $userId AND status = 1 ORDER BY id DESC LIMIT $offset, 50";
      $stmt = $dbConnection->prepare('SELECT id, title, text FROM polls WHERE user_id = :userId AND status = 1 ORDER BY id DESC LIMIT ' . $offset . ', 50');
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
      $sql = "SELECT id, title, text FROM polls WHERE user_id = $userId AND status = 1 AND title LIKE $search ORDER BY id DESC LIMIT $offset, 50";
      $stmt = $dbConnection->prepare('SELECT id, title, text FROM polls WHERE user_id = :userId AND status = 1 AND title LIKE :search ORDER BY id DESC LIMIT ' . $offset . ', 50');
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
    $sql = "SELECT 
        sum(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS yes,
        sum(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS maybe,
        /*sum(CASE WHEN status = 3 THEN 1 ELSE 0 END) AS no*/
        0 AS no
        FROM attendees WHERE poll_id = $pollId";
    $stmt = $dbConnection->prepare('SELECT 
        sum(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS yes,
        sum(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS maybe,
        /*sum(CASE WHEN status = 3 THEN 1 ELSE 0 END) AS no*/
        0 AS no
        FROM attendees WHERE poll_id = :pollId');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->execute();
    return $stmt->fetch();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  return false;
}

function updateMax($pollId, $max) {
    global $dbConnection, $config;

    try {
        $sql = 'UPDATE polls SET max = :max WHERE id = :pollId';
        $stmt = $dbConnection->prepare($sql);
        $stmt->bindParam(':max', $max);
        $stmt->bindParam(':pollId', $pollId);
        $stmt->execute();
    } catch (PDOException $e) {
        notifyOnException('Database Select', $config, $sql, $e);
    }
}

function buildPollAttendees($pollId, $yes, $maybe, $no, $link = false) {
  global $dbConnection, $config;
  $return = "

<b>Anmeldung - [$yes]</b>
";

  /*try {
    //$sql = "SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = $userId GROUP BY attendees.poll_id";
    //$stmt = $dbConnection->prepare('SELECT polls.id, title, text, count(attendees.user_id) as attendees FROM polls INNER JOIN attendees ON attendees.poll_id = polls.id WHERE polls.user_id = :userId GROUP BY attendees.poll_id');
    $status = 1;
    $sql = "SELECT user_id, nickname FROM attendees WHERE poll_id = $pollId AND status = $status ORDER BY time";
    $stmt = $dbConnection->prepare('SELECT user_id, nickname FROM attendees WHERE poll_id = :pollId AND status = :status ORDER BY time');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->bindParam(':status', $status);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if ($link) {
      foreach ($rows as $row) {
        $return .= '<a href="tg://user?id=' . $row['user_id'] . '">' . $row['nickname'] . '</a>
';
      }
    } else {
      foreach ($rows as $row) {
        $return .= $row['nickname'] . '
';
      }
    }

    $return .= "
<b>Vielleicht - [$maybe]</b>
";
    $status = 2;
    $stmt->bindParam(':status', $status);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if ($link) {
      foreach ($rows as $row) {
        $return .= '<a href="tg://user?id=' . $row['user_id'] . '">' . $row['nickname'] . '</a>
';
      }
    } else {
      foreach ($rows as $row) {
        $return .= $row['nickname'] . '
';
      }
    }

    $return .= "
<b>Abmeldung - [$no]</b>
";

    $status = 3;
    $stmt->bindParam(':status', $status);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if ($link) {
      foreach ($rows as $row) {
        $return .= '<a href="tg://user?id=' . $row['user_id'] . '">' . $row['nickname'] . '</a>
';
      }
    } else {
      foreach ($rows as $row) {
        $return .= $row['nickname'] . '
';
      }
    }
    return $return;
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }*/


  try {
    $sql = "SELECT user_id, nickname, status FROM attendees WHERE poll_id = $pollId AND (status = 1 OR status = 2) ORDER BY status, time";
    $stmt = $dbConnection->prepare('SELECT user_id, nickname, status FROM attendees WHERE poll_id = :pollId AND (status = 1 OR status = 2) ORDER BY status, time');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->execute();
    $rows = $stmt->fetchAll();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
    return false;
  }
  $lastStatus = 1;
  $return = "

<b>Anmeldung - [$yes]</b>
";
  /*
   * Keep links if character limit not met
   */
  if ($link) {
    foreach ($rows as $row) {
      if ($lastStatus != $row['status']) {
        if ($row['status'] === 2) {
          $return .= "
<b>Vielleicht - [$maybe]</b>
";
        }
        if ($row['status'] === 3) {
          $return .= "
<b>Abmeldung - [$no]</b>
";
        }
        $lastStatus = $row['status'];
      }
      $return .= '<a href="tg://user?id=' . $row['user_id'] . '">' . htmlspecialchars($row['nickname']) . '</a>
';
    }
  } /*
   * If character limit is reached, disable links
   */ else {
    foreach ($rows as $row) {
      if ($lastStatus != $row['status']) {
        if ($row['status'] === 2) {
          $return .= "
<b>Vielleicht - [$maybe]</b>
";
        }
        if ($row['status'] === 3) {
          $return .= "
<b>Abmeldung - [$no]</b>
";
        }
        $lastStatus = $row['status'];
      }
      $return .= htmlspecialchars($row['nickname']) . '
';
    }
  }
  /*
   *
   */
  return $return;
}

function setPollContent($userId, $feedbackMessageId, $text) {
  global $dbConnection, $config;

  try {
    $sql = "UPDATE polls SET text = $text, status = 1 WHERE user_id = $userId AND feedback_message_id = $feedbackMessageId";
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

function setPollNewContent($userId, $feedbackMessageId, $text) {
  global $dbConnection, $config;

  try {
    $sql = "UPDATE polls SET text_new = $text WHERE user_id = $userId AND feedback_message_id = $feedbackMessageId";
    $stmt = $dbConnection->prepare('UPDATE polls SET text_new = :text WHERE user_id = :userId AND feedback_message_id = :feedbackMessageId');
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
    $sql = "UPDATE polls SET status = 2 WHERE id = $pollId";
    $stmt = $dbConnection->prepare('UPDATE polls SET status = 2 WHERE id = :pollId');
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
    $sql = "SELECT id, status FROM attendees WHERE poll_id = $pollId AND user_id = $userId";
    $stmt = $dbConnection->prepare('SELECT id, status FROM attendees WHERE poll_id = :pollId AND user_id = :userId');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->bindParam(':userId', $userId);
    $stmt->execute();
    $row = $stmt->fetch();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  if ($stmt->rowCount() > 0) {
    //Update
    if ($row['status'] != $status) {
      try {
        $sql = "UPDATE attendees SET status = $status, nickname = $nickname, time = UNIX_TIMESTAMP() WHERE poll_id = $pollId AND user_id = $userId";
        $stmt = $dbConnection->prepare('UPDATE attendees SET status = :status, nickname = :nickname, time = UNIX_TIMESTAMP() WHERE poll_id = :pollId AND user_id = :userId');
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':nickname', $nickname);
        $stmt->bindParam(':pollId', $pollId);
        $stmt->bindParam(':userId', $userId);
        $stmt->execute();
      } catch (PDOException $e) {
        notifyOnException('Database Update', $config, $sql, $e);
      }
      return true;
    }
  } else {
    //Insert
    try {
      $sql = "INSERT INTO attendees(poll_id, user_id, nickname, status, time) VALUES ($pollId, $userId, $nickname, $status, UNIX_TIMESTAMP())";
      $stmt = $dbConnection->prepare('INSERT INTO attendees(poll_id, user_id, nickname, status, time) VALUES (:pollId, :userId, :nickname, :status, UNIX_TIMESTAMP())');
      $stmt->bindParam(':pollId', $pollId);
      $stmt->bindParam(':userId', $userId);
      $stmt->bindParam(':nickname', $nickname);
      $stmt->bindParam(':status', $status);
      $stmt->execute();
    } catch (PDOException $e) {
      notifyOnException('Database Insert', $config, $sql, $e);
    }
    return ($status != 3) ? true : false;
  }
  return false;
}

function updatePoll($pollId, $close = false) {
  global $dbConnection, $config;

  try {
    $sql = "SELECT inline_message_id, text, title FROM messages INNER JOIN polls p on messages.poll_id = p.id WHERE poll_id = $pollId";
    $stmt = $dbConnection->prepare('SELECT inline_message_id, text, title FROM messages INNER JOIN polls p on messages.poll_id = p.id WHERE poll_id = :pollId');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->execute();
    $rows = $stmt->fetchAll();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  foreach ($rows as $row) {
    $pollText = $row['text'];
    $pollTitle = $row['title'];
    $pollInlineMessageId = $row['inline_message_id'];
    list($attendeesYes, $attendeesMaybe, $attendeesNo) = getPollAttendees($pollId);
    $text = $pollText . buildPollAttendees($pollId, $attendeesYes, $attendeesMaybe, $attendeesNo, true);
    /*if(mb_strlen($text) > 4000){
      $text = "<a href=\"https://t.me/gaestebuch_bot?start=$pollInlineMessageId\">$pollTitle</a>" . buildPollAttendees($pollId, $attendeesYes, $attendeesMaybe, $attendeesNo, true);
      if(mb_strlen($text) > 4000){
        $text = "<a href=\"https://t.me/gaestebuch_bot?start=$pollInlineMessageId\">$pollTitle</a>" . buildPollAttendees($pollId, $attendeesYes, $attendeesMaybe, $attendeesNo);
        if(mb_strlen($text) > 4000){
          $text = $pollText;
        }
      }
    }*/
    if (mb_strlen($text) > 4000) {
      $text = $pollText . buildPollAttendees($pollId, $attendeesYes, $attendeesMaybe, $attendeesNo);
      if (mb_strlen($text) > 4000) {
        $text = $pollText;
      }
    }
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
              /*'text' => 'Abmeldung - ' . $attendeesNo,*/
              'text' => 'Abmeldung',
              'callback_data' => 'vote|0|3|0'
            )
          )
        )
      );
    } else {
      $replyMarkup = '';
    }

    $edited = editMessageText('', '', $text, $replyMarkup, $row['inline_message_id']);
    if ($edited === false) {
      //Too many false positives, gotta think about something else
      //deletePollMessage($row['inline_message_id'], $pollId);
    }
  }
}

function deletePollMessage($inlineMessageId, $pollId) {
  global $dbConnection, $config;

  try {
    $sql = "INSERT INTO messagesDEL(inline_message_id, poll_id) VALUES ($inlineMessageId, $pollId)";
    $stmt = $dbConnection->prepare('INSERT INTO messagesDEL(inline_message_id, poll_id) VALUES (:inlineMessageId, :pollId)');
    $stmt->bindParam(':inlineMessageId', $inlineMessageId);
    $stmt->bindParam(':pollId', $pollId);
    $stmt->execute();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }

  try {
    $sql = "DELETE FROM messages WHERE inline_message_id = $inlineMessageId";
    $stmt = $dbConnection->prepare('DELETE FROM messages WHERE inline_message_id = :inlineMessageId');
    $stmt->bindParam(':inlineMessageId', $inlineMessageId);
    $stmt->execute();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
}

function updatePollText($pollId) {
  global $dbConnection, $config;

  try {
    $sql = "UPDATE polls SET text = text_new, text_new = NULL WHERE id = $pollId AND text_new IS NOT NULL";
    $stmt = $dbConnection->prepare('UPDATE polls SET text = text_new, text_new = NULL WHERE id = :pollId AND text_new IS NOT NULL');
    $stmt->bindParam(':pollId', $pollId);
    $stmt->execute();
  } catch (PDOException $e) {
    notifyOnException('Database Update', $config, $sql, $e);
    return false;
  }
  return true;
}

function editMessageText($chatId, $messageId, $text, $replyMarkup = '', $inlineMessageId = '') {
  if (empty($inlineMessageId)) {
    $data = array(
      'chat_id' => $chatId,
      'message_id' => $messageId,
      'text' => $text,
      'parse_mode' => 'html',
      'disable_web_page_preview' => true,
      'reply_markup' => $replyMarkup
    );
  } else {
    $data = array(
      'inline_message_id' => $inlineMessageId,
      'text' => $text,
      'parse_mode' => 'html',
      'disable_web_page_preview' => true,
      'reply_markup' => $replyMarkup
    );
  }
  return makeApiRequest('editMessageText', $data);
}

function checkLastExecute($timeouts, $command, $type, $id) {
  if ($type === 'private') {
    return $timeouts;
  }
  global $config;
  $now = time();
  if (isset($timeouts[$id])) {
    $lastExecute = $timeouts[$id][$command];
    if ($now < $lastExecute + $config['commandInterval']) {
      return false;
    }
  }
  $timeouts[$id][$command] = $now;
  return $timeouts;
}

function mb_substr_replace($original, $replacement, $position, $length) {
  $startString = mb_substr($original, 0, $position, "UTF-8");
  $endString = mb_substr($original, $position + $length, mb_strlen($original), "UTF-8");

  $out = $startString . $replacement . $endString;

  return $out;
}

function filterSymbols($input, $clear = false) {
  if ($clear) {
    $input = str_replace(['<', '>'], '', $input);
  } else {
    $input = str_replace('<', '&lt;', $input);
    $input = str_replace('>', '&gt;', $input);
  }
  return $input;
}