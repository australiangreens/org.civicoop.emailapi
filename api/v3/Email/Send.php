<?php

/**
 * Email.Send API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
function _civicrm_api3_email_send_spec(&$spec) {
  $spec['contact_id'] = [
    'title' => 'Contact ID',
    'api.required' => 1,
  ];
  $spec['template_id'] = [
    'title' => 'Template ID',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 1,
  ];
  $spec['case_id'] = [
    'title' => 'Case ID',
    'type' => CRM_Utils_Type::T_INT,
  ];
  $spec['contribution_id'] = [
    'title' => 'Contribution ID',
    'type' => CRM_Utils_Type::T_INT,
  ];
  $spec['alternative_receiver_address'] = [
    'title' => 'Alternative receiver address',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['cc'] = [
    'title' => 'Cc',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['bcc'] = [
    'title' => 'Bcc',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['subject'] = [
    'title' => 'Subject',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['extra_data'] = [
    'title' => 'Extra data',
    'type' => CRM_Utils_Type::T_TEXT,
  ];
}

/**
 * Email.Send API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_email_send($params) {
  if (!preg_match('/[0-9]+(,[0-9]+)*/i', $params['contact_id'])) {
    throw new API_Exception('Parameter contact_id must be a unique id or a list of ids separated by comma');
  }
  $contactIds = explode(",", $params['contact_id']);
  $alternativeEmailAddress = !empty($params['alternative_receiver_address']) ? $params['alternative_receiver_address'] : false;

  $case_id = false;
  if (isset($params['case_id'])) {
    $case_id = $params['case_id'];
  }
  $contribution_id = false;
  if (isset($params['contribution_id'])) {
    $contribution_id = $params['contribution_id'];
  }
  elseif (!empty($params['extra_data']['contribution'])) {
    $contribution_id = $params['extra_data']['contribution']['contribution_id'];
  }
  $extra_data = false;
  if (isset($params['extra_data'])) {
    $extra_data = $params['extra_data'];
  }

  $messageTemplates = new CRM_Core_DAO_MessageTemplate();
  $messageTemplates->id = $params['template_id'];

  $from = CRM_Core_BAO_Domain::getNameAndEmail();
  $from = "$from[0] <$from[1]>";
  if (isset($params['from_email']) && isset($params['from_name'])) {
    $from = $params['from_name']."<".$params['from_email'].">";
  } elseif (isset($params['from_email']) || isset($params['from_name'])) {
    throw new API_Exception('You have to provide both from_name and from_email');
  }

  $domain     = CRM_Core_BAO_Domain::getDomain();
  $result     = NULL;

  if (!$messageTemplates->find(TRUE)) {
    throw new API_Exception('Could not find template with ID: '.$params['template_id']);
  }

  $body_text    = $messageTemplates->msg_text;
  $body_html    = $messageTemplates->msg_html;
  if (isset($params['subject']) && !empty($params['subject'])) {
    $messageSubject = $params['subject'];
  }
  else {
    $messageSubject = $messageTemplates->msg_subject;
  }
  if (!$body_text) {
    $body_text = CRM_Utils_String::htmlToText($body_html);
  }

  $returnValues = [];
  foreach($contactIds as $contactId) {
    $contact_params = [['contact_id', '=', $contactId, 0, 0]];
    list($contact, $_) = CRM_Contact_BAO_Query::apiQuery($contact_params);

    //CRM-4524
    $contact = reset($contact);

    if (!$contact || is_a($contact, 'CRM_Core_Error')) {
      throw new API_Exception('Could not find contact with ID: ' . $contact_params['contact_id']);
    }

    //CRM-5734

    // get tokens to be replaced
    $tokens = array_merge_recursive(CRM_Utils_Token::getTokens($body_text),
      CRM_Utils_Token::getTokens($body_html),
      CRM_Utils_Token::getTokens($messageSubject));

    if ($case_id) {
      $contact['case.id'] = $case_id;
    }
    if ($contribution_id) {
      $contact['contribution_id'] = $contribution_id;
    }
    if ($extra_data) {
      $contact['extra_data'] = $extra_data;
    }

    if ($alternativeEmailAddress) {
      /**
       * If an alternative reciepient address is given
       * then send e-mail to that address rather than to
       * the e-mail address of the contact
       *
       */
      $toName = '';
      $toEmail = $alternativeEmailAddress;
    } elseif ($contact['do_not_email'] || empty($contact['email']) || CRM_Utils_Array::value('is_deceased', $contact) || $contact['on_hold']) {
      /**
       * Contact is decaused or has opted out from mailings so do not send the e-mail
       */
      continue;
    } else {
      /**
       * Send e-mail to the contact
       */
      $toEmail = $contact['email'];
      $toName = $contact['display_name'];
    }

    // Change the contact array to the format the hook expects.
    $contactHookArray[$contact['contact_id']] = $contact;
    CRM_Utils_Hook::tokenValues($contactHookArray, array_keys($contactHookArray), NULL, $tokens);
    // Now update the original array.
    $contact = $contactHookArray[$contact['contact_id']];
    // call token hook
    $hookTokens = [];
    CRM_Utils_Hook::tokens($hookTokens);
    $categories = array_keys($hookTokens);

    // do replacements in text and html body
    $type = ['html', 'text'];
    foreach ($type as $key => $value) {
      $bodyType = "body_{$value}";
      if ($$bodyType) {
        if ($contribution_id) {
          try {
            $contribution = civicrm_api3('Contribution', 'getsingle', ['id' => $contribution_id]);
            $$bodyType = CRM_Utils_Token::replaceContributionTokens($$bodyType, $contribution, TRUE, $tokens);
          } catch (Exception $e) {
            // Do nothing
          }
        }

        $$bodyType = CRM_Utils_Token::replaceDomainTokens($$bodyType, $domain, TRUE, $tokens, TRUE);
        $$bodyType = CRM_Utils_Token::replaceContactTokens($$bodyType, $contact, FALSE, $tokens, FALSE, TRUE);
        $$bodyType = CRM_Utils_Token::replaceComponentTokens($$bodyType, $contact, $tokens, TRUE);
        if ($case_id) {
          $$bodyType = CRM_Utils_Token::replaceCaseTokens($case_id, $$bodyType, $tokens);
        }
        $$bodyType = CRM_Utils_Token::replaceHookTokens($$bodyType, $contact, $categories, TRUE);
        CRM_Utils_Token::replaceGreetingTokens($$bodyType, $contact, $contact['contact_id']);
      }
    }
    $html = $body_html;
    $text = $body_text;
    if (defined('CIVICRM_MAIL_SMARTY') && CIVICRM_MAIL_SMARTY) {
      $smarty = CRM_Core_Smarty::singleton();
      foreach ($type as $elem) {
        $$elem = $smarty->fetch("string:{$$elem}");
      }
    }

    // do replacements in message subject
    $messageSubject = CRM_Utils_Token::replaceContactTokens($messageSubject, $contact, false, $tokens);
    $messageSubject = CRM_Utils_Token::replaceDomainTokens($messageSubject, $domain, true, $tokens);
    $messageSubject = CRM_Utils_Token::replaceComponentTokens($messageSubject, $contact, $tokens, true);
    if ($case_id) {
      $messageSubject = CRM_Utils_Token::replaceCaseTokens($case_id, $messageSubject, $tokens);
    }
    $messageSubject = CRM_Utils_Token::replaceHookTokens($messageSubject, $contact, $categories, true);
    CRM_Utils_Token::replaceGreetingTokens($messageSubject, $contact, $contact['contact_id']);

    if (defined('CIVICRM_MAIL_SMARTY') && CIVICRM_MAIL_SMARTY) {
      $messageSubject = $smarty->fetch("string:{$messageSubject}");
    }

    // set up the parameters for CRM_Utils_Mail::send
    $mailParams = [
      'groupName' => 'E-mail from API',
      'from' => $from,
      'toName' => $toName,
      'toEmail' => $toEmail,
      'subject' => $messageSubject,
      'messageTemplateID' => $messageTemplates->id,
    ];

    if (!$html || $contact['preferred_mail_format'] == 'Text' || $contact['preferred_mail_format'] == 'Both') {
      // render the &amp; entities in text mode, so that the links work
      $mailParams['text'] = str_replace('&amp;', '&', $text);
    }
    if ($html && ($contact['preferred_mail_format'] == 'HTML' || $contact['preferred_mail_format'] == 'Both')) {
      $mailParams['html'] = $html;
    }
    if (isset($params['cc']) && !empty($params['cc'])) {
      $mailParams['cc'] = $params['cc'];
    }
    if (isset($params['bcc']) && !empty($params['bcc'])) {
      $mailParams['bcc'] = $params['bcc'];
    }
    $result = CRM_Utils_Mail::send($mailParams);
    if (!$result) {
      throw new API_Exception('Error sending e-mail to ' . $contact['display_name'] . ' <' . $toEmail . '> ');
    }

    //create activity for sending e-mail.
    $activityTypeID = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Email');

    // CRM-6265: save both text and HTML parts in details (if present)
    if ($html and $text) {
      $details = "-ALTERNATIVE ITEM 0-\n$html\n-ALTERNATIVE ITEM 1-\n$text\n-ALTERNATIVE END-\n";
    }
    else {
      $details = $html ? $html : $text;
    }

    $activityParams = [
      'source_contact_id' => $contactId,
      'activity_type_id' => $activityTypeID,
      'activity_date_time' => date('YmdHis'),
      'subject' => $messageSubject,
      'details' => $details,
      'status_id' => CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_status_id', 'Completed'),
    ];
    $activity = civicrm_api3('Activity', 'create', $activityParams);

    $activityContacts = CRM_Core_OptionGroup::values('activity_contacts', FALSE, FALSE, FALSE, NULL, 'name');
    $targetID = CRM_Utils_Array::key('Activity Targets', $activityContacts);

    $activityTargetParams = [
      'activity_id' => $activity['id'],
      'contact_id' => $contactId,
      'record_type_id' => $targetID
    ];
    CRM_Activity_BAO_ActivityContact::create($activityTargetParams);

    if (!empty($case_id)) {
      $caseActivity = [
        'activity_id' => $activity['id'],
        'case_id' => $case_id,
      ];
      CRM_Case_BAO_Case::processCaseActivity($caseActivity);
    }

    $returnValues[$contactId] = [
      'contact_id' => $contactId,
      'send' => 1,
      'status_msg' => "Successfully sent e-mail to {$toEmail}",
    ];
  }


  return civicrm_api3_create_success($returnValues, $params, 'Email', 'Send');
  //throw new API_Exception(/*errorMessage*/ 'Everyone knows that the magicword is "sesame"', /*errorCode*/ 1234);
}
