<?php
namespace Civi\Emailapi;

/**
 * Compiler Class for action provider
 */

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use CRM_Emailapi_ExtensionUtil as E;

class CompilerPass implements CompilerPassInterface {

  public function process(ContainerBuilder $container) {
    if ($container->hasDefinition('action_provider')) {
      $actionProviderDefinition = $container->getDefinition('action_provider');
      $actionProviderDefinition->addMethodCall('addAction',
        ['SendEmailByEmailApi', 'Civi\Emailapi\Actions\SendEmailByEmailApi', E::ts('Send Email using Email API'), []]);
    }
  }
}
