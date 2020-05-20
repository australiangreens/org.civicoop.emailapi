<?php
namespace Civi\Emailapi\Actions;

use \Civi\ActionProvider\Action\AbstractAction;
use \Civi\ActionProvider\Exception\InvalidParameterException;
use \Civi\ActionProvider\Parameter\ParameterBagInterface;
use \Civi\ActionProvider\Parameter\SpecificationBag;
use \Civi\ActionProvider\Parameter\Specification;

use Civi\FormProcessor\API\Exception;
use CRM_Emailapi_ExtensionUtil as E;

/**
 * Class SendEmailByEmailApi - send email action for action-provider using the Email api
 *
 * @package Civi\Emailapi\Action
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 20 May 2020
 * @license AGPL-3.0
 */
class SendEmailByEmailApi extends AbstractAction {

  /**
   * @return SpecificationBag
   */
  public function getParameterSpecification() {
    return new SpecificationBag([
      new Specification('contact_id', 'Integer', E::ts('Contact ID'), TRUE)
    ]);
  }

  /**
   * @return SpecificationBag|void
   */
  public function getConfigurationSpecification() {
    return new SpecificationBag([
      new Specification("template_id", "Integer", E::ts("Template to use for the Email"), TRUE, NULL, NULL, $this->getTemplates()),
      new Specification("cc", "String", E::ts("Send copy to email address (cc) "), FALSE),
      new Specification("bcc", "String", E::ts("Send blind copy to email address (bcc) "), FALSE),
      new Specification("subject", "String", E::ts("Email Subject"), FALSE),
      new Specification("alternate_receiver_address", "String", E::ts("Send to THIS Email Address (and not the primary one of the contact)"), FALSE),
      new Specification("case_id", "Integer", E::ts("File Email Activity on Case with ID"), FALSE),
      new Specification("contribution_id", "Integer", E::ts("Use Contribution with this ID for the contribution tokens in the Email"), FALSE),
    ]);
  }

  /**
   * @return SpecificationBag
   */
  public function getOutputSpecification() {
    return new SpecificationBag([
      new Specification("contact_id", "Integer", E::ts("Contact ID"), TRUE),
      new Specification("send", "Boolean", E::ts("Did Email Get Sent?"), FALSE),
      new Specification("status_msg", "String", E::ts("Status Message"), FALSE),
    ]);
  }

  /**
   * @param ParameterBagInterface $parameters
   * @param ParameterBagInterface $output
   * @throws InvalidParameterException
   */
  public function doAction(ParameterBagInterface $parameters, ParameterBagInterface $output) {
    $contactId = (int) $parameters->getParameter('contact_id');
    if ($contactId) {
      try {
        $result = civicrm_api3('Email', 'send', $this->collectEmailParams($contactId));
        $outputFields = ['contact_id', 'send', 'status_msg'];
        foreach ($outputFields as $outputField) {
          if ($result[$outputField]) {
            $output->setParameter($outputField, $result[$outputField]);
          }
        }
      }
      catch (\CiviCRM_API3_Exception $ex) {
        Civi::log()->error(E::ts('Could not send email in ') . __METHOD__
          . E::ts(', error from API Email Send: ') . $ex->getMessage());
      }
    }
    else {
      throw new InvalidParameterException(E::ts("Could not find mandatory parameter contact_id"));
    }
  }

  /**
   * Method to collect the parameters for the email
   *
   * @param int $contactId
   * @return array
   */
  private function collectEmailParams($contactId) {
    $emailParams = [
      'contact_id' => $contactId,
      'template_id' => (int) $this->configuration->getParameter('template_id'),
    ];
    $parameters = ['cc', 'bcc', 'alternate_receiver_address', 'subject', 'case_id', 'contribution_id'];
    foreach ($parameters as $parameter) {
      $value = $this->configuration->getParameter($parameter);
      if ($value && !empty($value)) {
        $emailParams[$parameter] = $value;
      }
    }
    return $emailParams;
  }

  /**
   * Method to get the list of active message templates
   */
  private function getTemplates() {
    $templates = [];
    try {
      $result = civicrm_api3('MessageTemplate', 'get', [
        'return' => ["msg_title"],
        'is_active' => 1,
        'options' => ['limit' => 0],
        'workflow_id' => ['IS NULL' => 1],
      ]);
      foreach ($result['values'] as $msgTemplateId => $msgTemplate) {
        $templates[$msgTemplateId] = $msgTemplate['msg_title'];
      }
    }
    catch (\CiviCRM_API3_Exception $ex) {
    }
    return $templates;
  }
}
