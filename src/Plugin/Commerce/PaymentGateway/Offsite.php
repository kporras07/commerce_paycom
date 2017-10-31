<?php

namespace Drupal\commerce_paycom\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\AuthenticationException;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paycom_offsite",
 *   label = "Paycom (Off-site)",
 *   display_label = "Paycom",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_paycom\PluginForm\Offsite\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 * )
 */
class Offsite extends OffsitePaymentGatewayBase {

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
   * Returns payment gateway entity id.
   */
  public function getEntityId() {
    return $this->entityId;
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
    $form['display_label']['#access'] = FALSE;

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
  public function createPayment(PaymentInterface $payment, array $payment_details, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    global $base_url;
    // Remember to take into account $capture when performing the request.
    $amount = $payment->getAmount();
    $remote_id = $payment_method->getRemoteId();
    $time = $this->time->getRequestTime();
    $parameters = [
      'username' => $this->getUsername(),
      'type' => 'auth',
      'key_id' => $this->getKeyId(),
      'hash' => $this->getHash([
        $payment->getOrderId(),
        $payment->getAmount()->getNumber(),
        $time,
        $this->getKey(),
      ]),
      'time' => $time,
      'ccnumber' => $payment_details['number'],
      'ccexp' => $payment_method->card_exp_month->value . $payment_method->card_exp_year->value,
      'amount' => $payment->getAmount()->getNumber(),
      'orderid' => $payment->getOrderId(),
      'cvv' => $payment_details['security_code'],
      'processor_id' => $this->getProcessorId(),
    ];
    $result = $this->doPost($parameters);
    $this->validateResponse($result);
    $next_state = 'authorization';
    $payment->setState($next_state);
    $payment->setRemoteId($result['transactionid']);
    $payment->save();
    if ($capture) {
      $this->capturePayment($payment, $payment->getAmount());
    }
  }

  /**
   * Validate response array formatted from Paycom.
   *
   * @param array $response
   *   The response array.
   *
   * @return bool
   *   Whether response is valid or not.
   */
  protected function validateResponse(array $response) {
    if (isset($response['response'])) {
      if ($response['response'] == 2) {
        throw new DeclineException($this->t('Denied transaction'));
      }
      elseif ($response['response'] == 3) {
        throw new AuthenticationException($this->t('Data error in the transaction or system error'));
      }
    }
    else {
      throw new InvalidResponseException($this->t('Response value not found'));
    }
    if (!empty($result['avsresponse'])) {
      throw new DeclineException($this->t('AVS response error. Code: @code', [
        '@code' => $response['avsresponse'],
      ]));
    }
    if (!empty($result['cvvresponse'])) {
      throw new DeclineException($this->t('CVV response error. Code: @code', [
        '@code' => $response['cvvresponse'],
      ]));
    }
    if (isset($response['response_code'])) {
      if ($response['response_code'] != 100) {
        throw new DeclineException($this->t('Denied transaction. Code: @code', [
          '@code' => $response['response_code'],
        ]));
      }
    }
    else {
      throw new InvalidResponseException($this->t('Response code value not found'));
    }

    $hash_elements = [
      $response['orderid'],
      $response['amount'],
      $response['response'],
      $response['transactionid'],
      $response['avsresponse'],
      $response['cvvresponse'],
      $response['time'],
      $this->getKey(),
    ];
    $hash = $this->getHash($hash_elements);
    if ($hash !== $response['hash']) {
      throw new InvalidResponseException($this->t('Hash can not be verified'));
    }

    return TRUE;
  }

  /**
   * Do POST Request.
   */
  protected function doPost($parameters) {
    $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
    $data = http_build_query($parameters);
    $response = $this->client->post($this->getUrl(), $headers, $data, 5);
    $contents = $response->getBody()->getContents();
    $contents = substr($contents, 1);
    $response_data = explode('&', $contents);
    $data = [];
    foreach ($response_data as $data_element) {
      $parts = explode('=', $data_element);
      $data[$parts[0]] = $parts[1];
    }
    return $data;
  }

  /**
   * Returns hash from provided values.
   */
  protected function getHash($values) {
    $string = implode('|', $values);
    return md5($string);
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);

    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    $remote_id = $payment->getRemoteId();
    $parameters = [
      'username' => $this->getUsername(),
      'type' => 'sale',
      'key_id' => $this->getKeyId(),
      'hash' => $this->getHash([
        $payment->getOrderId(),
        $amount->getNumber(),
        $this->time->getRequestTime(),
        $this->getKey(),
      ]),
      'time' => $this->time->getRequestTime(),
      'transactionid' => $remote_id,
      'amount' => $amount->getNumber(),
      'processor_id' => $this->getProcessorId(),
      'ccnumber' => $remote_id,
    ];
    $result = $this->doPost($parameters);
    $this->validateResponse($result);
    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);
    $remote_id = $payment->getRemoteId();
    $parameters = [
      'username' => $this->getUsername(),
      'type' => 'void',
      'key_id' => $this->getKeyId(),
      'hash' => $this->getHash([
        $payment->getOrderId(),
        $payment->getAmount()->getNumber(),
        $this->time->getRequestTime(),
        $this->getKey(),
      ]),
      'time' => $this->time->getRequestTime(),
      'transactionid' => $remote_id,
      'processor_id' => $this->getProcessorId(),
      'ccnumber' => $remote_id,
    ];
    $result = $this->doPost($parameters);
    $this->validateResponse($result);
    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, array $payment_details, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $payment_method = $payment->getPaymentMethod();

    $remote_id = $payment->getRemoteId();
    $number = $amount->getNumber();
    $parameters = [
      'username' => $this->getUsername(),
      'type' => 'refound',
      'key_id' => $this->getKeyId(),
      'hash' => $this->getHash([
        $payment->getOrderId(),
        $number,
        $this->time->getRequestTime(),
        $this->getKey(),
      ]),
      'time' => $this->time->getRequestTime(),
      'ccnumber' => $payment_details['number'],
      'ccexp' => $payment_method->card_exp_month->value . $payment_method->card_exp_year->value,
      'amount' => $number,
      'processor_id' => $this->getProcessorId(),
    ];
    $result = $this->doPost($parameters);
    $this->validateResponse($result);

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

    $payment_method->setReusable(FALSE);
    $payment_method->card_type = $payment_details['type'];
    // Only the last 4 numbers are safe to store.
    $payment_method->card_number = substr($payment_details['number'], -4);
    $payment_method->card_exp_month = $payment_details['expiration']['month'];
    $payment_method->card_exp_year = $payment_details['expiration']['year'];
    $expires = CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']);
    $remote_id = '-1';

    $payment_method->setRemoteId($remote_id);
    $payment_method->setExpiresTime($expires);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    $payment_method->delete();
  }

}
