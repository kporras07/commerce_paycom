<?php

namespace Drupal\commerce_paycom\PluginForm;

use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * PaymentOffsiteForm class.
 */
class PaycomForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $amount = $payment->getAmount();
    $amount_number = number_format(round($amount->getNumber(), 2), 2, '.', '');

    $form['ccnumber'] = [
      '#type' => 'textfield',
      '#title' => t('Credit Card Number'),
      '#name' => 'ccnumber',
    ];
    $form['ccexp'] = [
      '#type' => 'textfield',
      '#title' => t('Credit Card Expiration'),
      '#name' => 'ccexp',
    ];
    $form['cvv'] = [
      '#type' => 'textfield',
      '#title' => t('Credit Card CVV'),
      '#name' => 'cvv',
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Submit'),
    ];
    $form['time'] = [
      '#type' => 'hidden',
      '#value' => REQUEST_TIME,
      '#name' => 'time',
    ];
    $form['username'] = [
      '#type' => 'hidden',
      '#value' => $payment_gateway_plugin->getUsername(),
      '#name' => 'username',
    ];
    $form['type'] = [
      '#type' => 'hidden',
      '#value' => 'auth',
      '#name' => 'type',
    ];
    $form['key_id'] = [
      '#type' => 'hidden',
      '#value' => $payment_gateway_plugin->getKeyId(),
      '#name' => 'key_id',
    ];
    $form['hash'] = [
      '#type' => 'hidden',
      '#value' => $payment_gateway_plugin->getHash([
        $payment->getOrderId(),
        $amount_number,
        REQUEST_TIME,
        $payment_gateway_plugin->getKey(),
      ]),
      '#name' => 'hash',
    ];
    $form['redirect'] = [
      '#type' => 'hidden',
      '#value' => $form['#return_url'],
      '#name' => 'redirect',
    ];
    $form['amount'] = [
      '#type' => 'hidden',
      '#value' => $amount_number,
      '#name' => 'amount',
    ];
    $form['orderid'] = [
      '#type' => 'hidden',
      '#value' => $payment->getOrderId(),
      '#name' => 'orderid',
    ];
    $form['processor_id'] = [
      '#type' => 'hidden',
      '#value' => $payment_gateway_plugin->getProcessorId(),
      '#name' => 'processor_id',
    ];

    $form['url'] = [
      '#type' => 'value',
      '#value' => $payment_gateway_plugin->getUrl(),
    ];

    $form['ccnumber']['#process'] = [[get_class($this), 'processForm']];

    return $form;
  }

  public static function processForm(array $element, FormStateInterface $form_state, &$complete_form) {
    $complete_form['#action'] = $complete_form['payment_process']['offsite_payment']['url']['#value'];
    return $element;
  }

}
