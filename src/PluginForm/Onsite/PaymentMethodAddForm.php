<?php

namespace Drupal\commerce_paycom\PluginForm\Onsite;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;

class PaymentMethodAddForm extends BasePaymentMethodAddForm {

  /**
   * {@inheritdoc}
   */
  protected function buildCreditCardForm(array $element, FormStateInterface $form_state) {
    // @TODO: Do we really need this?
    $element = parent::buildCreditCardForm($element, $form_state);
    return $element;
  }

}
