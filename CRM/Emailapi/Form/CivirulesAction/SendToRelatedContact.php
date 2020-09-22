<?php

use CRM_Emailapi_ExtensionUtil as E;
/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Emailapi_Form_CivirulesAction_SendToRelatedContact extends CRM_Core_Form {

  protected $ruleActionId = false;

  protected $ruleAction;

  protected $rule;

  protected $action;

  protected $triggerClass;

  protected $hasCase = false;

  /**
   * Overridden parent method to do pre-form building processing
   *
   * @throws Exception when action or rule action not found
   * @access public
   */
  public function preProcess() {
    $this->ruleActionId = CRM_Utils_Request::retrieve('rule_action_id', 'Integer');
    $this->ruleAction = new CRM_Civirules_BAO_RuleAction();
    $this->action = new CRM_Civirules_BAO_Action();
    $this->rule = new CRM_Civirules_BAO_Rule();
    $this->ruleAction->id = $this->ruleActionId;
    if ($this->ruleAction->find(TRUE)) {
      $this->action->id = $this->ruleAction->action_id;
      if (!$this->action->find(TRUE)) {
        throw new Exception('CiviRules Could not find action with id '.$this->ruleAction->action_id);
      }
    } else {
      throw new Exception('CiviRules Could not find rule action with id '.$this->ruleActionId);
    }

    $this->rule->id = $this->ruleAction->rule_id;
    if (!$this->rule->find(TRUE)) {
      throw new Exception('Civirules could not find rule');
    }

    $this->triggerClass = CRM_Civirules_BAO_Trigger::getTriggerObjectByTriggerId($this->rule->trigger_id, TRUE);
    $this->triggerClass->setTriggerId($this->rule->trigger_id);
    $providedEntities = $this->triggerClass->getProvidedEntities();
    if (isset($providedEntities['Case'])) {
      $this->hasCase = TRUE;
    }

    parent::preProcess();
  }

  protected function getRelationshipTypes() {
    return CRM_Emailapi_CivirulesAction_SendToRelatedContact::getRelationshipTypes();
  }

  protected function getRelatedOptions() {
    return CRM_Emailapi_CivirulesAction_SendToRelatedContact::getRelationshipOptions();
  }

  /**
   * Method to get location types
   *
   * @return array
   * @access protected
   */

  protected function getLocationTypes() {
    $return = ['' => E::ts('-- please select --')];
    try {
      $locationTypes = civicrm_api3('LocationType', 'get', [
        'return' => ["id", "display_name"],
        'is_active' => 1,
        'options' => ['limit' => 0, 'sort' => "display_name"],
      ]);
      foreach ($locationTypes['values'] as $locationTypeId => $locationType) {
        $return[$locationTypeId] = $locationType['display_name'];
      }
    }
    catch (CiviCRM_API3_Exception $ex) {
    }
    return $return;
  }

  function buildQuickForm() {

    $this->setFormTitle();
    $this->registerRule('emailList', 'callback', 'emailList', 'CRM_Utils_Rule');
    $this->add('hidden', 'rule_action_id');
    $this->add('text', 'from_name', E::ts('From Name'), TRUE);
    $this->add('text', 'from_email', E::ts('From Email'), TRUE);
    $this->addRule("from_email", E::ts('Email is not valid.'), 'email');
    $this->add('select', 'relationship_type', E::ts('Relationship Type'), $this->getRelationshipTypes(), TRUE);
    $this->add('select', 'relationship_option', E::ts('Send e-mail to'), $this->getRelatedOptions(), TRUE);
    $this->add('text', 'cc', E::ts('Cc to'));
    $this->addRule("cc", E::ts('Email is not valid.'), 'emailList');
    $this->add('text', 'bcc', E::ts('Bcc to'));
    $this->addRule("bcc", E::ts('Email is not valid.'), 'emailList');
    $this->addEntityRef('template_id', E::ts('Message Template'),[
      'entity' => 'MessageTemplate',
      'api' => [
        'label_field' => 'msg_title',
        'search_field' => 'msg_title',
        'params' => [
          'is_active' => 1,
          'workflow_id' => ['IS NULL' => 1],
        ]
      ],
      'placeholder' => E::ts(' - select - '),
    ], TRUE);
    $this->add('select', 'location_type_id', E::ts('Location Type (if you do not want primary e-mail address)'), $this->getLocationTypes(), FALSE);
    if ($this->hasCase) {
      $this->add('checkbox','file_on_case', E::ts('File Email on Case'));
    }
    $this->assign('has_case', $this->hasCase);
    // add buttons
    $this->addButtons([
      ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE,],
      ['type' => 'cancel', 'name' => E::ts('Cancel')]
    ]);
  }

  /**
   * Overridden parent method to set default values
   *
   * @return array $defaultValues
   * @access public
   */
  public function setDefaultValues() {
    $data = [];
    $defaultValues = [];
    $defaultValues['rule_action_id'] = $this->ruleActionId;
    if (!empty($this->ruleAction->action_params)) {
      $data = unserialize($this->ruleAction->action_params);
    }
    if (!empty($data['from_name'])) {
      $defaultValues['from_name'] = $data['from_name'];
    }
    if (!empty($data['from_email'])) {
      $defaultValues['from_email'] = $data['from_email'];
    }
    if (!empty($data['relationship_type'])) {
      $defaultValues['relationship_type'] = $data['relationship_type'];
    }
    if (!empty($data['relationship_option'])) {
      $defaultValues['relationship_option'] = $data['relationship_option'];
    }
    if (!empty($data['template_id'])) {
      $defaultValues['template_id'] = $data['template_id'];
    }
    if (!empty($data['location_type_id'])) {
      $defaultValues['location_type_id'] = $data['location_type_id'];
    }
    if (!empty($data['cc'])) {
      $defaultValues['cc'] = $data['cc'];
    }
    if (!empty($data['bcc'])) {
      $defaultValues['bcc'] = $data['bcc'];
    }
    $defaultValues['file_on_case'] = FALSE;
    if (!empty($data['file_on_case'])) {
      $defaultValues['file_on_case'] = TRUE;
    }
    return $defaultValues;
  }

  /**
   * Overridden parent method to process form data after submitting
   *
   * @access public
   */
  public function postProcess() {
    $data['from_name'] = $this->_submitValues['from_name'];
    $data['from_email'] = $this->_submitValues['from_email'];
    $data['relationship_type'] = $this->_submitValues['relationship_type'];
    $data['relationship_option'] = $this->_submitValues['relationship_option'];
    $data['template_id'] = $this->_submitValues['template_id'];
    $data['location_type_id'] = $this->_submitValues['location_type_id'];
    $data['cc'] = '';
    if (!empty($this->_submitValues['cc'])) {
      $data['cc'] = $this->_submitValues['cc'];
    }
    $data['bcc'] = '';
    if (!empty($this->_submitValues['bcc'])) {
      $data['bcc'] = $this->_submitValues['bcc'];
    }
    $data['file_on_case'] = FALSE;
    if (!empty($this->_submitValues['file_on_case'])) {
      $data['file_on_case'] = TRUE;
    }

    $ruleAction = new CRM_Civirules_BAO_RuleAction();
    $ruleAction->id = $this->ruleActionId;
    $ruleAction->action_params = serialize($data);
    $ruleAction->save();

    $session = CRM_Core_Session::singleton();
    $session->setStatus('Action '.$this->action->label.' parameters updated to CiviRule '.CRM_Civirules_BAO_Rule::getRuleLabelWithId($this->ruleAction->rule_id),
      'Action parameters updated', 'success');

    $redirectUrl = CRM_Utils_System::url('civicrm/civirule/form/rule', 'action=update&id='.$this->ruleAction->rule_id, TRUE);
    CRM_Utils_System::redirect($redirectUrl);
  }

  /**
   * Method to set the form title
   *
   * @access protected
   */
  protected function setFormTitle() {
    $title = 'CiviRules Edit Action parameters';
    $this->assign('ruleActionHeader', 'Edit action '.$this->action->label.' of CiviRule '.CRM_Civirules_BAO_Rule::getRuleLabelWithId($this->ruleAction->rule_id));
    CRM_Utils_System::setTitle($title);
  }
}
