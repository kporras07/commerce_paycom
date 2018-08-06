<?php

namespace Drupal\commerce_paycom\Event;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Symfony\Component\EventDispatcher\Event;

/**
 * Commerce Paycom Payment Gateway Exception event.
 */
class CommercePaycomPaymenGatewayExceptionEvent extends Event {

  const PAYMENT_GATEWAY_EXCEPTION = 'commerce_paycom.payment_gateway_exception';

  /**
   * Exception thrown.
   *
   * @var \Drupal\commerce_payment\Exception\PaymentGatewayException
   */
  protected $exception;

  /**
   * Construct event object.
   *
   * @param \Drupal\commerce_payment\Exception\PaymentGatewayException $exception
   *   The exception that has been thrown.
   */
  public function __construct(PaymentGatewayException $exception) {
    $this->exception = $exception;
  }

  /**
   * Return the exception.
   */
  public function getException() {
    return $this->exception;
  }

}
