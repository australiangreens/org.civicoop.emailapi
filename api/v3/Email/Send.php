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
  $spec['activity_id'] = [
    'title' => 'Activity ID',
    'type' => CRM_Utils_Type::T_INT,
  ];
  $spec['contribution_id'] = [
    'title' => 'Contribution ID',
    'type' => CRM_Utils_Type::T_INT,
  ];
  $spec['activity_id'] = [
    'title' => 'Activity ID',
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
 *
 * @return array API result descriptor
 * @throws \API_Exception
 * @throws \CRM_Core_Exception
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_email_send($params) {
  // @todo contact_id accepts multiple but other params do not. So eg. each contact gets the same activity.
  //   That may be what we want but it may not!
  //   We could add a "context" param that takes an array of params instead (note that we can only support one of each entity currently).
  //   [['contact_id' => 1, 'activity_id' => 123, ..], ['contact_id' => 2, 'activity_id' => 456]]
  // @todo Perhaps we could use TokenProcessor if passed the context param and the "old" method otherwise?
  if (!CRM_Utils_Type::validate($params['contact_id'], 'CommaSeparatedIntegers')) {
    throw new API_Exception('Parameter contact_id must be a unique id or a list of ids separated by comma');
  }
  $params['contact_id'] = explode(',', $params['contact_id']);
  $alternativeEmailAddress = !empty($params['alternative_receiver_address']) ? $params['alternative_receiver_address'] : FALSE;

  $messageTemplates = new CRM_Core_DAO_MessageTemplate();
  $messageTemplates->id = $params['template_id'];

  // From header defaults to site default.
  list($defaultFromName, $defaultFromEmail) = CRM_Core_BAO_Domain::getNameAndEmail();
  $from = "\"$defaultFromName\" <$defaultFromEmail>";

  if (!empty($params['from_email']) && !empty($params['from_name'])) {
    // If both an email and a name are provided, use those as the from header.
    $from = '"' . $params['from_name'] . '" <' . $params['from_email'] . '>';
  } elseif (!empty($params['from_email']) || !empty($params['from_name'])) {
    // Why do we insist on this, instead of using the site default where the data is missing?
    throw new API_Exception('You have to provide both from_name and from_email');
  }

  if (!$messageTemplates->find(TRUE)) {
    throw new API_Exception('Could not find template with ID: '.$params['template_id']);
  }

  $tokenProc = _civicrm_api3_email_send_createTokenProcessor($params, $messageTemplates);
  $tokenProc->evaluate();

  $returnValues = [];
  foreach ($tokenProc->getRows() as $tokenRow) {
    /** @var \Civi\Token\TokenRow $tokenRow */
    $contactId = $tokenRow->context[_civicrm_api3_email_send_getEntityFieldsMap()['contact_id']];
    $messageSubject = $tokenRow->render('subject');
    $html = $tokenRow->render('body_html');
    $text = $tokenRow->render('body_text');
    $contact = civicrm_api3('Contact', 'getsingle', ['id' => $contactId]);

    if ($alternativeEmailAddress) {
      /*
       * If an alternative recipient address is given
       * then send e-mail to that address rather than to
       * the e-mail address of the contact
       */
      $toName = '';
      $toEmail = $alternativeEmailAddress;
    }
    elseif ($contact['do_not_email'] || empty($contact['email']) || CRM_Utils_Array::value('is_deceased', $contact) || $contact['on_hold']) {
      /*
       * Contact is deceased or has opted out from mailings so do not send the e-mail
       */
      continue;
    }
    else {
      $toName = $contact['display_name'];
      $toEmail = $contact['email'];
    }

    // set up the parameters for CRM_Utils_Mail::send
    $mailParams = [
      'groupName' => 'E-mail from API',
      'from' => $from,
      'toName' => $toName,
      'toEmail' => $toEmail,
      'subject' => $messageSubject,
      'messageTemplateID' => $messageTemplates->id,
      'contactId' => $contactId,
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
}

/**
 * The field names in $params and in the token context don't exactly match;
 * Until 5.26: https://github.com/civicrm/civicrm-core/pull/17161 and https://github.com/civicrm/civicrm-core/pull/17254
 *
 * @return array
 */
function _civicrm_api3_email_send_getEntityFieldsMap() {
  return [
    // string $api_param_name => string $tokenContextName
    'activity_id' => 'activityId',
    'contact_id' => 'contactId',
    'contribution_id' => 'contributionId',
    'case_id' => 'caseId',
  ];
}

/**
 * Create an instance of the TokenProcessor. Populate it with
 * - Message templates (from $messageTemplate).
 * - Basic contextual data about each planned message (eg contact ID from $params).
 *
 * @param array $params
 * @param CRM_Core_DAO_MessageTemplate $messageTemplate
 * @return \Civi\Token\TokenProcessor
 */
function _civicrm_api3_email_send_createTokenProcessor($params, $messageTemplate) {
  // TODO: In discussion between aydun+totten, we wanted add a general item called 'schema'
  //   so that we could foreshadow data available in each row. I'm not sure
  //   this has been finished/merged yet. But this code assumes it's working.
  // TODO: CRM_Case_Tokens should consume case_id
  // TODO: CRM_Contribute_Tokens should consume contribution_id; like old call to replaceContributionTokens (https://github.com/civicrm/civicrm-core/pull/16612)
  // TODO: Email.send previously called replaceComponentTokens(). Determine if that's something we care about.

  $availableEntityFields = _civicrm_api3_email_send_getEntityFieldsMap();

  $activeEntityFields = [];
  foreach ($availableEntityFields as $paramName => $contextName) {
    if (isset($params[$paramName])) {
      $activeEntityFields[$paramName] = $contextName;
    }
  }

  // Prepare the processor and general context.
  $tokenProc = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), [
    // Unique(ish) identifier for our controller/use-case.
    'controller' => 'civicrm_api3_email_send',

    // Provide hints about what data will be available for each row.
    // Ex: 'schema' => ['contactId', 'activityId', 'caseId'],
    'schema' => array_values($activeEntityFields),

    // Whether to enable Smarty evaluation.
    'smarty' => (defined('CIVICRM_MAIL_SMARTY') && CIVICRM_MAIL_SMARTY),
  ]);

  // @todo do we need to call replaceComponentTokens when using tokenProcessor?
  //   It won't work unless tokens are passed in via $contact array like ['member.id' => 12]
  // $messageTemplate->msg_subject = CRM_Utils_Token::replaceComponentTokens($messageTemplate->msg_subject, $contact, $tokens, true);
  // $messageTemplate->msg_html = CRM_Utils_Token::replaceComponentTokens($messageTemplate->msg_html, $contact, $tokens, true);
  // $messageTemplate->msg_text = CRM_Utils_Token::replaceComponentTokens($messageTemplate->msg_text, $contact, $tokens, true);
  if (!empty($params['contribution_id'])) {
    // @fixme: Contributions don't (yet) support tokenProcessor: see https://github.com/civicrm/civicrm-core/pull/16612
    // get tokens to be replaced
    $tokens = array_merge_recursive(
      CRM_Utils_Token::getTokens($messageTemplate->msg_text),
      CRM_Utils_Token::getTokens($messageTemplate->msg_html),
      CRM_Utils_Token::getTokens($messageTemplate->msg_subject)
    );
    try {
      $contribution = civicrm_api3('Contribution', 'getsingle', ['id' => $params['contribution_id']]);
      $messageTemplate->msg_subject = CRM_Utils_Token::replaceContributionTokens($messageTemplate->msg_subject, $contribution, TRUE, $tokens);
      $messageTemplate->msg_html = CRM_Utils_Token::replaceContributionTokens($messageTemplate->msg_html, $contribution, TRUE, $tokens);
      $messageTemplate->msg_text = CRM_Utils_Token::replaceContributionTokens($messageTemplate->msg_text, $contribution, TRUE, $tokens);
    } catch (Exception $e) {
      // Do nothing
    }
  }

  // Define message templates.
  $tokenProc->addMessage('subject', $messageTemplate->msg_subject, 'text/plain');
  $tokenProc->addMessage('body_html', $messageTemplate->msg_html, 'text/html');
  $tokenProc->addMessage('body_text',
    $messageTemplate->msg_text ? $messageTemplate->msg_text : CRM_Utils_String::htmlToText($messageTemplate->msg_html),
    'text/plain');

  // Define row data.
  for ($i=0;$i<count($params['contact_id']);$i++) {
    $context = [];
    foreach ($activeEntityFields as $paramName => $contextName) {
      $context[$contextName] = is_array($params[$paramName]) ? $params[$paramName][$i] : $params[$paramName] ?? NULL;
    }
    $tokenProc->addRow()->context($context);
  }

  return $tokenProc;
}
