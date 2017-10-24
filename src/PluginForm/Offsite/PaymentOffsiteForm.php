<?php

namespace Drupal\commerce_paycom\PluginForm\Offsite;

use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_payment\Entity\PaymentMethodTypeInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class PaymentOffsiteForm extends PaymentMethodAddForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $redirect_url = Url::fromRoute('commerce_paycom.response_handler')->toString();

    $form['payment_details'] = [];
    $form['payment_details'] = $this->buildCreditCardForm($form['payment_details'], $form_state);
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Submit'),
    ];

    return $form;
  }

    /**
     * {@inheritdoc}
     */
    public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
      $this->validateCreditCardForm($form['payment_details'], $form_state);
    }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $this->entity->getPaymentMethod();
    if (!$payment_method) {
      $payment_method = PaymentMethod::create([
        'type' => 'credit_card',
        'payment_gateway' => $this->plugin->getPluginId(),
      ]);
      $payment_method->save();
    }
    $this->submitCreditCardForm($form['payment_details'], $form_state);

    $values = $form_state->getValue($form['#parents']);
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $this->plugin;
    // The payment method form is customer facing. For security reasons
    // the returned errors need to be more generic.
    try {
      
      $this->entity->payment_method = $payment_method;
      $payment_gateway_plugin->createPaymentMethod($payment_method, $values['payment_details']);
      $payment_gateway_plugin->createPayment($this->entity, $values['payment_details'], TRUE);
    }
    catch (DeclineException $e) {
      \Drupal::logger('commerce_payment')->warning($e->getMessage());
      throw new DeclineException('We encountered an error processing your payment method. Please verify your details and try again.');
    }
    catch (PaymentGatewayException $e) {
      \Drupal::logger('commerce_payment')->error($e->getMessage());
      throw new PaymentGatewayException('We encountered an unexpected error processing your payment method. Please try again later.');
    }
  }

  /**
   * Handles the submission of the credit card form.
   *
   * @param array $element
   *   The credit card form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the complete form.
   */
  protected function submitCreditCardForm(array $element, FormStateInterface $form_state) {
    $values = $form_state->getValue($element['#parents']);
    $this->entity->card_type = $values['type'];
    $this->entity->card_number = substr($values['number'], -4);
    $this->entity->card_exp_month = $values['expiration']['month'];
    $this->entity->card_exp_year = $values['expiration']['year'];
  }

}
