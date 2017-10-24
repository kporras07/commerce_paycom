<?php

namespace Drupal\commerce_paycom\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the On-site payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paycom_onsite",
 *   label = "Paycom (On-site)",
 *   display_label = "Paycom",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_paycom\PluginForm\Onsite\PaymentMethodAddForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 * )
 */
class Onsite extends OnsitePaymentGatewayBase implements OnsiteInterface {

  /**
   * HTTP Client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * Url.
   *
   * @var string
   */
  protected $url;

  /**
   * Constructs a new Onsite object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The payment method type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \GuzzleHttp\ClientInterface $client
   *   The http client.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, ClientInterface $client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->client = $client;
    $this->url = 'https://paycom.credomatic.com/PayComBackEndWeb/common/requestPaycomService.go';
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'username' => '',
      'key' => '',
      'key_id' => '',
      'processor_id' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['mode']['#default_value'] = 'live';
    $form['mode']['#access'] = FALSE;

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['username'],
      '#required' => TRUE,
    ];

    $form['key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key'),
      '#default_value' => $this->configuration['key'],
      '#required' => TRUE,
    ];

    $form['key_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key ID'),
      '#default_value' => $this->configuration['key_id'],
      '#required' => TRUE,
    ];

    $form['processor_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Processor ID'),
      '#default_value' => $this->configuration['processor_id'],
      '#required' => FALSE,
    ];

    return $form;
  }

  /**
   * Returns Username.
   */
  protected function getUsername() {
    return $this->configuration['username'] ?: '';
  }

  /**
   * Returns Key.
   */
  protected function getKey() {
    return $this->configuration['key'] ?: '';
  }

  /**
   * Returns Key ID.
   */
  protected function getKeyId() {
    return $this->configuration['key_id'] ?: '';
  }

  /**
   * Returns Processor ID.
   */
  protected function getProcessorId() {
    return $this->configuration['processor_id'] ?: '';
  }

  /**
   * Returns url.
   */
  protected function getUrl() {
    return $this->url;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['username'] = $values['username'];
      $this->configuration['key'] = $values['key'];
      $this->configuration['key_id'] = $values['key_id'];
      $this->configuration['processor_id'] = $values['processor_id'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    try {
      global $base_url;
      // Perform the create payment request here, throw an exception if it fails.
      // See \Drupal\commerce_payment\Exception for the available exceptions.
      // Remember to take into account $capture when performing the request.
      $amount = $payment->getAmount();
      $remote_id = $payment_method->getRemoteId();

      $parameters = [
        'username' => $this->getUsername(),
        'type' => 'auth',
        'key_id' => $this->getKeyId(),
        'hash' => $this->getEntryHash($payment->getOrderId(), $payment->getAmount()->getNumber(), $this->time->getRequestTime()),
        'time' => $this->time->getRequestTime(),
        'redirect' => $base_url . '/commerce_paycom/commerce_paycom_response',
        'ccnumber' => $payment_method->card_number,
        'ccexp' => $payment_method->card_exp_month->value . $payment_method->card_exp_year->value,
        'amount' => $payment->getAmount()->getNumber(),
        'orderid' => $payment->getOrderId(),
        // @TODO: Is this allowed?
        'cvv' => $payment_method->security_code,
        'processor_id' => $this->getProcessorId(),
      ];
      dpm($parameters, 'PARAMS');
      $result = $this->doPost($parameters);
      dpm($result->getBody()->getContents(), 'RESBODY');
      dpm($result->getHeaders(), 'RESHEAD');
      dpm($result->getStatusCode(), 'RESCODE');
      dpm($result->getReasonPhrase(), 'RESPhrase');
      // @TODO: Evaluate result.
      $next_state = $capture ? 'completed' : 'authorization';
      $payment->setState($next_state);
      $payment->setRemoteId($remote_id);
      $payment->save();
      if ($capture) {
        $this->capturePayment($payment);
      }
    }
    catch (\Exception $e) {
    }
  }

  /**
   * Do POST Request.
   */
  protected function doPost($parameters) {
    $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
    $data = http_build_query($parameters);
    return $this->client->post($this->getUrl(), $headers, $data, 5);
  }

  /**
   * Returns entry hash from provided values.
   */
  protected function getEntryHash($order_id, $amount, $time) {
    $string = $order_id . '|' . $amount . '|' . $time . '|' . $this->getKey();
    return md5($string);
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    // @TODO: Implement.
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    // Perform the capture request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    $remote_id = $payment->getRemoteId();
    $number = $amount->getNumber();

    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    // @TODO: Implement.
    $this->assertPaymentState($payment, ['authorization']);
    // Perform the void request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    $remote_id = $payment->getRemoteId();

    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    // @TODO: Implement.
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    // Perform the refund request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    $remote_id = $payment->getRemoteId();
    $number = $amount->getNumber();

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    // @TODO: Implement.
    // The expected keys are payment gateway specific and usually match
    // the PaymentMethodAddForm form elements. They are expected to be valid.
    $required_keys = [
      'type',
      'number',
      'expiration',
      'security_code',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    // If the remote API needs a remote customer to be created.
    $owner = $payment_method->getOwner();
    if ($owner && $owner->isAuthenticated()) {
      $customer_id = $this->getRemoteCustomerId($owner);
      // @TODO: Check this!
      // If $customer_id is empty, create the customer remotely and then do
      // $this->setRemoteCustomerId($owner, $customer_id);
      // $owner->save();
    }

    $payment_method->card_type = $payment_details['type'];
    // Only the last 4 numbers are safe to store.
    $payment_method->card_number = substr($payment_details['number'], -4);
    // @TODO: Is this allowed?
    $payment_method->security_code = $payment_details['security_code'];
    $payment_method->card_exp_month = $payment_details['expiration']['month'];
    $payment_method->card_exp_year = $payment_details['expiration']['year'];
    $expires = CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']);
    // @TODO: WHAT???
    // The remote ID returned by the request.
    $remote_id = '789';

    $payment_method->setRemoteId($remote_id);
    $payment_method->setExpiresTime($expires);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the remote record here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    // Delete the local entity.
    $payment_method->delete();
  }

}
